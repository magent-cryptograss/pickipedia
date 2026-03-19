<?php
/**
 * Special page for delivering other content via delivery-kid.
 *
 * Wiki login is the auth layer. PHP generates a short-lived HMAC token.
 * JavaScript uploads files directly to delivery-kid — no bytes pass through PHP.
 *
 * Flow:
 * 1. User logs in to wiki
 * 2. PHP generates HMAC upload token from shared API key
 * 3. JS uploads directly to delivery-kid with the token
 * 4. JS creates a ReleaseDraft wiki page and redirects there
 * 5. Review, metadata, and finalization happen on the ReleaseDraft page
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialDeliverOtherContent extends SpecialPage {

	public function __construct() {
		parent::__construct( 'DeliverOtherContent' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->requireNamedUser();
		$out = $this->getOutput();
		$user = $this->getUser();

		$out->addModuleStyles( [ 'ext.pickipediaReleases.upload.styles' ] );
		$out->addModules( [ 'ext.pickipediaReleases.deliverOtherContent' ] );

		// Generate HMAC upload token
		$apiKey = $this->getConfig()->get( 'DeliveryKidApiKey' );
		$apiUrl = $this->getConfig()->get( 'DeliveryKidUrl' );
		$username = $user->getName();
		$timestamp = (int)( microtime( true ) * 1000 );
		$token = hash_hmac( 'sha256', "upload:{$username}:{$timestamp}", $apiKey );

		// Estimate current Ethereum block from wall-clock time
		// (post-merge: 12s slots from the merge block)
		$mergeBlock = 15537394;
		$mergeTimestamp = 1663224179;
		$slotTime = 12;
		$uploadBlockheight = $mergeBlock + intdiv( time() - $mergeTimestamp, $slotTime );

		// Pass config to JS — token is short-lived, not a persistent secret
		$out->addJsConfigVars( [
			'wgDeliveryKidUrl' => $apiUrl,
			'wgUploadToken' => $token,
			'wgUploadUser' => $username,
			'wgUploadTimestamp' => $timestamp,
			'wgUploadBlockheight' => $uploadBlockheight,
		] );

		// Editable intro text
		$this->addWikitextMessage( 'special-deliverothercontent-header' );

		$out->addHTML( $this->renderPageStructure() );
	}

	/**
	 * Render the HTML skeleton — just a dropzone.
	 * After upload, JS creates a ReleaseDraft page and redirects.
	 */
	private function renderPageStructure(): string {
		$html = '';

		$html .= '<div id="uc-step-upload" class="uc-step uc-step-active">';
		$html .= '<h3>Upload Files</h3>';
		$html .= '<p>Select files to upload. After analysis, a draft page will be created for review and metadata editing.</p>';
		$html .= '<p class="uc-hint">Video files will be transcoded to AV1 HLS (royalty-free) automatically on finalization.</p>';
		$html .= '<div id="uc-dropzone" class="uc-dropzone">';
		$html .= '<p>Drag files here or click to select</p>';
		$html .= '<p class="uc-hint">Audio, Video, Images &mdash; no size limit</p>';
		$html .= '<input type="file" id="uc-file-input" multiple style="display:none">';
		$html .= '</div>';
		$html .= '<div id="uc-file-list" class="uc-file-list"></div>';
		$html .= '<button id="uc-upload-btn" class="cdx-button cdx-button--action-progressive cdx-button--weight-primary" disabled>Upload &amp; Analyze</button>';
		$html .= '<div id="uc-upload-progress" class="uc-progress-bar" style="display:none"><div class="uc-progress-fill"></div></div>';
		$html .= '<div id="uc-upload-status" class="uc-status"></div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Parse and output a MediaWiki message as wikitext, if it exists and is non-empty.
	 */
	private function addWikitextMessage( string $msgKey ): void {
		$msg = $this->msg( $msgKey );
		if ( !$msg->isDisabled() ) {
			$this->getOutput()->addWikiTextAsInterface( $msg->plain() );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'media';
	}
}
