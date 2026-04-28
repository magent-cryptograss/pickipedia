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
use MediaWiki\SpecialPage\SpecialPage;
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
			'type' => 'record',
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
		$type = $content->getDraftType();

		// Status comes from the YAML — set to 'finalized' by the JS once
		// the SSE 'complete' event fires during finalize. Defaults to
		// 'draft' for any draft that hasn't been through finalize.
		$status = $data['status'] ?? 'draft';

		// Validation errors
		$validation = $content->validate();
		if ( !$validation->isOK() ) {
			$html .= $this->renderValidationErrors( $validation );
		}

		// Abandoned banner (shown above the normal status)
		if ( $content->isAbandoned() ) {
			$html .= $this->renderAbandonedBanner( $content->getAbandonedReason() );
		}

		// Status banner
		$html .= $this->renderStatusBanner( $status, $data );

		// Type-specific form
		if ( $type === 'record' || $type === 'album' ) {
			$html .= $this->renderAlbumForm( $data, $status );
		} elseif ( $type === 'video' ) {
			$html .= $this->renderVideoForm( $data, $status );
		} else {
			$html .= $this->renderGenericForm( $data, $status );
		}

		// Blockheight field (all types)
		$html .= $this->renderBlockheightField( $data );

		// Action buttons
		$html .= $this->renderActions( $data );

		// Provenance info (commit, source, uploader)
		$html .= $this->renderProvenance( $data );

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
	}

	private function renderAbandonedBanner( ?string $reason ): string {
		$text = 'This draft has been abandoned.';
		if ( $reason !== null ) {
			$text .= ' Reason: ' . $reason;
		}
		return Html::rawElement( 'div', [
			'class' => 'release-draft-abandoned-banner',
			'style' => 'background:#f8d7da; border:2px solid #a94442; padding:1em; '
				. 'margin-bottom:1em; border-radius:4px; color:#58151c;',
		], Html::element( 'strong', [], $text ) );
	}

	private function renderStatusBanner( string $status, array $data ): string {
		$classes = [
			'draft' => 'rd-status-draft',
			'finalizing' => 'rd-status-finalizing',
			'finalized' => 'rd-status-complete',
			'complete' => 'rd-status-complete',
		];
		$labels = [
			'draft' => 'Draft — not yet finalized',
			'finalizing' => 'Finalizing — processing in progress',
			'finalized' => 'Finalized — pinned to IPFS',
			'complete' => 'Complete — pinned to IPFS',
		];

		$class = $classes[$status] ?? 'rd-status-draft';
		$label = $labels[$status] ?? 'Unknown status';

		$type = $data['type'] ?? 'release';

		$body = Html::element( 'span', [ 'class' => 'rd-status-label' ], $label ) .
			Html::element( 'span', [ 'class' => 'rd-status-type' ], ucfirst( $type ) . ' draft' );

		if ( !empty( $data['final_cid'] ) ) {
			$cid = $data['final_cid'];
			$body .= ' — ' . Html::rawElement( 'a',
				[ 'href' => '/wiki/Release:' . rawurlencode( $cid ) ],
				'Release:' . htmlspecialchars( $cid )
			);
		}

		return Html::rawElement( 'div', [ 'class' => "rd-status-banner $class" ], $body );
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
		$content = $data['content'] ?? [];
		$files = $data['files'] ?? [];
		$disabled = $status !== 'draft' ? ' disabled' : '';

		$html = Html::openElement( 'div', [ 'class' => 'rd-content-form', 'id' => 'rd-content-form' ] );
		$html .= Html::element( 'h3', [], 'Content Info' );

		$html .= Html::openElement( 'div', [ 'class' => 'uc-metadata-form' ] );

		// Title
		$html .= $this->renderField( 'rd-content-title', 'Title',
			$content['title'] ?? '', 'text', $disabled );

		// Description
		$html .= Html::openElement( 'div', [ 'class' => 'uc-field' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-content-description' ], 'Description' );
		$html .= Html::element( 'textarea', [
			'id' => 'rd-content-description',
			'class' => 'cdx-text-input__input',
			'rows' => 3,
			'placeholder' => 'Optional description',
			'disabled' => $status !== 'draft' ? true : false,
		], $content['description'] ?? '' );
		$html .= Html::closeElement( 'div' );

		// File type override
		$html .= $this->renderField( 'rd-content-file-type', 'File type override',
			$content['file_type'] ?? '', 'text', $disabled );

		// Subsequent to
		$html .= $this->renderField( 'rd-content-subsequent-to', 'Subsequent to (CID)',
			$content['subsequent_to'] ?? '', 'text', $disabled );

		$html .= Html::closeElement( 'div' );

		// File info table
		// Each file's media_type ("audio", "video", "image", "other") is set by
		// delivery-kid's analyze.detect_media_type() during upload, then written
		// into the ReleaseDraft YAML by the creating Special page's JS.
		if ( !empty( $files ) ) {
			$html .= Html::element( 'h3', [], 'Files' );
			$html .= Html::openElement( 'table', [ 'class' => 'wikitable' ] );
			$html .= '<tr><th>File</th><th>Type</th><th>Format</th><th>Size</th></tr>';

			$draftIdForFiles = $data['draft_id'] ?? '';
			foreach ( $files as $f ) {
				$html .= Html::openElement( 'tr' );
				$html .= $this->renderFilenameCell( $f['original_filename'] ?? '', $draftIdForFiles );
				$html .= Html::element( 'td', [], $f['media_type'] ?? '' );
				$html .= Html::element( 'td', [], $f['format'] ?? '' );
				$sizeStr = !empty( $f['size_bytes'] ) ? $this->formatSize( (int)$f['size_bytes'] ) : '';
				$html .= Html::element( 'td', [], $sizeStr );
				$html .= Html::closeElement( 'tr' );

				// Extra row for video details
				if ( !empty( $f['width'] ) && !empty( $f['height'] ) ) {
					$detail = $f['width'] . 'x' . $f['height'];
					if ( !empty( $f['video_codec'] ) ) {
						$detail .= ' · ' . $f['video_codec'];
					}
					if ( !empty( $f['audio_codec'] ) ) {
						$detail .= ' · ' . $f['audio_codec'];
					}
					$html .= '<tr><td></td><td colspan="3">' . htmlspecialchars( $detail ) . '</td></tr>';
				}
			}

			$html .= Html::closeElement( 'table' );

			// Video preview, trim controls
			$draftId = $data['draft_id'] ?? '';
			$hasVideo = false;
			foreach ( $files as $f ) {
				if ( ( $f['media_type'] ?? '' ) === 'video' ) {
					$hasVideo = true;
					if ( $draftId ) {
						$html .= $this->renderVideoPreviewAndTrim( $f, $draftId, $data, $status );
					}
					break;
				}
			}

			if ( $hasVideo ) {
				$html .= Html::rawElement( 'p', [ 'class' => 'uc-hls-info', 'id' => 'rd-hls-info' ],
					'Video will be transcoded to AV1 HLS (royalty-free).' );
			}
		}

		$html .= Html::closeElement( 'div' );
		return $html;
	}

	private function renderVideoForm( array $data, string $status ): string {
		$content = $data['content'] ?? [];
		$files = $data['files'] ?? [];
		$disabled = $status !== 'draft' ? ' disabled' : '';

		$html = Html::openElement( 'div', [ 'class' => 'rd-video-form', 'id' => 'rd-video-form' ] );
		$html .= Html::element( 'h3', [], 'Video Info' );

		$html .= Html::openElement( 'div', [ 'class' => 'uc-metadata-form' ] );

		// Title
		$html .= $this->renderField( 'rd-content-title', 'Title',
			$content['title'] ?? '', 'text', $disabled );

		// Venue
		$html .= $this->renderField( 'rd-video-venue', 'Venue',
			$content['venue'] ?? '', 'text', $disabled );

		// Performers
		$performers = $content['performers'] ?? [];
		$performersStr = implode( ', ', $performers );
		$html .= Html::openElement( 'div', [ 'class' => 'uc-field' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-video-performers' ], 'Performers' );
		$html .= Html::element( 'input', [
			'type' => 'text',
			'id' => 'rd-video-performers',
			'class' => 'cdx-text-input__input',
			'value' => $performersStr,
			'placeholder' => 'Comma-separated names',
			'disabled' => $disabled ? true : false,
		] );
		$html .= Html::closeElement( 'div' );

		// Description
		$html .= Html::openElement( 'div', [ 'class' => 'uc-field' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-content-description' ], 'Description' );
		$html .= Html::element( 'textarea', [
			'id' => 'rd-content-description',
			'class' => 'cdx-text-input__input',
			'rows' => 3,
			'placeholder' => 'Optional description',
			'disabled' => $status !== 'draft' ? true : false,
		], $content['description'] ?? '' );
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'div' );

		// More settings (collapsed)
		$html .= Html::openElement( 'details', [ 'class' => 'rd-more-settings' ] );
		$html .= Html::element( 'summary', [], 'More settings' );
		$html .= $this->renderField( 'rd-content-file-type', 'File type override',
			$content['file_type'] ?? '', 'text', $disabled );
		$html .= Html::closeElement( 'details' );

		// File info table — media_type values ("audio", "video", "image", "other")
		// are set by delivery-kid's analyze.detect_media_type() during upload,
		// then written into ReleaseDraft YAML by the Deliver page's JS.
		if ( !empty( $files ) ) {
			$html .= Html::element( 'h3', [], 'Files' );
			$html .= Html::openElement( 'table', [ 'class' => 'wikitable' ] );
			$html .= '<tr><th>File</th><th>Type</th><th>Format</th><th>Size</th></tr>';

			$draftIdForFiles = $data['draft_id'] ?? '';
			foreach ( $files as $f ) {
				$html .= Html::openElement( 'tr' );
				$html .= $this->renderFilenameCell( $f['original_filename'] ?? '', $draftIdForFiles );
				$html .= Html::element( 'td', [], $f['media_type'] ?? '' );
				$html .= Html::element( 'td', [], $f['format'] ?? '' );
				$sizeStr = !empty( $f['size_bytes'] ) ? $this->formatSize( (int)$f['size_bytes'] ) : '';
				$html .= Html::element( 'td', [], $sizeStr );
				$html .= Html::closeElement( 'tr' );

				if ( !empty( $f['width'] ) && !empty( $f['height'] ) ) {
					$detail = $f['width'] . 'x' . $f['height'];
					if ( !empty( $f['video_codec'] ) ) {
						$detail .= ' · ' . $f['video_codec'];
					}
					if ( !empty( $f['audio_codec'] ) ) {
						$detail .= ' · ' . $f['audio_codec'];
					}
					$html .= '<tr><td></td><td colspan="3">' . htmlspecialchars( $detail ) . '</td></tr>';
				}
			}

			$html .= Html::closeElement( 'table' );

			// Video preview, trim controls
			$draftId = $data['draft_id'] ?? '';
			foreach ( $files as $f ) {
				if ( ( $f['media_type'] ?? '' ) === 'video' && $draftId ) {
					$html .= $this->renderVideoPreviewAndTrim( $f, $draftId, $data, $status );
					break;
				}
			}

			$html .= Html::rawElement( 'p', [ 'class' => 'uc-hls-info', 'id' => 'rd-hls-info' ],
				'Video will be transcoded to AV1 HLS (royalty-free).' );
		}

		$html .= Html::closeElement( 'div' );
		return $html;
	}

	/**
	 * Render video preview player, preview status, and trim controls.
	 * Shared by renderGenericForm and renderVideoForm.
	 */
	private function renderVideoPreviewAndTrim(
		array $file, string $draftId, array $data, string $status
	): string {
		$html = Html::openElement( 'div', [
			'class' => 'rd-video-preview',
			'id' => 'rd-video-preview',
		] );

		// Preview status message (shown/hidden by JS)
		$html .= Html::element( 'div', [
			'id' => 'rd-preview-status',
			'class' => 'rd-preview-status',
		], '' );

		$html .= Html::element( 'video', [
			'id' => 'rd-video-player',
			'class' => 'rd-video-player',
			'controls' => true,
			'preload' => 'metadata',
			'data-draft-id' => $draftId,
			'data-filename' => $file['original_filename'] ?? '',
		] );

		$html .= Html::closeElement( 'div' );

		// Trim controls
		$trimStart = $data['content']['trim_start_seconds'] ?? '';
		$trimEnd = $data['content']['trim_end_seconds'] ?? '';
		$trimDisabled = ( $status !== 'draft' ) ? [ 'disabled' => true ] : [];

		$html .= Html::openElement( 'div', [
			'class' => 'rd-trim-controls',
			'id' => 'rd-trim-controls',
		] );
		$html .= Html::element( 'h4', [], 'Trim' );

		$html .= Html::openElement( 'div', [ 'class' => 'rd-trim-row' ] );

		$html .= Html::openElement( 'div', [ 'class' => 'rd-trim-field' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-trim-start' ], 'Start' );
		$html .= Html::element( 'input', array_merge( [
			'type' => 'text',
			'id' => 'rd-trim-start',
			'class' => 'rd-trim-input',
			'value' => $trimStart,
			'placeholder' => '0:00',
			'size' => 8,
		], $trimDisabled ) );
		$html .= Html::element( 'button', array_merge( [
			'type' => 'button',
			'id' => 'rd-trim-set-start',
			'class' => 'rd-trim-set-btn',
		], $trimDisabled ), 'Set start' );
		$html .= Html::closeElement( 'div' );

		$html .= Html::openElement( 'div', [ 'class' => 'rd-trim-field' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-trim-end' ], 'End' );
		$html .= Html::element( 'input', array_merge( [
			'type' => 'text',
			'id' => 'rd-trim-end',
			'class' => 'rd-trim-input',
			'value' => $trimEnd,
			'placeholder' => '0:00',
			'size' => 8,
		], $trimDisabled ) );
		$html .= Html::element( 'button', array_merge( [
			'type' => 'button',
			'id' => 'rd-trim-set-end',
			'class' => 'rd-trim-set-btn',
		], $trimDisabled ), 'Set end' );
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'div' ); // rd-trim-row

		$html .= Html::element( 'div', [
			'id' => 'rd-trim-preview',
			'class' => 'rd-trim-preview',
		], '' );

		$html .= Html::closeElement( 'div' ); // rd-trim-controls

		return $html;
	}

	private function renderBlockheightField( array $data ): string {
		$blockheight = $data['blockheight'] ?? '';

		$html = Html::openElement( 'div', [ 'class' => 'rd-blockheight-section' ] );
		$html .= Html::element( 'h3', [], 'When did this happen?' );

		$html .= Html::openElement( 'div', [ 'class' => 'uc-field' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-date-input' ],
			'Approximately when did the events depicted go down?' );

		$html .= Html::openElement( 'div', [ 'class' => 'rd-date-converter' ] );
		$html .= Html::element( 'input', [
			'type' => 'date',
			'id' => 'rd-date-input',
			'class' => 'cdx-text-input__input rd-date-input',
		] );
		$html .= Html::closeElement( 'div' );

		$html .= Html::openElement( 'div', [ 'class' => 'rd-blockheight-row' ] );
		$html .= Html::element( 'label', [ 'for' => 'rd-blockheight' ], 'Or enter an Ethereum block height:' );
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

		// Upload blockheight — auto-captured server-side, preserved in YAML
		$uploadBlockheight = $data['upload_blockheight'] ?? null;
		if ( $uploadBlockheight ) {
			$html .= Html::element( 'input', [
				'type' => 'hidden',
				'id' => 'rd-upload-blockheight',
				'value' => $uploadBlockheight,
			] );
		}

		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/** Map a draft type string to the Special page that handles its upload. */
	private function specialForDraftType( string $type ): string {
		switch ( $type ) {
			case 'record':
			case 'album':
				return 'DeliverRecord';
			case 'video':
				return 'DeliverVideo';
			case 'blue-railroad':
				return 'DeliverBlueRailroad';
			default:
				return 'DeliverOtherContent';
		}
	}

	private function renderActions( array $data ): string {
		$draftId = $data['draft_id'] ?? '';
		$type = $data['type'] ?? 'other';
		$abandoned = (bool)( $data['abandoned'] ?? false );

		$html = Html::openElement( 'div', [ 'class' => 'rd-actions', 'id' => 'rd-actions' ] );

		$html .= Html::element( 'button', [
			'type' => 'button',
			'id' => 'rd-save-btn',
			'class' => 'cdx-button cdx-button--action-progressive',
		], 'Save Draft' );

		// Re-upload — takes user to the matching Special:Deliver* page with
		// ?redraft=<id>. Server-side, delivery-kid wipes the staging dir and
		// reuses the draft_id. Hidden on abandoned drafts.
		if ( $draftId && !$abandoned ) {
			$specialName = $this->specialForDraftType( $type );
			$specialTitle = SpecialPage::getTitleFor( $specialName );
			$reuploadUrl = $specialTitle->getLocalURL( [ 'redraft' => $draftId ] );
			$html .= Html::element( 'a', [
				'href' => $reuploadUrl,
				'class' => 'cdx-button cdx-button--action-default',
				'title' => 'Replace the uploaded files for this draft. '
					. 'The existing files on delivery-kid will be wiped first.',
			], 'Re-upload files' );
		}

		// Preserve original checkbox
		$html .= Html::openElement( 'label', [
			'class' => 'rd-preserve-label',
			'for' => 'rd-preserve-original',
		] );
		$html .= Html::element( 'input', [
			'type' => 'checkbox',
			'id' => 'rd-preserve-original',
		] );
		$html .= ' Keep original file';
		$html .= Html::closeElement( 'label' );

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
		$stages = [ 'preparing' => 'Preparing', 'transcoding' => 'Transcoding', 'tagging' => 'Tagging', 'pinning' => 'Pinning', 'complete' => 'Complete' ];
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

		// Abandon controls — only on still-active (not abandoned, not finalized)
		// drafts. Two destructive choices: keep the staging files (e.g. so
		// they're recoverable later) vs delete them now via delivery-kid's
		// DELETE /content/<id> endpoint.
		$status = $this->statusForActions( $data );
		if ( $draftId && !$abandoned && $status === 'draft' ) {
			$html .= Html::openElement( 'details', [ 'class' => 'rd-abandon-controls' ] );
			$html .= Html::element( 'summary', [], 'Abandon this draft' );
			$html .= Html::element( 'p', [ 'class' => 'rd-abandon-explainer' ],
				'When you decide not to finalize, you can mark this draft as '
				. 'abandoned. Choose whether to delete the uploaded files now '
				. 'or keep them on delivery-kid for later recovery.' );
			$html .= Html::element( 'button', [
				'type' => 'button',
				'id' => 'rd-abandon-keep-btn',
				'class' => 'cdx-button cdx-button--action-default',
			], 'Abandon — keep files' );
			$html .= Html::element( 'button', [
				'type' => 'button',
				'id' => 'rd-abandon-delete-btn',
				'class' => 'cdx-button cdx-button--action-destructive',
			], 'Abandon — delete files' );
			$html .= Html::closeElement( 'details' );
		}

		return $html;
	}

	/**
	 * Best-effort status determination for renderActions — mirrors the
	 * "draft" / "finalizing" / "complete" set the status banner uses.
	 */
	private function statusForActions( array $data ): string {
		$resultCid = $data['result']['cid'] ?? null;
		if ( $resultCid ) {
			return 'complete';
		}
		return 'draft';
	}

	/**
	 * Render a file-list "File" cell with a download link to the staging file.
	 *
	 * The link starts unauth'd; releaseDraft.js hydrates the href on init using
	 * the per-render upload token from JS config, so the auth params are fresh
	 * with each page load (and not baked into MediaWiki's parser cache).
	 */
	private function renderFilenameCell( string $filename, string $draftId ): string {
		if ( $filename === '' ) {
			return Html::element( 'td', [], '' );
		}
		$html = Html::openElement( 'td' );
		$html .= Html::element( 'a', [
			'href' => '#',
			'class' => 'rd-download-original',
			'data-filename' => $filename,
			'data-draft-id' => $draftId,
			'download' => $filename,
			'title' => 'Download original file from staging',
		], $filename );
		$html .= Html::closeElement( 'td' );
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

	private function renderProvenance( array $data ): string {
		$commit = $data['commit'] ?? null;
		$source = $data['source'] ?? null;
		$uploader = $data['uploader'] ?? null;

		if ( !$commit && !$source && !$uploader ) {
			return '';
		}

		$html = Html::openElement( 'details', [ 'class' => 'rd-provenance' ] );
		$html .= Html::element( 'summary', [], 'Provenance' );
		$html .= Html::openElement( 'table', [ 'class' => 'wikitable' ] );

		if ( $uploader ) {
			$html .= '<tr>' . Html::element( 'th', [], 'Uploader' ) .
				Html::element( 'td', [], $uploader ) . '</tr>';
		}
		if ( $source ) {
			$html .= '<tr>' . Html::element( 'th', [], 'Source' ) .
				Html::element( 'td', [], $source ) . '</tr>';
		}
		if ( $commit ) {
			$html .= '<tr>' . Html::element( 'th', [], 'Build' ) .
				Html::element( 'td', [ 'class' => 'rd-commit' ], $commit ) . '</tr>';
		}

		$html .= Html::closeElement( 'table' );
		$html .= Html::closeElement( 'details' );

		return $html;
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
