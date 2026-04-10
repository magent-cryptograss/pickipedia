<?php
/**
 * Content class for Release pages with YAML metadata
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

class ReleaseContent extends TextContent {

	/** @var array|null Parsed data, cached */
	private ?array $parsedData = null;

	/** @var ParseException|null Parse error, if any */
	private ?ParseException $parseError = null;

	/**
	 * @param string $text Raw YAML text
	 */
	public function __construct( string $text ) {
		parent::__construct( $text, 'release-yaml' );
	}

	/**
	 * Parse and return the YAML data as an associative array
	 *
	 * @return array Parsed data, or empty array on parse failure
	 */
	public function getData(): array {
		if ( $this->parsedData === null ) {
			$this->parseYaml();
		}
		return $this->parsedData ?? [];
	}

	/**
	 * Get any parse error that occurred
	 *
	 * @return ParseException|null
	 */
	public function getParseError(): ?ParseException {
		if ( $this->parsedData === null && $this->parseError === null ) {
			$this->parseYaml();
		}
		return $this->parseError;
	}

	/**
	 * Parse the YAML text
	 */
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

	/**
	 * Get the IPFS CID from the release
	 *
	 * @return string|null
	 */
	public function getIpfsCid(): ?string {
		$data = $this->getData();
		return $data['ipfs_cid'] ?? null;
	}

	/**
	 * Get the BitTorrent infohash
	 *
	 * @return string|null
	 */
	public function getBittorrentHash(): ?string {
		$data = $this->getData();
		return $data['bittorrent_infohash'] ?? null;
	}

	/**
	 * Get the release title
	 *
	 * @return string|null
	 */
	public function getReleaseTitle(): ?string {
		$data = $this->getData();
		return $data['title'] ?? null;
	}

	/**
	 * Get release type (video, record, blue-railroad, other)
	 *
	 * @return string|null
	 */
	public function getReleaseType(): ?string {
		$data = $this->getData();
		return $data['release_type'] ?? null;
	}

	/**
	 * Get file type (MIME type)
	 *
	 * @return string|null
	 */
	public function getFileType(): ?string {
		$data = $this->getData();
		return $data['file_type'] ?? null;
	}

	/**
	 * Get file size in bytes
	 *
	 * @return int|null
	 */
	public function getFileSize(): ?int {
		$data = $this->getData();
		$size = $data['file_size'] ?? null;
		return $size !== null ? (int)$size : null;
	}

	/**
	 * Get description
	 *
	 * @return string|null
	 */
	public function getDescription(): ?string {
		$data = $this->getData();
		return $data['description'] ?? null;
	}

	/**
	 * Get BitTorrent trackers
	 *
	 * @return array
	 */
	public function getTrackers(): array {
		$data = $this->getData();
		return $data['bittorrent_trackers'] ?? [];
	}

	/**
	 * Validate the content - YAML must parse, but no required fields
	 * The CID comes from the page title, so page body is optional metadata.
	 *
	 * @return StatusValue
	 */
	public function validate(): StatusValue {
		$status = StatusValue::newGood();

		// Check for parse errors only - all fields are optional
		if ( $this->getParseError() !== null ) {
			$status->fatal(
				'pickipediareleases-invalid-yaml',
				$this->parseError->getMessage()
			);
		}

		return $status;
	}

	/**
	 * @inheritDoc
	 */
	public function isValid(): bool {
		return $this->validate()->isOK();
	}

	/**
	 * @inheritDoc
	 */
	public function getTextForSearchIndex(): string {
		$data = $this->getData();
		$searchText = [];

		// Index title and description for search
		if ( isset( $data['title'] ) ) {
			$searchText[] = $data['title'];
		}
		if ( isset( $data['description'] ) ) {
			$searchText[] = $data['description'];
		}

		return implode( "\n", $searchText );
	}

	/**
	 * @inheritDoc
	 */
	public function getTextForSummary( $maxLength = 250 ) {
		$title = $this->getReleaseTitle();
		if ( $title !== null ) {
			return mb_substr( $title, 0, $maxLength );
		}
		return mb_substr( $this->getText(), 0, $maxLength );
	}

	/**
	 * @inheritDoc
	 */
	public function copy(): Content {
		return new ReleaseContent( $this->getText() );
	}

	/**
	 * @inheritDoc
	 */
	public function isCountable( $hasLinks = null ): bool {
		return true;
	}
}
