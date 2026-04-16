<?php
/**
 * Special page listing all release drafts with status and metadata
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialReleaseDrafts extends SpecialPage {

	private const NS_RELEASEDRAFT = 3006;
	private const NS_RELEASE = 3004;

	public function __construct() {
		parent::__construct( 'ReleaseDrafts' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$out = $this->getOutput();
		$out->addModuleStyles( [ 'ext.pickipediaReleases.styles' ] );

		$drafts = $this->getDrafts();

		$out->addHTML( Html::element( 'p', [],
			count( $drafts ) . ( count( $drafts ) === 1 ? ' draft' : ' drafts' ) . ' total.'
		) );

		if ( !$drafts ) {
			$out->addHTML( Html::element( 'p', [ 'class' => 'mw-message-box' ],
				'No release drafts found.' ) );
			return;
		}

		// Separate by status
		$complete = [];
		$pending = [];
		foreach ( $drafts as $d ) {
			if ( $d['status'] === 'finalized' ) {
				$complete[] = $d;
			} else {
				$pending[] = $d;
			}
		}

		if ( $pending ) {
			$out->addHTML( Html::element( 'h2', [], 'Pending Drafts' ) );
			$out->addHTML( Html::element( 'p', [],
				count( $pending ) . ( count( $pending ) === 1 ? ' draft' : ' drafts' )
			) );
			$out->addHTML( $this->renderTable( $pending ) );
		}

		if ( $complete ) {
			$out->addHTML( Html::element( 'h2', [], 'Finalized Drafts' ) );
			$out->addHTML( Html::element( 'p', [],
				count( $complete ) . ( count( $complete ) === 1 ? ' draft' : ' drafts' )
			) );
			$out->addHTML( $this->renderTable( $complete ) );
		}
	}

	/**
	 * Fetch all ReleaseDraft pages with parsed metadata and status.
	 *
	 * @return array
	 */
	private function getDrafts(): array {
		$services = MediaWikiServices::getInstance();
		$dbr = $services->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$wikiPageFactory = $services->getWikiPageFactory();
		$titleFactory = $services->getTitleFactory();

		$result = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_title', 'page_touched' ] )
			->from( 'page' )
			->where( [
				'page_namespace' => self::NS_RELEASEDRAFT,
				'page_is_redirect' => 0,
			] )
			->orderBy( 'page_touched', 'DESC' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$drafts = [];

		foreach ( $result as $row ) {
			$title = $titleFactory->makeTitle( self::NS_RELEASEDRAFT, $row->page_title );
			$wikiPage = $wikiPageFactory->newFromTitle( $title );
			$content = $wikiPage->getContent();

			$data = [];
			if ( $content instanceof ReleaseDraftContent ) {
				$data = $content->getData();
			}

			// Determine status by checking edit history for finalization
			$status = 'draft';
			$cid = null;
			$cid = $this->findCidFromHistory( $dbr, $row->page_id );
			if ( $cid ) {
				$status = 'finalized';
			}

			// Check if a Release page exists for this CID
			$hasRelease = false;
			if ( $cid ) {
				$releaseTitle = $titleFactory->makeTitle( self::NS_RELEASE, $cid );
				$hasRelease = $releaseTitle->exists();
			}

			// Extract content metadata
			$contentBlock = $data['content'] ?? [];
			$files = $data['files'] ?? [];
			$firstFile = $files[0] ?? [];

			$drafts[] = [
				'page_title' => $row->page_title,
				'title_obj' => $title,
				'draft_id' => $data['draft_id'] ?? null,
				'type' => $data['type'] ?? 'unknown',
				'source' => $data['source'] ?? null,
				'uploader' => $data['uploader'] ?? null,
				'display_title' => $contentBlock['title'] ?? null,
				'description' => $contentBlock['description'] ?? null,
				'file_type' => $contentBlock['file_type'] ?? $firstFile['media_type'] ?? null,
				'file_count' => count( $files ),
				'total_size' => $this->sumFileSizes( $files ),
				'video_codec' => $firstFile['video_codec'] ?? null,
				'resolution' => $this->formatResolution( $firstFile ),
				'blockheight' => $data['blockheight'] ?? null,
				'upload_blockheight' => $data['upload_blockheight'] ?? null,
				'commit' => $data['commit'] ?? null,
				'status' => $status,
				'cid' => $cid,
				'has_release' => $hasRelease,
				'touched' => $row->page_touched,
			];
		}

		return $drafts;
	}

	/**
	 * Find the finalization CID from page revision comments.
	 *
	 * @param \Wikimedia\Rdbms\IReadableDatabase $dbr
	 * @param int $pageId
	 * @return string|null
	 */
	private function findCidFromHistory( $dbr, int $pageId ): ?string {
		$result = $dbr->newSelectQueryBuilder()
			->select( [ 'comment_text' ] )
			->from( 'revision' )
			->join( 'comment', null, 'rev_comment_id = comment_id' )
			->where( [ 'rev_page' => $pageId ] )
			->orderBy( 'rev_id', 'DESC' )
			->limit( 20 )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $result as $row ) {
			if ( preg_match( '/pinned to IPFS as (\S+)/', $row->comment_text, $m ) ) {
				return $m[1];
			}
		}

		return null;
	}

	/**
	 * Sum file sizes from the files array.
	 *
	 * @param array $files
	 * @return int
	 */
	private function sumFileSizes( array $files ): int {
		$total = 0;
		foreach ( $files as $f ) {
			$total += (int)( $f['size_bytes'] ?? 0 );
		}
		return $total;
	}

	/**
	 * Format resolution from a file entry.
	 *
	 * @param array $file
	 * @return string|null
	 */
	private function formatResolution( array $file ): ?string {
		$w = $file['width'] ?? null;
		$h = $file['height'] ?? null;
		if ( $w && $h ) {
			return "{$w}×{$h}";
		}
		return null;
	}

	/**
	 * Format bytes as human-readable string.
	 *
	 * @param int $bytes
	 * @return string
	 */
	private function formatSize( int $bytes ): string {
		if ( $bytes === 0 ) {
			return '—';
		}
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		if ( $bytes < 1048576 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		if ( $bytes < 1073741824 ) {
			return round( $bytes / 1048576, 2 ) . ' MB';
		}
		return round( $bytes / 1073741824, 2 ) . ' GB';
	}

	/**
	 * Render drafts as a table.
	 *
	 * @param array $drafts
	 * @return string HTML
	 */
	private function renderTable( array $drafts ): string {
		$html = Html::openElement( 'table', [
			'class' => 'wikitable sortable',
			'style' => 'width: 100%;',
		] );

		$html .= Html::openElement( 'tr' );
		$headers = [ 'Draft', 'Title', 'Type', 'Uploader', 'Files', 'Size',
			'Resolution', 'Status', 'Release' ];
		foreach ( $headers as $h ) {
			$html .= Html::element( 'th', [], $h );
		}
		$html .= Html::closeElement( 'tr' );

		foreach ( $drafts as $d ) {
			$html .= Html::openElement( 'tr' );

			// Draft link
			$draftLink = Html::element( 'a', [
				'href' => $d['title_obj']->getLocalURL(),
			], $d['draft_id'] ? substr( $d['draft_id'], 0, 12 ) . '…' : $d['page_title'] );
			$html .= Html::rawElement( 'td', [], $draftLink );

			// Display title
			$html .= Html::element( 'td', [],
				$d['display_title'] ?? '(untitled)' );

			// Type
			$html .= Html::element( 'td', [], $d['type'] );

			// Uploader
			$html .= Html::element( 'td', [], $d['uploader'] ?? '—' );

			// File count
			$html .= Html::element( 'td', [ 'style' => 'text-align: center;' ],
				$d['file_count'] > 0 ? (string)$d['file_count'] : '—' );

			// Total size
			$html .= Html::element( 'td', [
				'data-sort-value' => $d['total_size'],
			], $this->formatSize( $d['total_size'] ) );

			// Resolution
			$html .= Html::element( 'td', [],
				$d['resolution'] ?? '—' );

			// Status
			$statusClass = $d['status'] === 'finalized'
				? 'color: #2d5016; font-weight: bold;'
				: 'color: #856404;';
			$statusText = $d['status'];
			if ( $d['status'] === 'finalized' && $d['cid'] ) {
				$statusText .= ' (' . substr( $d['cid'], 0, 8 ) . '…)';
			}
			$html .= Html::element( 'td', [ 'style' => $statusClass ], $statusText );

			// Release page link
			$releaseHtml = '—';
			if ( $d['has_release'] && $d['cid'] ) {
				$releaseTitle = \MediaWiki\MediaWikiServices::getInstance()
					->getTitleFactory()
					->makeTitle( self::NS_RELEASE, $d['cid'] );
				$releaseHtml = Html::element( 'a', [
					'href' => $releaseTitle->getLocalURL(),
				], '✓ View' );
			} elseif ( $d['cid'] ) {
				$releaseHtml = Html::element( 'span', [
					'style' => 'color: #888;',
				], 'pending bot' );
			}
			$html .= Html::rawElement( 'td', [], $releaseHtml );

			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'table' );
		return $html;
	}
}
