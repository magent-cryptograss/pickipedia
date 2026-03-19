<?php
/**
 * Special page for delivering Blue Railroad Train exercise videos.
 *
 * Same two-column layout as DeliverVideo, but with Blue Railroad-specific
 * mandatory fields: exercise type, block height. Creates a ReleaseDraft
 * with type: blue-railroad.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialDeliverBlueRailroad extends SpecialPage {

	private const EXERCISES = [
		'Blue Railroad Train (Squats)',
		'Nine Pound Hammer (Pushups)',
		'Ginseng Sullivan (Army Crawls)',
	];

	public function __construct() {
		parent::__construct( 'DeliverBlueRailroad' );
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
		$out->addModules( [ 'ext.pickipediaReleases.deliverBlueRailroad' ] );

		// Generate HMAC upload token
		$apiKey = $this->getConfig()->get( 'DeliveryKidApiKey' );
		$apiUrl = $this->getConfig()->get( 'DeliveryKidUrl' );
		$username = $user->getName();
		$timestamp = (int)( microtime( true ) * 1000 );
		$token = hash_hmac( 'sha256', "upload:{$username}:{$timestamp}", $apiKey );

		// Estimate current Ethereum block from wall-clock time
		$mergeBlock = 15537394;
		$mergeTimestamp = 1663224179;
		$slotTime = 12;
		$uploadBlockheight = $mergeBlock + intdiv( time() - $mergeTimestamp, $slotTime );

		$out->addJsConfigVars( [
			'wgDeliveryKidUrl' => $apiUrl,
			'wgUploadToken' => $token,
			'wgUploadUser' => $username,
			'wgUploadTimestamp' => $timestamp,
			'wgUploadBlockheight' => $uploadBlockheight,
		] );

		$this->addWikitextMessage( 'special-deliverbluerailroad-header' );

		$out->addHTML( $this->renderPageStructure() );
	}

	private function renderPageStructure(): string {
		$html = '';

		$html .= '<div id="dv-step-upload" class="uc-step uc-step-active">';
		$html .= '<div class="dv-columns">';

		// -- Left column: dropzone, mandatory fields, upload --
		$html .= '<div class="dv-col-main">';
		$html .= '<h3>Blue Railroad Train</h3>';

		$html .= '<div id="dv-dropzone" class="uc-dropzone">';
		$html .= '<p>Drag exercise video here or click to select</p>';
		$html .= '<p class="uc-hint">MP4, MKV, WebM, MOV, AVI &mdash; no size limit</p>';
		$html .= '<input type="file" id="dv-file-input" accept=".mp4,.mkv,.webm,.mov,.avi,.m4v,.ts" style="display:none">';
		$html .= '</div>';
		$html .= '<div id="dv-file-list" class="uc-file-list"></div>';

		// Exercise — mandatory dropdown
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-exercise' ], 'Exercise (required)' );
		$html .= Html::openElement( 'select', [
			'id' => 'dv-exercise',
			'class' => 'cdx-text-input__input',
			'required' => true,
		] );
		$html .= Html::element( 'option', [ 'value' => '' ], '— Select exercise —' );
		foreach ( self::EXERCISES as $exercise ) {
			$html .= Html::element( 'option', [ 'value' => $exercise ], $exercise );
		}
		$html .= Html::closeElement( 'select' );
		$html .= '</div>';

		// Block height — mandatory
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-content-blockheight' ], 'Block height (required)' );
		$html .= '<div class="rd-blockheight-row">';
		$html .= Html::element( 'input', [
			'type' => 'number',
			'id' => 'dv-content-blockheight',
			'class' => 'cdx-text-input__input rd-blockheight-input',
			'placeholder' => 'e.g. 24631327',
			'required' => true,
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

		// Recorder
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-recorder' ], 'Recorder' );
		$html .= Html::element( 'input', [
			'type' => 'text',
			'id' => 'dv-recorder',
			'class' => 'cdx-text-input__input',
			'placeholder' => 'Who filmed it?',
		] );
		$html .= '</div>';

		// Participants (wallet addresses)
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-participants' ], 'Participants' );
		$html .= Html::element( 'textarea', [
			'id' => 'dv-participants',
			'class' => 'cdx-text-input__input',
			'rows' => 3,
			'placeholder' => "One per line:\n0x... or name.eth",
		], '' );
		$html .= '</div>';

		// Notes
		$html .= '<div class="uc-field">';
		$html .= Html::element( 'label', [ 'for' => 'dv-notes' ], 'Notes' );
		$html .= Html::element( 'textarea', [
			'id' => 'dv-notes',
			'class' => 'cdx-text-input__input',
			'rows' => 2,
			'placeholder' => 'Optional',
		], '' );
		$html .= '</div>';

		$html .= '</div>'; // dv-col-details
		$html .= '</div>'; // dv-columns

		$html .= '</div>'; // dv-step-upload

		return $html;
	}

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
