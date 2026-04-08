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

		// Determine if this release is a video:
		// 1. Explicit file_type in YAML
		// 2. Inferred from backlinks (pages using HLSVideo template link here)
		$isVideo = !empty( $data['file_type'] ) && str_starts_with( $data['file_type'], 'video/' );
		if ( !$isVideo && $pageRef ) {
			$isVideo = $this->hasHLSVideoBacklink( $pageRef );
		}

		// Render the release info
		$html .= $this->renderReleaseInfo( $cid, $data, $pageRef, $isVideo );

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

			// Add category based on release type
			$releaseType = $data['release_type'] ?? null;
			if ( $releaseType ) {
				$typeCategoryMap = [
					'video' => 'Video_Releases',
					'record' => 'Record_Releases',
					'blue-railroad' => 'Blue_Railroad_Releases',
					'other' => 'Other_Releases',
				];
				if ( isset( $typeCategoryMap[$releaseType] ) ) {
					$output->addCategory( $typeCategoryMap[$releaseType] );
				}
			}
		}

		// Emit Semantic MediaWiki properties if SMW is available
		if ( $pageRef && class_exists( \SMW\DIProperty::class ) ) {
			try {
				$this->setSemanticProperties( $output, $cid, $data, $pageRef );
			} catch ( \Throwable $e ) {
				// Don't let SMW errors break page rendering
				wfDebugLog( 'PickiPediaReleases',
					'SMW property emission failed: ' . $e->getMessage()
				);
			}
		}
	}

	/**
	 * Emit Semantic MediaWiki properties for this Release page.
	 *
	 * Uses SMW\DataValueFactory for compatibility across SMW versions.
	 *
	 * @param ParserOutput $output
	 * @param string|null $cid
	 * @param array $data
	 * @param mixed $pageRef
	 */
	private function setSemanticProperties(
		ParserOutput $output,
		?string $cid,
		array $data,
		$pageRef
	): void {
		$title = \MediaWiki\MediaWikiServices::getInstance()
			->getTitleFactory()
			->makeTitle( $pageRef->getNamespace(), $pageRef->getDBkey() );

		$subject = \SMW\DIWikiPage::newFromTitle( $title );
		$semanticData = new \SMW\SemanticData( $subject );
		$dvFactory = \SMW\DataValueFactory::getInstance();

		$props = [];

		if ( $cid ) {
			$normalizedCid = str_starts_with( $cid, 'Bafy' ) ? strtolower( $cid ) : $cid;
			$props['IPFS_CID'] = $normalizedCid;
		}
		if ( !empty( $data['title'] ) ) {
			$props['Release_title'] = $data['title'];
		}
		if ( !empty( $data['release_type'] ) ) {
			$props['Release_type'] = $data['release_type'];
		}
		if ( !empty( $data['file_type'] ) ) {
			$props['File_type'] = $data['file_type'];
		}
		if ( !empty( $data['file_size'] ) ) {
			$props['File_size'] = (string)(int)$data['file_size'];
		}
		if ( !empty( $data['pinned_on'] ) ) {
			$props['Pinned_on'] = is_array( $data['pinned_on'] )
				? implode( ', ', $data['pinned_on'] )
				: (string)$data['pinned_on'];
		}
		if ( !empty( $data['bittorrent_infohash'] ) ) {
			$props['BitTorrent_infohash'] = $data['bittorrent_infohash'];
		}
		if ( !empty( $data['description'] ) ) {
			$props['Release_description'] = $data['description'];
		}

		foreach ( $props as $propName => $value ) {
			$dataValue = $dvFactory->newDataValueByText( $propName, $value );
			if ( $dataValue->isValid() ) {
				$semanticData->addDataValue( $dataValue );
			}
		}

		// Store semantic data in the ParserOutput for SMW to pick up
		$parserData = new \SMW\ParserData( $title, $output );
		$parserData->getSemanticData()->importFrom( $semanticData );
		$parserData->pushSemanticDataToParserOutput();
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
	private function renderReleaseInfo( ?string $cid, array $data, $pageRef, bool $isVideo = false ): string {
		$html = Html::openElement( 'div', [ 'class' => 'release-info' ] );

		// Render video player if this release is a video
		if ( $cid && $isVideo ) {
			$html .= Html::element( 'div', [
				'class' => 'hls-video-player',
				'data-cid' => $cid,
				'data-width' => '100%',
				'data-max-width' => '800px',
			] );
		}

		// Title from YAML
		if ( !empty( $data['title'] ) ) {
			$html .= Html::element( 'h2', [ 'class' => 'release-title' ], $data['title'] );
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
				$this->renderTorrentLink(
					$data['bittorrent_infohash'],
					$data['title'] ?? $cid,
					$data['bittorrent_trackers'] ?? [],
					$data['bittorrent_webseeds'] ?? [],
					$data['bittorrent_torrent_url'] ?? null
				)
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
	 * Check if any page linking to this release uses the HLSVideo template.
	 *
	 * Joins pagelinks → page → templatelinks → linktarget to find pages
	 * that both link to this Release page and transclude Template:HLSVideo.
	 *
	 * @param mixed $pageRef
	 * @return bool
	 */
	private function hasHLSVideoBacklink( $pageRef ): bool {
		$services = \MediaWiki\MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );

		// Find pages that link to this Release AND use Template:HLSVideo
		$result = $dbr->newSelectQueryBuilder()
			->select( '1' )
			->from( 'pagelinks' )
			->join( 'linktarget', 'lt_release', 'pl_target_id = lt_release.lt_id' )
			->join( 'templatelinks', null, 'pl_from = tl_from' )
			->join( 'linktarget', 'lt_template', 'tl_target_id = lt_template.lt_id' )
			->where( [
				'lt_release.lt_namespace' => $pageRef->getNamespace(),
				'lt_release.lt_title' => $pageRef->getDBkey(),
				'lt_template.lt_namespace' => NS_TEMPLATE,
				'lt_template.lt_title' => 'HLSVideo',
			] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchResultSet();

		return $result->numRows() > 0;
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
		// CIDv1 (bafy...) is Base32 lowercase; MediaWiki capitalizes page titles,
		// so we must lowercase it back. CIDv0 (Qm...) is Base58, case-sensitive.
		$normalizedCid = str_starts_with( $cid, 'Bafy' ) ? strtolower( $cid ) : $cid;
		$gatewayUrl = "https://ipfs.io/ipfs/" . $normalizedCid;
		$dweb = "ipfs://{$normalizedCid}";

		return Html::rawElement( 'span', [ 'class' => 'release-ipfs-link' ],
			Html::element( 'code', [], $normalizedCid ) . ' ' .
			Html::element( 'a', [ 'href' => $gatewayUrl, 'rel' => 'nofollow' ], '[gateway]' ) . ' ' .
			Html::element( 'a', [ 'href' => $dweb ], '[ipfs://]' )
		);
	}

	/**
	 * Render a BitTorrent infohash as a magnet link
	 *
	 * @param string $infohash
	 * @param string $name
	 * @param array $trackers
	 * @param string|null $cid IPFS CID for webseed URL
	 * @return string
	 */
	private function renderTorrentLink( string $infohash, string $name, array $trackers = [], array $webseeds = [], ?string $torrentUrl = null ): string {
		$magnetUri = "magnet:?xt=urn:btih:{$infohash}";
		if ( $name ) {
			$magnetUri .= "&dn=" . urlencode( $name );
		}
		foreach ( $trackers as $tracker ) {
			$magnetUri .= "&tr=" . urlencode( $tracker );
		}
		foreach ( $webseeds as $webseed ) {
			$magnetUri .= "&ws=" . urlencode( $webseed );
		}

		$links = Html::element( 'a', [ 'href' => $magnetUri ], '[magnet]' );
		if ( $torrentUrl ) {
			$links .= ' ' . Html::element( 'a', [ 'href' => $torrentUrl ], '[.torrent]' );
		}

		return Html::rawElement( 'span', [ 'class' => 'release-torrent-link' ],
			Html::element( 'code', [], $infohash ) . ' ' . $links
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
