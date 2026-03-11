<?php
/**
 * Special page for uploading albums directly to delivery-kid.
 *
 * Wiki login is the auth layer. PHP generates a short-lived HMAC token.
 * JavaScript uploads files directly to delivery-kid — no bytes pass through PHP.
 *
 * Uses the /draft-album multi-step workflow:
 * 1. Upload audio files + cover art → delivery-kid analyzes them
 * 2. Review metadata, reorder tracks, edit titles
 * 3. Finalize → transcode, tag, pin to IPFS
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialUploadAlbum extends SpecialPage {

	public function __construct() {
		parent::__construct( 'UploadAlbum', 'upload-to-delivery-kid' );
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
		$out->addModules( [ 'ext.pickipediaReleases.uploadAlbum' ] );

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
		$this->addWikitextMessage( 'special-uploadalbum-header' );

		$out->addHTML( $this->renderPageStructure() );
	}

	/**
	 * Render the HTML skeleton. JS handles upload, then creates a ReleaseDraft page.
	 */
	private function renderPageStructure(): string {
		$html = '';

		$html .= '<div id="ua-step-upload" class="uc-step uc-step-active">';
		$html .= '<h3>Upload Album Files</h3>';
		$html .= '<p>Upload audio tracks and optional cover art. Supported formats: FLAC, WAV, OGG, MP3, M4A.</p>';
		$html .= '<p>After upload, a draft page will be created where you can edit metadata, reorder tracks, and finalize.</p>';
		$html .= '<div id="ua-dropzone" class="uc-dropzone">';
		$html .= '<p>Drag audio files here or click to select</p>';
		$html .= '<p class="uc-hint">FLAC &amp; WAV will be archived and transcoded to OGG</p>';
		$html .= '<input type="file" id="ua-file-input" multiple accept=".flac,.wav,.ogg,.mp3,.m4a,.jpg,.jpeg,.png,.webp" style="display:none">';
		$html .= '</div>';
		$html .= '<div id="ua-file-list" class="uc-file-list"></div>';
		$html .= '<button id="ua-upload-btn" class="cdx-button cdx-button--action-progressive cdx-button--weight-primary" disabled>Upload &amp; Analyze</button>';
		$html .= '<div id="ua-upload-progress" class="uc-progress-bar" style="display:none"><div class="uc-progress-fill"></div></div>';
		$html .= '<div id="ua-upload-status" class="uc-status"></div>';
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
