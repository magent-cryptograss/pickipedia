<?php
/**
 * Content handler for Release pages with YAML metadata
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use Content;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\ValidationParams;
use MediaWiki\Html\Html;
use MediaWiki\Parser\ParserOutput;
use StatusValue;

class ReleaseContentHandler extends ContentHandler {

	/**
	 * @param string $modelId
	 */
	public function __construct( $modelId = 'release-yaml' ) {
		parent::__construct( $modelId, [ CONTENT_FORMAT_TEXT ] );
	}

	/**
	 * @inheritDoc
	 */
	public function serializeContent( Content $content, $format = null ): string {
		if ( !$content instanceof ReleaseContent ) {
			throw new \InvalidArgumentException( 'Expected ReleaseContent' );
		}
		return $content->getText();
	}

	/**
	 * @inheritDoc
	 */
	public function unserializeContent( $text, $format = null ): ReleaseContent {
		return new ReleaseContent( $text );
	}

	/**
	 * @inheritDoc
	 */
	public function makeEmptyContent(): ReleaseContent {
		// CID comes from the page title, so body can be empty or have optional metadata
		$defaultYaml = <<<'YAML'
# Optional metadata - the CID is the page title
# description:
# pinned_on:
#   - delivery-kid
#   - maybelle
YAML;
		return new ReleaseContent( $defaultYaml );
	}

	/**
	 * @inheritDoc
	 */
	public function validateSave(
		Content $content,
		ValidationParams $validationParams
	): StatusValue {
		if ( !$content instanceof ReleaseContent ) {
			return StatusValue::newFatal( 'invalid-content-data' );
		}
		return $content->validate();
	}

	/**
	 * @inheritDoc
	 */
	protected function fillParserOutput(
		Content $content,
		ContentParseParams $cpoParams,
		ParserOutput &$output
	): void {
		if ( !$content instanceof ReleaseContent ) {
			$output->setRawText( '<p>Invalid content type</p>' );
			return;
		}

		// Add our CSS
		$output->addModuleStyles( [ 'ext.pickipediaReleases.styles' ] );

		$html = '';
		$parseError = $content->getParseError();

		// Show validation errors if present
		$validation = $content->validate();
		if ( !$validation->isOK() ) {
			$html .= $this->renderValidationErrors( $validation );
		}

		// Extract CID from page title
		$pageRef = $cpoParams->getPage();
		$cid = $pageRef ? $pageRef->getDBkey() : null;

		// Get optional YAML metadata
		$data = $content->getData();

		// Render the release info
		$html .= $this->renderReleaseInfo( $cid, $data, $pageRef );

		// Get and render backlinks
		if ( $pageRef ) {
			$html .= $this->renderBacklinks( $pageRef );
		}

		// Add raw YAML view if there's any content
		$yamlText = trim( $content->getText() );
		if ( !empty( $yamlText ) && !$this->isOnlyComments( $yamlText ) ) {
			$html .= $this->renderRawYaml( $yamlText );
		}

		$output->setRawText( $html );

		// Add categories for organization
		if ( $pageRef ) {
			$output->addCategory( 'Releases' );

			// Add category based on pin status
			if ( !empty( $data['pinned_on'] ) ) {
				$output->addCategory( 'Pinned_Releases' );
			}
		}
	}

	/**
	 * Check if YAML content is only comments
	 *
	 * @param string $yaml
	 * @return bool
	 */
	private function isOnlyComments( string $yaml ): bool {
		$lines = explode( "\n", $yaml );
		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( $trimmed !== '' && $trimmed[0] !== '#' ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Render the main release info section
	 *
	 * @param string|null $cid
	 * @param array $data
	 * @param mixed $pageRef
	 * @return string
	 */
	private function renderReleaseInfo( ?string $cid, array $data, $pageRef ): string {
		$html = Html::openElement( 'div', [ 'class' => 'release-info' ] );

		// Render video player if this release is a video
		if ( $cid && !empty( $data['file_type'] ) && str_starts_with( $data['file_type'], 'video/' ) ) {
			$html .= Html::element( 'div', [
				'class' => 'hls-video-player',
				'data-cid' => $cid,
				'data-width' => '100%',
				'data-max-width' => '800px',
			] );
		}

		$html .= Html::openElement( 'table', [ 'class' => 'release-metadata wikitable' ] );

		// CID from page title (primary identifier)
		if ( $cid ) {
			$html .= Html::openElement( 'tr', [ 'class' => 'release-cid' ] );
			$html .= Html::element( 'th', [], 'IPFS CID' );
			$html .= Html::rawElement( 'td', [], $this->renderIpfsLink( $cid ) );
			$html .= Html::closeElement( 'tr' );
		}

		// Pin status from YAML
		if ( !empty( $data['pinned_on'] ) ) {
			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'th', [], 'Pinned On' );
			$pinnedList = is_array( $data['pinned_on'] )
				? implode( ', ', $data['pinned_on'] )
				: (string)$data['pinned_on'];
			$html .= Html::element( 'td', [ 'class' => 'release-pinned' ], $pinnedList );
			$html .= Html::closeElement( 'tr' );
		}

		// Description from YAML
		if ( !empty( $data['description'] ) ) {
			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'th', [], 'Description' );
			$html .= Html::rawElement( 'td', [], nl2br( htmlspecialchars( $data['description'] ) ) );
			$html .= Html::closeElement( 'tr' );
		}

		// BitTorrent infohash if present
		if ( !empty( $data['bittorrent_infohash'] ) ) {
			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'th', [], 'BitTorrent' );
			$html .= Html::rawElement( 'td', [],
				$this->renderTorrentLink( $data['bittorrent_infohash'], $data['title'] ?? $cid )
			);
			$html .= Html::closeElement( 'tr' );
		}

		// File info if present
		if ( !empty( $data['file_type'] ) || !empty( $data['file_size'] ) ) {
			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'th', [], 'File Info' );
			$info = [];
			if ( !empty( $data['file_type'] ) ) {
				$info[] = htmlspecialchars( $data['file_type'] );
			}
			if ( !empty( $data['file_size'] ) ) {
				$info[] = $this->formatFileSize( (int)$data['file_size'] );
			}
			$html .= Html::rawElement( 'td', [], implode( ' · ', $info ) );
			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'table' );
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/**
	 * Render backlinks from other pages
	 *
	 * @param mixed $pageRef
	 * @return string
	 */
	private function renderBacklinks( $pageRef ): string {
		$services = \MediaWiki\MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

		// MediaWiki 1.43+ uses linktarget table instead of pl_namespace/pl_title
		// Query pages that link to this release via linktarget join
		$result = $dbr->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'pagelinks' )
			->join( 'linktarget', null, 'pl_target_id = lt_id' )
			->join( 'page', null, 'pl_from = page_id' )
			->where( [
				'lt_namespace' => $pageRef->getNamespace(),
				'lt_title' => $pageRef->getDBkey(),
			] )
			->limit( 50 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$links = [];
		foreach ( $result as $row ) {
			$title = $services->getTitleFactory()->makeTitle(
				$row->page_namespace,
				$row->page_title
			);
			$links[] = Html::element(
				'a',
				[ 'href' => $title->getLocalURL() ],
				$title->getPrefixedText()
			);
		}

		if ( empty( $links ) ) {
			return '';
		}

		$html = Html::openElement( 'div', [ 'class' => 'release-backlinks' ] );
		$html .= Html::element( 'h3', [], 'Referenced by' );
		$html .= Html::rawElement( 'ul', [],
			implode( '', array_map( fn( $link ) => Html::rawElement( 'li', [], $link ), $links ) )
		);
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/**
	 * Render validation errors as HTML
	 *
	 * @param StatusValue $status
	 * @return string
	 */
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

	/**
	 * Render an IPFS CID as a clickable link
	 *
	 * @param string $cid
	 * @return string
	 */
	private function renderIpfsLink( string $cid ): string {
		$gatewayUrl = "https://ipfs.io/ipfs/{$cid}";
		$dweb = "ipfs://{$cid}";

		return Html::rawElement( 'span', [ 'class' => 'release-ipfs-link' ],
			Html::element( 'code', [], $cid ) . ' ' .
			Html::element( 'a', [ 'href' => $gatewayUrl, 'rel' => 'nofollow' ], '[gateway]' ) . ' ' .
			Html::element( 'a', [ 'href' => $dweb ], '[ipfs://]' )
		);
	}

	/**
	 * Render a BitTorrent infohash as a magnet link
	 *
	 * @param string $infohash
	 * @param string $name
	 * @return string
	 */
	private function renderTorrentLink( string $infohash, string $name ): string {
		$magnetUri = "magnet:?xt=urn:btih:{$infohash}";
		if ( $name ) {
			$magnetUri .= "&dn=" . urlencode( $name );
		}

		return Html::rawElement( 'span', [ 'class' => 'release-torrent-link' ],
			Html::element( 'code', [], $infohash ) . ' ' .
			Html::element( 'a', [ 'href' => $magnetUri ], '[magnet]' )
		);
	}

	/**
	 * Format file size in human-readable format
	 *
	 * @param int $bytes
	 * @return string
	 */
	private function formatFileSize( int $bytes ): string {
		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$unitIndex = 0;
		$size = (float)$bytes;

		while ( $size >= 1024 && $unitIndex < count( $units ) - 1 ) {
			$size /= 1024;
			$unitIndex++;
		}

		return round( $size, 2 ) . ' ' . $units[$unitIndex] .
			' (' . number_format( $bytes ) . ' bytes)';
	}

	/**
	 * Render raw YAML in a collapsible section
	 *
	 * @param string $yaml
	 * @return string
	 */
	private function renderRawYaml( string $yaml ): string {
		return Html::rawElement( 'details', [ 'class' => 'release-raw-yaml' ],
			Html::element( 'summary', [], 'Raw YAML' ) .
			Html::element( 'pre', [], $yaml )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function supportsDirectEditing(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function supportsDirectApiEditing(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getActionOverrides(): array {
		return [];
	}
}
