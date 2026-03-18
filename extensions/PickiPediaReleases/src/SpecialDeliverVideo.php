<?php
/**
 * Special page for delivering video content via delivery-kid.
 *
 * Video-specific upload page with metadata fields tailored for
 * performance video: venue, performers, date/blockheight.
 *
 * Wiki login is the auth layer. PHP generates a short-lived HMAC token.
 * JavaScript uploads files directly to delivery-kid — no bytes pass through PHP.
 *
 * Flow:
 * 1. User logs in to wiki
 * 2. PHP generates HMAC upload token from shared API key
 * 3. JS uploads video directly to delivery-kid with the token
 * 4. JS creates a ReleaseDraft wiki page (type: video) and redirects there
 * 5. Review, metadata, and finalization happen on the ReleaseDraft page
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialDeliverVideo extends SpecialPage {

	public function __construct() {
		parent::__construct( 'DeliverVideo' );
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
		$out->addModules( [ 'ext.pickipediaReleases.deliverVideo' ] );

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
		$this->addWikitextMessage( 'special-delivervideo-header' );

		$out->addHTML( $this->renderPageStructure() );
	}

	/**
	 * Render the HTML skeleton — video dropzone + metadata fields.
	 * After upload, JS creates a ReleaseDraft page and redirects.
	 */
	private function renderPageStructure(): string {
		$html = '';

		$html .= '<div id="dv-step-upload" class="uc-step uc-step-active">';

		// Two-column layout: upload on left, optional details on right
		$html .= '<div class="dv-columns">';

		// -- Left column: dropzone, title, upload --
		$html .= '<div class="dv-col-main">';
		$html .= '<h3>Upload Video</h3>';

		$html .= '<div id="dv-dropzone" class="uc-dropzone">';
		$html .= '<p>Drag video file here or click to select</p>';
		$html .= '<p class="uc-hint">MP4, MKV, WebM, MOV, AVI &mdash; no size limit</p>';
		$html .= '<input type="file" id="dv-file-input" accept=".mp4,.mkv,.webm,.mov,.avi,.m4v,.ts" style="display:none">';
		$html .= '</div>';
		$html .= '<div id="dv-file-list" class="uc-file-list"></div>';

		// Title — required
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-title' ], 'Title (required)' );
		$html .= Html::element( 'input', [
			'type' => 'text',
			'id' => 'dv-title',
			'class' => 'cdx-text-input__input',
			'placeholder' => 'e.g. Flatpicking at Station Inn',
			'required' => true,
		] );
		$html .= '</div>';

		$html .= '<button id="dv-upload-btn" class="cdx-button cdx-button--action-progressive cdx-button--weight-primary" disabled>Upload</button>';
		$html .= '<div id="dv-upload-progress" class="uc-progress-bar" style="display:none"><div class="uc-progress-fill"></div></div>';
		$html .= '<div id="dv-upload-status" class="uc-status"></div>';
		$html .= '<p class="uc-hint">Video will be transcoded to AV1 HLS on finalization.</p>';

		$html .= '</div>';

		// -- Right column: optional details --
		$html .= '<div class="dv-col-details">';
		$html .= '<h4>Optional Details</h4>';
		$html .= '<p class="uc-hint">Can be added later on the draft page.</p>';

		// Venue
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-venue' ], 'Venue' );
		$html .= Html::element( 'input', [
			'type' => 'text',
			'id' => 'dv-venue',
			'class' => 'cdx-text-input__input',
			'placeholder' => 'e.g. Station Inn',
		] );
		$html .= '</div>';

		// Performers
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-performers' ], 'Performers' );
		$html .= Html::element( 'input', [
			'type' => 'text',
			'id' => 'dv-performers',
			'class' => 'cdx-text-input__input',
			'placeholder' => 'Comma-separated',
		] );
		$html .= '</div>';

		// Content blockheight
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-content-blockheight' ], 'When recorded (block height)' );
		$html .= '<div class="rd-blockheight-row">';
		$html .= Html::element( 'input', [
			'type' => 'number',
			'id' => 'dv-content-blockheight',
			'class' => 'cdx-text-input__input rd-blockheight-input',
			'placeholder' => 'e.g. 24631327',
		] );
		$html .= Html::element( 'button', [
			'type' => 'button',
			'id' => 'dv-blockheight-now',
			'class' => 'cdx-button',
		], 'Now' );
		$html .= Html::element( 'span', [ 'id' => 'dv-blockheight-date', 'class' => 'rd-blockheight-date' ], '' );
		$html .= '</div>';
		$html .= '<div class="rd-date-converter">';
		$html .= Html::element( 'label', [ 'for' => 'dv-date-input' ], 'Or pick a date:' );
		$html .= Html::element( 'input', [
			'type' => 'date',
			'id' => 'dv-date-input',
			'class' => 'cdx-text-input__input rd-date-input',
		] );
		$html .= '</div>';
		$html .= '</div>';

		// Description
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-description' ], 'Description' );
		$html .= Html::element( 'textarea', [
			'id' => 'dv-description',
			'class' => 'cdx-text-input__input',
			'rows' => 3,
			'placeholder' => 'Optional',
		], '' );
		$html .= '</div>';

		$html .= '</div>'; // dv-col-details
		$html .= '</div>'; // dv-columns

		$html .= '</div>'; // dv-step-upload

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
