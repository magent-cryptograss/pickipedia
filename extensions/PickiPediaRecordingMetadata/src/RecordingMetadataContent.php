<?php
/**
 * Content class for recording-metadata-yaml pages (Release:*\/Metadata).
 *
 * Stores per-recording timeline + ensemble + arrangement data as YAML.
 * The companion ContentHandler renders an interactive editor on view;
 * this class is just the storage shape.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaRecordingMetadata;

use MediaWiki\Content\TextContent;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class RecordingMetadataContent extends TextContent {

	/** @var array|null Parsed data, cached */
	private ?array $parsedData = null;

	/** @var ParseException|null Parse error, if any */
	private ?ParseException $parseError = null;

	public function __construct( string $text ) {
		parent::__construct( $text, 'recording-metadata-yaml' );
	}

	public function getData(): array {
		if ( $this->parsedData === null ) {
			$this->parseYaml();
		}
		return $this->parsedData ?? [];
	}

	public function getParseError(): ?ParseException {
		if ( $this->parsedData === null && $this->parseError === null ) {
			$this->parseYaml();
		}
		return $this->parseError;
	}

	private function parseYaml(): void {
		try {
			$data = Yaml::parse( $this->getText() );
			$this->parsedData = is_array( $data ) ? $data : [];
			$this->parseError = null;
		} catch ( ParseException $e ) {
			$this->parsedData = [];
			$this->parseError = $e;
		}
	}

	public function isValid(): bool {
		return $this->getParseError() === null;
	}

	public function getTextForSearchIndex(): string {
		// Surface ensemble names so the wiki search can find recordings
		// by who plays on them.
		$names = array_keys( $this->getData()['ensemble'] ?? [] );
		return implode( "\n", $names );
	}
}
