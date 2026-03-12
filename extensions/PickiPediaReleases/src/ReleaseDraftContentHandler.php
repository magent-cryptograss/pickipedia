<?php
/**
 * Content handler for ReleaseDraft pages.
 *
 * Renders type-specific interactive forms:
 * - Album drafts: track list with reorder, per-track metadata, album fields
 * - All types: blockheight field, status display, finalize action
 *
 * The actual files live on delivery-kid's storage. This page holds
 * the metadata and serves as the collaborative editing surface.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\TextContentHandler;
use MediaWiki\Content\ValidationParams;
use MediaWiki\Html\Html;
use MediaWiki\Parser\ParserOutput;
use StatusValue;
use Symfony\Component\Yaml\Yaml;

class ReleaseDraftContentHandler extends TextContentHandler {

	public function __construct( $modelId = 'release-draft-yaml' ) {
		parent::__construct( $modelId, [ CONTENT_FORMAT_TEXT ] );
	}

	public function serializeContent( Content $content, $format = null ): string {
		if ( !$content instanceof ReleaseDraftContent ) {
			throw new \InvalidArgumentException( 'Expected ReleaseDraftContent' );
		}
		return $content->getText();
	}

	public function unserializeContent( $text, $format = null ): ReleaseDraftContent {
		return new ReleaseDraftContent( $text );
	}

	public function makeEmptyContent(): ReleaseDraftContent {
		$yaml = Yaml::dump( [
			'draft_id' => '',
			'type' => 'album',
			'status' => 'draft',
			'blockheight' => null,
			'album' => [
				'title' => '',
				'artist' => '',
				'version' => '',
				'description' => '',
			],
			'tracks' => [],
			'result' => [
				'cid' => null,
				'gateway_url' => null,
			],
		], 4, 2 );
		return new ReleaseDraftContent( $yaml );
	}

	public function validateSave(
		Content $content,
		ValidationParams $validationParams
	): StatusValue {
		if ( !$content instanceof ReleaseDraftContent ) {
			return StatusValue::newFatal( 'invalid-content-data' );
		}
		return $content->validate();
	}

	protected function fillParserOutput(
		Content $content,
		ContentParseParams $cpoParams,
		ParserOutput &$output
	): void {
		if ( !$content instanceof ReleaseDraftContent ) {
			$output->setRawText( '<p>Invalid content type</p>' );
			return;
		}

		$output->addModuleStyles( [ 'ext.pickipediaReleases.styles', 'ext.pickipediaReleases.upload.styles' ] );
		$output->addModules( [ 'ext.pickipediaReleases.releaseDraft' ] );

		$html = '';
		$data = $content->getData();
		$status = $content->getStatus();
		$type = $content->getDraftType();

		// Validation errors
		$validation = $content->validate();
		if ( !$validation->isOK() ) {
			$html .= $this->renderValidationErrors( $validation );
		}

		// Status banner
		$html .= $this->renderStatusBanner( $status, $data );

		// If complete, show the result
		if ( $status === 'complete' ) {
			$html .= $this->renderResult( $data );
		}

		// Type-specific form
		if ( $type === 'album' ) {
			$html .= $this->renderAlbumForm( $data, $status );
		} else {
			$html .= $this->renderGenericForm( $data, $status );
		}

		// Blockheight field (all types)
		$html .= $this->renderBlockheightField( $data );

		// Action buttons
		if ( $status === 'draft' ) {
			$html .= $this->renderActions( $data );
		}

		// Raw YAML
		$yamlText = trim( $content->getText() );
		if ( !empty( $yamlText ) ) {
			$html .= $this->renderRawYaml( $yamlText );
		}

		// Pass data to JS
		$output->setJsConfigVar( 'wgReleaseDraftData', $data );

		$output->setRawText( $html );

		// Categories
		$output->addCategory( 'Release_Drafts' );
		if ( $status === 'complete' ) {
			$output->addCategory( 'Completed_Drafts' );
		}
	}

	private function renderStatusBanner( string $status, array $data ): string {
		$classes = [
			'draft' => 'rd-status-draft',
			'finalizing' => 'rd-status-finalizing',
			'complete' => 'rd-status-complete',
		];
		$labels = [
			'draft' => 'Draft — not yet finalized',
			'finalizing' => 'Finalizing — processing in progress',
			'complete' => 'Complete — pinned to IPFS',
		];

		$class = $classes[$status] ?? 'rd-status-draft';
		$label = $labels[$status] ?? 'Unknown status';

		$type = $data['type'] ?? 'release';

		return Html::rawElement( 'div', [ 'class' => "rd-status-banner $class" ],
			Html::element( 'span', [ 'class' => 'rd-status-label' ], $label ) .
			Html::element( 'span', [ 'class' => 'rd-status-type' ], ucfirst( $type ) . ' draft' )
		);
	}

	private function renderResult( array $data ): string {
		$result = $data['result'] ?? [];
		if ( empty( $result['cid'] ) ) {
			return '';
		}

		$cid = $result['cid'];
		$gatewayUrl = $result['gateway_url'] ?? "https://ipfs.io/ipfs/{$cid}";
		$releaseTitle = \MediaWiki\MediaWikiServices::getInstance()
			->getTitleFactory()->makeTitle( NS_RELEASE, $cid );

		$html = Html::openElement( 'div', [ 'class' => 'uc-result-card' ] );
		$html .= Html::element( 'h4', [], 'Pinned to IPFS' );
		$html .= Html::openElement( 'table', [ 'class' => 'wikitable' ] );

		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [], 'CID' );
		$html .= Html::element( 'td', [ 'class' => 'release-cid-cell' ], $cid );
		$html .= Html::closeElement( 'tr' );

		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [], 'Gateway' );
		$html .= Html::rawElement( 'td', [],
			Html::element( 'a', [ 'href' => $gatewayUrl, 'target' => '_blank' ], $gatewayUrl )
		);
		$html .= Html::closeElement( 'tr' );

		if ( $releaseTitle ) {
			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'th', [], 'Release Page' );
			$html .= Html::rawElement( 'td', [],
				Html::element( 'a', [ 'href' => $releaseTitle->getLocalURL() ], $releaseTitle->getPrefixedText() )
			);
			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'table' );
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	private function renderAlbumForm( array $data, string $status ): string {
		$album = $data['album'] ?? [];
		$tracks = $data['tracks'] ?? [];
		$disabled = $status !== 'draft' ? ' disabled' : '';

		$html = Html::openElement( 'div', [ 'class' => 'rd-album-form', 'id' => 'rd-album-form' ] );
		$html .= Html::element( 'h3', [], 'Album Info' );

		$html .= Html::openElement( 'div', [ 'class' => 'uc-metadata-form' ] );

		// Album title
		$html .= $this->renderField( 'rd-album-title', 'Album Title',
			$album['title'] ?? '', 'text', $disabled );

		// Artist
		$html .= $this->renderField( 'rd-artist', 'Artist',
			$album['artist'] ?? '', 'text', $disabled );

		// Version
		$html .= $this->renderField( 'rd-version', 'Version',
			$album['version'] ?? '', 'text', $disabled );

		// Description
		$html .= Html::openElement( 'div', [ 'class' => 'uc-field' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-description' ], 'Description' );
		$html .= Html::element( 'textarea', [
			'id' => 'rd-description',
			'class' => 'cdx-text-input__input',
			'rows' => 3,
			'placeholder' => 'Optional album description',
			'disabled' => $status !== 'draft' ? true : false,
		], $album['description'] ?? '' );
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'div' );

		// Track list
		$html .= Html::element( 'h3', [], 'Tracks' );
		if ( $status === 'draft' ) {
			$html .= Html::element( 'p', [ 'class' => 'uc-hint' ], 'Drag to reorder. Metadata field accepts key=value pairs (one per line) for Vorbis comments.' );
		}

		$html .= Html::openElement( 'div', [ 'id' => 'rd-track-list', 'class' => 'rd-track-list' ] );

		foreach ( $tracks as $idx => $track ) {
			$html .= $this->renderTrackRow( $idx, $track, $status );
		}

		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	private function renderTrackRow( int $idx, array $track, string $status ): string {
		$disabled = $status !== 'draft';
		$num = $idx + 1;

		$html = Html::openElement( 'div', [
			'class' => 'rd-track-row',
			'draggable' => $disabled ? 'false' : 'true',
			'data-idx' => $idx,
			'data-filename' => $track['filename'] ?? '',
		] );

		if ( !$disabled ) {
			$html .= Html::element( 'span', [
				'class' => 'ua-track-handle',
				'title' => 'Drag to reorder',
			], "\u{2630}" );
		}

		$html .= Html::element( 'span', [ 'class' => 'rd-track-num' ], (string)$num );

		// Track info column
		$html .= Html::openElement( 'div', [ 'class' => 'rd-track-info' ] );

		// Title input
		$html .= Html::element( 'input', [
			'type' => 'text',
			'class' => 'rd-track-title cdx-text-input__input',
			'value' => $track['title'] ?? '',
			'data-filename' => $track['filename'] ?? '',
			'disabled' => $disabled ? true : false,
		] );

		// File info
		$meta = [];
		if ( !empty( $track['format'] ) ) {
			$meta[] = htmlspecialchars( $track['format'] );
		}
		if ( !empty( $track['duration'] ) ) {
			$mins = floor( $track['duration'] / 60 );
			$secs = floor( $track['duration'] % 60 );
			$meta[] = $mins . ':' . str_pad( (string)$secs, 2, '0', STR_PAD_LEFT );
		}
		if ( !empty( $track['size_bytes'] ) ) {
			$meta[] = $this->formatSize( (int)$track['size_bytes'] );
		}
		if ( !empty( $meta ) ) {
			$html .= Html::rawElement( 'span', [ 'class' => 'rd-track-meta' ],
				implode( ' &middot; ', $meta )
			);
		}

		// Per-track metadata (freetext)
		$html .= Html::element( 'textarea', [
			'class' => 'rd-track-metadata',
			'rows' => 2,
			'placeholder' => 'COMPOSER=...' . "\n" . 'PERFORMER=...',
			'disabled' => $disabled ? true : false,
		], $track['metadata'] ?? '' );

		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	private function renderGenericForm( array $data, string $status ): string {
		// Placeholder for non-album draft types
		$html = Html::openElement( 'div', [ 'class' => 'rd-generic-form' ] );
		$html .= Html::element( 'h3', [], 'Content Draft' );
		$html .= Html::element( 'p', [], 'Draft type: ' . ( $data['type'] ?? 'unknown' ) );
		$html .= Html::closeElement( 'div' );
		return $html;
	}

	private function renderBlockheightField( array $data ): string {
		$blockheight = $data['blockheight'] ?? '';

		$html = Html::openElement( 'div', [ 'class' => 'rd-blockheight-section' ] );
		$html .= Html::element( 'h3', [], 'Temporal Reference' );

		$html .= Html::openElement( 'div', [ 'class' => 'uc-field' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-blockheight' ], 'Ethereum Block Height' );

		$html .= Html::openElement( 'div', [ 'class' => 'rd-blockheight-row' ] );
		$html .= Html::element( 'input', [
			'type' => 'text',
			'id' => 'rd-blockheight',
			'class' => 'cdx-text-input__input rd-blockheight-input',
			'value' => $blockheight,
			'placeholder' => 'e.g. 24631327',
		] );
		$html .= Html::element( 'button', [
			'type' => 'button',
			'id' => 'rd-blockheight-now',
			'class' => 'cdx-button',
		], 'Current Block' );
		$html .= Html::element( 'span', [ 'id' => 'rd-blockheight-date', 'class' => 'rd-blockheight-date' ], '' );
		$html .= Html::closeElement( 'div' );

		$html .= Html::openElement( 'div', [ 'class' => 'rd-date-converter' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-date-input' ], 'Or pick a date:' );
		$html .= Html::element( 'input', [
			'type' => 'date',
			'id' => 'rd-date-input',
			'class' => 'cdx-text-input__input rd-date-input',
		] );
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	private function renderActions( array $data ): string {
		$draftId = $data['draft_id'] ?? '';

		$html = Html::openElement( 'div', [ 'class' => 'rd-actions', 'id' => 'rd-actions' ] );

		$html .= Html::element( 'button', [
			'type' => 'button',
			'id' => 'rd-save-btn',
			'class' => 'cdx-button cdx-button--action-progressive',
		], 'Save Draft' );

		$html .= Html::element( 'button', [
			'type' => 'button',
			'id' => 'rd-finalize-btn',
			'class' => 'cdx-button cdx-button--action-progressive cdx-button--weight-primary',
		], 'Finalize & Pin to IPFS' );

		$html .= Html::openElement( 'div', [
			'id' => 'rd-finalize-progress',
			'class' => 'rd-finalize-progress',
			'style' => 'display:none',
		] );

		// Stage indicators
		$html .= Html::openElement( 'div', [ 'class' => 'rd-stages', 'id' => 'rd-stages' ] );
		$stages = [ 'preparing' => 'Preparing', 'transcoding' => 'Transcoding', 'tagging' => 'Tagging', 'pinning' => 'Pinning', 'torrenting' => 'Torrenting', 'complete' => 'Complete' ];
		foreach ( $stages as $key => $label ) {
			$html .= Html::element( 'span', [
				'class' => 'rd-stage',
				'data-stage' => $key,
			], $label );
		}
		$html .= Html::closeElement( 'div' );

		// Progress bar
		$html .= Html::rawElement( 'div', [
			'id' => 'rd-progress-bar',
			'class' => 'uc-progress-bar',
		], Html::element( 'div', [ 'class' => 'uc-progress-fill' ] ) );

		// Current activity log
		$html .= Html::element( 'div', [ 'id' => 'rd-progress-status', 'class' => 'rd-progress-status' ] );
		$html .= Html::openElement( 'div', [ 'id' => 'rd-progress-log', 'class' => 'rd-progress-log' ] );
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'div' );

		return $html;
	}

	private function renderField( string $id, string $label, string $value, string $type, string $disabled ): string {
		$html = Html::openElement( 'div', [ 'class' => 'uc-field' ] );
		$html .= Html::element( 'label', [ 'for' => $id ], $label );
		$attrs = [
			'type' => $type,
			'id' => $id,
			'class' => 'cdx-text-input__input',
			'value' => $value,
		];
		if ( $disabled ) {
			$attrs['disabled'] = true;
		}
		$html .= Html::element( 'input', $attrs );
		$html .= Html::closeElement( 'div' );
		return $html;
	}

	private function formatSize( int $bytes ): string {
		$units = [ 'B', 'KB', 'MB', 'GB' ];
		$size = (float)$bytes;
		$i = 0;
		while ( $size >= 1024 && $i < count( $units ) - 1 ) {
			$size /= 1024;
			$i++;
		}
		return round( $size, 1 ) . ' ' . $units[$i];
	}

	private function renderValidationErrors( StatusValue $status ): string {
		$errors = $status->getMessages( 'error' );
		$errorHtml = Html::element( 'strong', [], 'Validation Errors:' );
		$errorList = Html::openElement( 'ul' );
		foreach ( $errors as $error ) {
			$errorList .= Html::element( 'li', [], wfMessage( $error )->text() );
		}
		$errorList .= Html::closeElement( 'ul' );
		return Html::rawElement( 'div', [ 'class' => 'release-validation-error' ],
			$errorHtml . $errorList
		);
	}

	private function renderRawYaml( string $yaml ): string {
		return Html::rawElement( 'details', [ 'class' => 'release-raw-yaml' ],
			Html::element( 'summary', [], 'Raw YAML' ) .
			Html::element( 'pre', [], $yaml )
		);
	}

	public function supportsDirectEditing(): bool {
		return true;
	}

	public function supportsDirectApiEditing(): bool {
		return true;
	}

	public function getActionOverrides(): array {
		return [];
	}
}
