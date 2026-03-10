<?php
/**
 * Special page for uploading content directly to delivery-kid.
 *
 * Wiki login is the auth layer. PHP generates a short-lived HMAC token.
 * JavaScript uploads files directly to delivery-kid — no bytes pass through PHP.
 *
 * Flow:
 * 1. User logs in to wiki (must have 'upload-to-delivery-kid' right)
 * 2. PHP generates HMAC upload token from shared API key
 * 3. JS uploads directly to delivery-kid with the token
 * 4. JS handles the review/finalize steps
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialUploadContent extends SpecialPage {

	public function __construct() {
		parent::__construct( 'UploadContent', 'upload-to-delivery-kid' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->checkPermissions();
		$out = $this->getOutput();
		$user = $this->getUser();

		$out->addModuleStyles( [ 'ext.pickipediaReleases.upload.styles' ] );
		$out->addModules( [ 'ext.pickipediaReleases.upload' ] );

		// Generate HMAC upload token
		$apiKey = $this->getConfig()->get( 'DeliveryKidApiKey' );
		$apiUrl = $this->getConfig()->get( 'DeliveryKidUrl' );
		$username = $user->getName();
		$timestamp = (int)( microtime( true ) * 1000 );
		$token = hash_hmac( 'sha256', "upload:{$username}:{$timestamp}", $apiKey );

		// Pass config to JS — token is short-lived, not a persistent secret
		$out->addJsConfigVars( [
			'wgDeliveryKidUrl' => $apiUrl,
			'wgUploadToken' => $token,
			'wgUploadUser' => $username,
			'wgUploadTimestamp' => $timestamp,
		] );

		// Editable intro text
		$this->addWikitextMessage( 'special-uploadcontent-header' );

		$out->addHTML( $this->renderPageStructure() );
	}

	/**
	 * Render the HTML skeleton. JS handles all interactivity.
	 */
	private function renderPageStructure(): string {
		$html = '';

		// Step 1: File upload
		$html .= '<div id="uc-step-upload" class="uc-step uc-step-active">';
		$html .= '<h3>Upload Files</h3>';
		$html .= '<p>Select files to upload for review before pinning to IPFS.</p>';
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

		// Step 2: Review and metadata
		$html .= '<div id="uc-step-review" class="uc-step">';
		$html .= '<h3>Review &amp; Metadata</h3>';
		$html .= '<div id="uc-draft-info" class="uc-draft-info"></div>';
		$html .= '<div class="uc-metadata-form">';
		$html .= '<div class="uc-field"><label for="uc-title">Title</label>';
		$html .= '<input type="text" id="uc-title" class="cdx-text-input__input" placeholder="Content title"></div>';
		$html .= '<div class="uc-field"><label for="uc-description">Description</label>';
		$html .= '<textarea id="uc-description" class="cdx-text-input__input" rows="3" placeholder="Optional description"></textarea></div>';
		$html .= '<div class="uc-field"><label for="uc-file-type">File type override</label>';
		$html .= '<input type="text" id="uc-file-type" class="cdx-text-input__input" placeholder="e.g., video/webm (leave blank for auto)"></div>';
		$html .= '<div class="uc-field"><label for="uc-subsequent-to">Subsequent to (CID)</label>';
		$html .= '<input type="text" id="uc-subsequent-to" class="cdx-text-input__input" placeholder="CID this content supersedes"></div>';
		$html .= '<div class="uc-field uc-checkbox-field" id="uc-hls-field" style="display:none">';
		$html .= '<label><input type="checkbox" id="uc-transcode-hls"> Transcode video to HLS before pinning</label></div>';
		$html .= '</div>';
		$html .= '<div class="uc-button-row">';
		$html .= '<button id="uc-finalize-btn" class="cdx-button cdx-button--action-progressive cdx-button--weight-primary">Finalize &amp; Pin</button>';
		$html .= '<button id="uc-delete-draft-btn" class="cdx-button cdx-button--action-destructive">Delete Draft</button>';
		$html .= '</div>';
		$html .= '</div>';

		// Step 3: Progress and result
		$html .= '<div id="uc-step-progress" class="uc-step">';
		$html .= '<h3>Pinning</h3>';
		$html .= '<div id="uc-progress-bar" class="uc-progress-bar"><div class="uc-progress-fill"></div></div>';
		$html .= '<div id="uc-progress-status" class="uc-status"></div>';
		$html .= '<div id="uc-result" class="uc-result"></div>';
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
