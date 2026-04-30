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

		// Mount points hydrated by the editor JS. The editor reads the
		// page's YAML from wgRecordingMetadataYaml (set below) — not from
		// any of these elements — so they can start empty.
		$html .= Html::openElement( 'div', [ 'class' => 'rm-editor-shell' ] );
		$html .= Html::element( 'h2', [], 'Recording Metadata' );

		// Container the TimelineEditor mounts inside.
		$html .= Html::element( 'div', [ 'id' => 'rm-editor-root' ], '' );

		// Save bar.
		$html .= Html::openElement( 'div', [ 'class' => 'rm-actions' ] );
		$html .= Html::element( 'button', [
			'type' => 'button',
			'id' => 'rm-save-btn',
			'class' => 'cdx-button cdx-button--action-progressive',
			'disabled' => true,
		], 'Saved' );
		$html .= Html::element( 'span', [
			'id' => 'rm-save-status',
			'class' => 'rm-save-status',
		], '' );
		$html .= Html::closeElement( 'div' );

		// Keyboard help (KeyboardShortcuts.getHelpHTML fills this in).
		$html .= Html::element( 'div', [
			'id' => 'rm-keyboard-help',
			'class' => 'rm-keyboard-help',
		], '' );

		// Raw YAML, collapsed — for inspection / copy-paste.
		$html .= Html::rawElement( 'details', [ 'class' => 'rm-raw-yaml' ],
			Html::element( 'summary', [], 'Raw YAML' )
			. Html::element( 'pre', [], $content->getText() )
		);

		$html .= Html::closeElement( 'div' );

		// Hand the YAML to the editor JS via JsConfigVar (avoids a
		// round-trip back to fetch what the parser already read).
		$output->setJsConfigVar( 'wgRecordingMetadataYaml', $content->getText() );

		// Parent Release page — derived from the title by chopping the
		// "/Metadata" suffix. The editor JS uses this to fetch the
		// parent's YAML for audio CIDs (so it can wire up playback).
		$title = $cpoParams->getPage();
		$dbkey = $title ? $title->getDBkey() : '';
		if ( str_ends_with( $dbkey, '/Metadata' ) ) {
			$parentDbkey = substr( $dbkey, 0, -strlen( '/Metadata' ) );
			$output->setJsConfigVar( 'wgRecordingMetadataParentTitle',
				'Release:' . $parentDbkey );
		}

		$output->addModules( [ 'ext.pickipediaRecordingMetadata.editor' ] );

		$output->setRawText( $html );
	}

	public function supportsDirectEditing(): bool {
		return true;
	}

	public function supportsDirectApiEditing(): bool {
		return true;
	}
}
