<?php
/**
 * Content class for ReleaseDraft pages with YAML metadata.
 *
 * Stores structured data about a draft release:
 * - delivery-kid draft ID (referencing files on storage)
 * - type (album, content, etc.)
 * - status (draft, finalizing, complete)
 * - blockheight for temporal reference
 * - type-specific metadata (album info, tracks, etc.)
 * - result data after finalization (CID, gateway URL)
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use Content;
use MediaWiki\Content\AbstractContent;
use StatusValue;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class ReleaseDraftContent extends AbstractContent {

	/** @var string Raw YAML text */
	private string $yamlText;

	/** @var array|null Parsed data, cached */
	private ?array $parsedData = null;

	/** @var ParseException|null Parse error, if any */
	private ?ParseException $parseError = null;

	public function __construct( string $text ) {
		parent::__construct( 'release-draft-yaml' );
		$this->yamlText = $text;
	}

	public function getText(): string {
		return $this->yamlText;
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
			$data = Yaml::parse( $this->yamlText );
			$this->parsedData = is_array( $data ) ? $data : [];
			$this->parseError = null;
		} catch ( ParseException $e ) {
			$this->parsedData = [];
			$this->parseError = $e;
		}
	}

	// -- Getters --

	public function getDraftId(): ?string {
		return $this->getData()['draft_id'] ?? null;
	}

	public function getDraftType(): string {
		return $this->getData()['type'] ?? 'album';
	}

	public function getStatus(): string {
		return $this->getData()['status'] ?? 'draft';
	}

	public function getBlockheight(): ?int {
		$bh = $this->getData()['blockheight'] ?? null;
		return $bh !== null ? (int)$bh : null;
	}

	public function getAlbumData(): array {
		return $this->getData()['album'] ?? [];
	}

	public function getTracks(): array {
		return $this->getData()['tracks'] ?? [];
	}

	public function getResult(): array {
		return $this->getData()['result'] ?? [];
	}

	public function getAlbumTitle(): ?string {
		return $this->getAlbumData()['title'] ?? null;
	}

	public function getArtist(): ?string {
		return $this->getAlbumData()['artist'] ?? null;
	}

	// -- AbstractContent methods --

	public function validate(): StatusValue {
		$status = StatusValue::newGood();

		if ( $this->getParseError() !== null ) {
			$status->fatal(
				'pickipediareleases-invalid-yaml',
				$this->parseError->getMessage()
			);
			return $status;
		}

		$data = $this->getData();
		if ( empty( $data['draft_id'] ) ) {
			$status->fatal( 'releasedraft-missing-draft-id' );
		}
		if ( empty( $data['type'] ) ) {
			$status->fatal( 'releasedraft-missing-type' );
		}

		return $status;
	}

	public function isValid(): bool {
		return $this->validate()->isOK();
	}

	public function getTextForSearchIndex(): string {
		$parts = [];
		$album = $this->getAlbumData();
		if ( !empty( $album['title'] ) ) {
			$parts[] = $album['title'];
		}
		if ( !empty( $album['artist'] ) ) {
			$parts[] = $album['artist'];
		}
		if ( !empty( $album['description'] ) ) {
			$parts[] = $album['description'];
		}
		foreach ( $this->getTracks() as $track ) {
			if ( !empty( $track['title'] ) ) {
				$parts[] = $track['title'];
			}
		}
		return implode( "\n", $parts );
	}

	public function getWikitextForTransclusion(): string {
		return $this->yamlText;
	}

	public function getTextForSummary( $maxLength = 250 ) {
		$album = $this->getAlbumData();
		$summary = '';
		if ( !empty( $album['artist'] ) && !empty( $album['title'] ) ) {
			$summary = $album['artist'] . ' — ' . $album['title'];
		} elseif ( !empty( $album['title'] ) ) {
			$summary = $album['title'];
		}
		return $summary ? mb_substr( $summary, 0, $maxLength ) : mb_substr( $this->yamlText, 0, $maxLength );
	}

	public function getNativeData(): string {
		return $this->yamlText;
	}

	public function getSize(): int {
		return strlen( $this->yamlText );
	}

	public function copy(): Content {
		return new ReleaseDraftContent( $this->yamlText );
	}

	public function isCountable( $hasLinks = null ): bool {
		return true;
	}

	public function serialize( $format = null ): string {
		return $this->yamlText;
	}
}
