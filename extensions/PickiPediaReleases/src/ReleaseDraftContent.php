<?php
/**
 * Content class for ReleaseDraft pages with YAML metadata.
 *
 * Stores structured data about a draft release:
 * - delivery-kid draft ID (referencing files on storage)
 * - type (album, content, blue-railroad, etc.)
 * - source (which Special page or bot created this)
 * - commit hash (maybelle-config build that processed the upload)
 * - blockheight for temporal reference
 * - uploader identity
 * - type-specific metadata (album info, tracks, content info, files)
 *
 * Status is NOT stored here — it's derived from context (existence of
 * a corresponding Release page, active Coconut jobs, etc.)
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use Content;
use MediaWiki\Content\TextContent;
use StatusValue;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class ReleaseDraftContent extends TextContent {

	/** @var array|null Parsed data, cached */
	private ?array $parsedData = null;

	/** @var ParseException|null Parse error, if any */
	private ?ParseException $parseError = null;

	public function __construct( string $text ) {
		parent::__construct( $text, 'release-draft-yaml' );
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

	// -- Getters --

	public function getDraftId(): ?string {
		return $this->getData()['draft_id'] ?? null;
	}

	public function getDraftType(): string {
		return $this->getData()['type'] ?? 'record';
	}

	public function getSource(): string {
		return $this->getData()['source'] ?? '';
	}

	public function getCommit(): string {
		return $this->getData()['commit'] ?? '';
	}

	public function getUploader(): string {
		return $this->getData()['uploader'] ?? '';
	}

	public function getBlockheight(): ?int {
		$bh = $this->getData()['blockheight'] ?? null;
		return $bh !== null ? (int)$bh : null;
	}

	public function getUploadBlockheight(): ?int {
		$bh = $this->getData()['upload_blockheight'] ?? null;
		return $bh !== null ? (int)$bh : null;
	}

	public function getAlbumData(): array {
		return $this->getData()['album'] ?? [];
	}

	public function getTracks(): array {
		return $this->getData()['tracks'] ?? [];
	}

	public function getContentData(): array {
		return $this->getData()['content'] ?? [];
	}

	public function getFiles(): array {
		return $this->getData()['files'] ?? [];
	}

	public function getAlbumTitle(): ?string {
		return $this->getAlbumData()['title'] ?? null;
	}

	public function getArtist(): ?string {
		return $this->getAlbumData()['artist'] ?? null;
	}

	public function getContentTitle(): ?string {
		return $this->getContentData()['title'] ?? null;
	}

	public function getVenue(): ?string {
		return $this->getContentData()['venue'] ?? null;
	}

	public function getPerformers(): array {
		return $this->getContentData()['performers'] ?? [];
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

		$draftType = $this->getDraftType();
		if ( $draftType === 'record' || $draftType === 'album' ) {
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
		} else {
			$content = $this->getContentData();
			if ( !empty( $content['title'] ) ) {
				$parts[] = $content['title'];
			}
			if ( !empty( $content['description'] ) ) {
				$parts[] = $content['description'];
			}
			if ( !empty( $content['venue'] ) ) {
				$parts[] = $content['venue'];
			}
			foreach ( $content['performers'] ?? [] as $performer ) {
				$parts[] = $performer;
			}
		}

		return implode( "\n", $parts );
	}

	public function getTextForSummary( $maxLength = 250 ) {
		$draftType = $this->getDraftType();
		if ( $draftType === 'record' || $draftType === 'album' ) {
			$album = $this->getAlbumData();
			$summary = '';
			if ( !empty( $album['artist'] ) && !empty( $album['title'] ) ) {
				$summary = $album['artist'] . ' — ' . $album['title'];
			} elseif ( !empty( $album['title'] ) ) {
				$summary = $album['title'];
			}
		} else {
			$content = $this->getContentData();
			$summary = $content['title'] ?? '';
		}

		return $summary ? mb_substr( $summary, 0, $maxLength ) : mb_substr( $this->getText(), 0, $maxLength );
	}

	public function isCountable( $hasLinks = null ): bool {
		return true;
	}
}
