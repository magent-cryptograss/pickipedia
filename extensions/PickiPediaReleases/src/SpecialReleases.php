<?php
/**
 * Special page listing all releases
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialReleases extends SpecialPage {

	public function __construct() {
		parent::__construct( 'Releases' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$out = $this->getOutput();
		$out->addModuleStyles( [ 'ext.pickipediaReleases.styles' ] );

		// Editable header: MediaWiki:special-releases-header
		$this->addWikitextMessage( 'special-releases-header' );

		$releases = $this->getReleases();

		$out->addHTML( Html::element( 'p', [],
			count( $releases ) . ' releases in the catalog.'
		) );

		// Editable mid-section: MediaWiki:special-releases-mid
		$this->addWikitextMessage( 'special-releases-mid' );

		$out->addHTML( $this->renderTable( $releases ) );

		// Editable footer: MediaWiki:special-releases-footer
		$this->addWikitextMessage( 'special-releases-footer' );
	}

	/**
	 * Parse and output a MediaWiki message as wikitext, if it exists and is non-empty.
	 *
	 * @param string $msgKey
	 */
	private function addWikitextMessage( string $msgKey ): void {
		$msg = $this->msg( $msgKey );
		if ( !$msg->isDisabled() ) {
			$this->getOutput()->addWikiTextAsInterface( $msg->plain() );
		}
	}

	/**
	 * Get all releases with metadata and backlink counts.
	 *
	 * @return array
	 */
	private function getReleases(): array {
		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$wikiPageFactory = $services->getWikiPageFactory();
		$titleFactory = $services->getTitleFactory();

		$nsRelease = defined( 'NS_RELEASE' ) ? NS_RELEASE : 3004;

		$result = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_title' ] )
			->from( 'page' )
			->where( [
				'page_namespace' => $nsRelease,
				'page_is_redirect' => 0,
			] )
			->orderBy( 'page_title' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$releases = [];

		foreach ( $result as $row ) {
			$title = $titleFactory->makeTitle( $nsRelease, $row->page_title );
			$wikiPage = $wikiPageFactory->newFromTitle( $title );
			$content = $wikiPage->getContent();

			$data = [];
			if ( $content instanceof ReleaseContent ) {
				$data = $content->getData();
			}

			// Count backlinks
			$backlinkCount = $dbr->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'pagelinks' )
				->join( 'linktarget', null, 'pl_target_id = lt_id' )
				->where( [
					'lt_namespace' => $nsRelease,
					'lt_title' => $row->page_title,
				] )
				->caller( __METHOD__ )
				->fetchField();

			$releases[] = [
				'page_title' => $row->page_title,
				'title_obj' => $title,
				'display_title' => $data['title'] ?? null,
				'description' => $data['description'] ?? null,
				'file_type' => $data['file_type'] ?? null,
				'file_size' => isset( $data['file_size'] ) ? (int)$data['file_size'] : null,
				'pinned_on' => $data['pinned_on'] ?? null,
				'backlinks' => (int)$backlinkCount,
			];
		}

		return $releases;
	}

	/**
	 * Render the releases table.
	 *
	 * @param array $releases
	 * @return string
	 */
	private function renderTable( array $releases ): string {
		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable releases-list' ] );

		// Header
		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [], 'CID' );
		$html .= Html::element( 'th', [], 'Title' );
		$html .= Html::element( 'th', [], 'Type' );
		$html .= Html::element( 'th', [], 'Pinned On' );
		$html .= Html::element( 'th', [], 'References' );
		$html .= Html::closeElement( 'tr' );

		foreach ( $releases as $release ) {
			$html .= $this->renderRow( $release );
		}

		$html .= Html::closeElement( 'table' );
		return $html;
	}

	/**
	 * Render a single release row.
	 *
	 * @param array $release
	 * @return string
	 */
	private function renderRow( array $release ): string {
		$html = Html::openElement( 'tr' );

		// CID — truncated, linked to the Release page
		$cid = $release['page_title'];
		$shortCid = substr( $cid, 0, 12 ) . '…';
		$html .= Html::rawElement( 'td', [ 'class' => 'release-cid-cell' ],
			Html::element( 'a', [
				'href' => $release['title_obj']->getLocalURL(),
				'title' => $cid,
			], $shortCid )
		);

		// Title / description
		$displayText = $release['display_title'] ?? '';
		if ( !$displayText && $release['description'] ) {
			$displayText = $release['description'];
		}
		$html .= Html::element( 'td', [], $displayText );

		// File type
		$fileType = $release['file_type'] ?? '';
		if ( $release['file_size'] ) {
			$fileType .= ' · ' . $this->formatFileSize( $release['file_size'] );
		}
		$html .= Html::element( 'td', [], $fileType );

		// Pinned on
		$pinnedOn = '';
		if ( !empty( $release['pinned_on'] ) ) {
			$pinnedOn = is_array( $release['pinned_on'] )
				? implode( ', ', $release['pinned_on'] )
				: (string)$release['pinned_on'];
		}
		$html .= Html::element( 'td', [], $pinnedOn );

		// Backlinks count
		$html .= Html::element( 'td', [ 'style' => 'text-align:center;' ],
			$release['backlinks'] > 0 ? (string)$release['backlinks'] : ''
		);

		$html .= Html::closeElement( 'tr' );
		return $html;
	}

	/**
	 * Format file size in human-readable format.
	 *
	 * @param int $bytes
	 * @return string
	 */
	private function formatFileSize( int $bytes ): string {
		$units = [ 'B', 'KB', 'MB', 'GB' ];
		$size = (float)$bytes;
		$i = 0;
		while ( $size >= 1024 && $i < count( $units ) - 1 ) {
			$size /= 1024;
			$i++;
		}
		return round( $size, 1 ) . ' ' . $units[$i];
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'pages';
	}
}
