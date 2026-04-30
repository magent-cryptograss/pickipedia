<?php
/**
 * Content handler for recording-metadata-yaml pages.
 *
 * Renders an interactive timeline editor on Release:*\/Metadata pages.
 * The full editor JS is ported from rabbithole/src/editor in a
 * follow-up commit; this scaffolding ships a stub render so the
 * routing can be verified end-to-end first.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaRecordingMetadata;

use Content;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\TextContentHandler;
use MediaWiki\Content\ValidationParams;
use MediaWiki\Html\Html;
use MediaWiki\Parser\ParserOutput;
use StatusValue;

class RecordingMetadataContentHandler extends TextContentHandler {

	public function __construct( $modelId = 'recording-metadata-yaml' ) {
		parent::__construct( $modelId, [ CONTENT_FORMAT_TEXT ] );
	}

	public function serializeContent( Content $content, $format = null ): string {
		if ( !$content instanceof RecordingMetadataContent ) {
			throw new \InvalidArgumentException( 'Expected RecordingMetadataContent' );
		}
		return $content->getText();
	}

	public function unserializeContent( $text, $format = null ): RecordingMetadataContent {
		return new RecordingMetadataContent( $text );
	}

	public function makeEmptyContent(): RecordingMetadataContent {
		return new RecordingMetadataContent(
			"# Recording metadata for this Release.\n" .
			"# Edited via the timeline editor on this page.\n" .
			"\n" .
			"timeline: {}\n" .
			"ensemble: {}\n"
		);
	}

	public function validateSave(
		Content $content,
		ValidationParams $validationParams
	): StatusValue {
		if ( !$content instanceof RecordingMetadataContent ) {
			return StatusValue::newFatal( 'invalid-content-data' );
		}
		$err = $content->getParseError();
		if ( $err !== null ) {
			return StatusValue::newFatal(
				'pickipediarecordingmetadata-invalid-yaml',
				$err->getMessage()
			);
		}
		return StatusValue::newGood();
	}

	protected function fillParserOutput(
		Content $content,
		ContentParseParams $cpoParams,
		ParserOutput &$output
	): void {
		if ( !$content instanceof RecordingMetadataContent ) {
			$output->setRawText( '<p>Invalid content type</p>' );
			return;
		}

		$err = $content->getParseError();
		$html = '';

		if ( $err !== null ) {
			$html .= Html::rawElement( 'div',
				[ 'class' => 'rm-yaml-error' ],
				Html::element( 'strong', [], 'YAML parse error: ' )
				. Html::element( 'code', [], $err->getMessage() )
			);
		}

		// Stub render — interactive editor lands in a follow-up commit.
		$html .= Html::openElement( 'div', [ 'class' => 'rm-editor-stub' ] );
		$html .= Html::element( 'h2', [], 'Recording Metadata' );
		$html .= Html::element( 'p', [],
			'Interactive timeline editor will mount here. Raw YAML is shown '
			. 'below for now; edit via the Edit tab to change it.' );
		$html .= Html::element( 'pre', [ 'class' => 'rm-yaml-raw' ],
			$content->getText() );
		$html .= Html::closeElement( 'div' );

		$output->setRawText( $html );
	}

	public function supportsDirectEditing(): bool {
		return true;
	}

	public function supportsDirectApiEditing(): bool {
		return true;
	}
}
