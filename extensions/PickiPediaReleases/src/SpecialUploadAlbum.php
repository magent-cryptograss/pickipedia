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
	 * Render the HTML skeleton. JS handles all interactivity.
	 */
	private function renderPageStructure(): string {
		$html = '';

		// Step 1: File upload
		$html .= '<div id="ua-step-upload" class="uc-step uc-step-active">';
		$html .= '<h3>Upload Album Files</h3>';
		$html .= '<p>Upload audio tracks and optional cover art. Supported formats: FLAC, WAV, OGG, MP3, M4A.</p>';
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

		// Step 2: Review tracks and metadata
		$html .= '<div id="ua-step-review" class="uc-step">';
		$html .= '<h3>Review &amp; Organize</h3>';
		$html .= '<div id="ua-draft-info" class="uc-draft-info"></div>';

		// Album metadata
		$html .= '<div class="uc-metadata-form">';
		$html .= '<div class="uc-field"><label for="ua-album-title">Album Title</label>';
		$html .= '<input type="text" id="ua-album-title" class="cdx-text-input__input" placeholder="Album title" required></div>';
		$html .= '<div class="uc-field"><label for="ua-artist">Artist</label>';
		$html .= '<input type="text" id="ua-artist" class="cdx-text-input__input" placeholder="Artist name" required></div>';
		$html .= '<div class="uc-field"><label for="ua-year">Year</label>';
		$html .= '<input type="text" id="ua-year" class="cdx-text-input__input" placeholder="e.g. 2026"></div>';
		$html .= '<div class="uc-field"><label for="ua-description">Description</label>';
		$html .= '<textarea id="ua-description" class="cdx-text-input__input" rows="3" placeholder="Optional album description"></textarea></div>';
		$html .= '</div>';

		// Track list (populated by JS)
		$html .= '<h4>Track List <span class="uc-hint">(drag to reorder)</span></h4>';
		$html .= '<div id="ua-track-list" class="ua-track-list"></div>';

		$html .= '<div class="uc-button-row">';
		$html .= '<button id="ua-finalize-btn" class="cdx-button cdx-button--action-progressive cdx-button--weight-primary">Finalize &amp; Pin</button>';
		$html .= '<button id="ua-delete-draft-btn" class="cdx-button cdx-button--action-destructive">Delete Draft</button>';
		$html .= '</div>';
		$html .= '</div>';

		// Step 3: Progress and result
		$html .= '<div id="ua-step-progress" class="uc-step">';
		$html .= '<h3>Processing Album</h3>';
		$html .= '<div id="ua-progress-bar" class="uc-progress-bar"><div class="uc-progress-fill"></div></div>';
		$html .= '<div id="ua-progress-status" class="uc-status"></div>';
		$html .= '<div id="ua-result" class="uc-result"></div>';
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
