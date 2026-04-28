<?php
/**
 * Special page rendering the most recent delivery-kid storage audit.
 *
 * Audit data is produced by maybelle's host cron, scp'd to delivery-kid,
 * and served as JSON at $wgPickiPediaAuditUrl. This page fetches and
 * renders it on view — no wiki edits, no recent-changes noise.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialDeliveryKidAudit extends SpecialPage {

	/** Ethereum merge constants — match Special:Deliver* and audit producer. */
	private const MERGE_BLOCK = 15537394;
	private const MERGE_TIMESTAMP = 1663224179;
	private const SLOT_TIME = 12;

	private const CACHE_TTL = 60;

	public function __construct() {
		parent::__construct( 'DeliveryKidAudit' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$out = $this->getOutput();

		$config = $this->getConfig();
		$url = $config->get( 'DeliveryKidAuditUrl' );

		$payload = $this->fetchPayload( $url );
		if ( $payload === null ) {
			$out->addHTML( $this->errorBox(
				"Couldn't fetch the audit data from <code>"
				. htmlspecialchars( $url )
				. "</code>. delivery-kid may be unreachable."
			) );
			return;
		}

		$out->addHTML( $this->renderHeader( $payload ) );
		$out->addHTML( $this->renderBanner( $payload['problems'] ?? [] ) );
		$out->addHTML( $this->renderAuditText( $payload['audit_text'] ?? '' ) );
	}

	private function fetchPayload( string $url ): ?array {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();
		$cacheKey = $cache->makeKey( 'pickipedia-delivery-kid-audit', md5( $url ) );

		return $cache->getWithSetCallback(
			$cacheKey,
			self::CACHE_TTL,
			function () use ( $services, $url ) {
				$factory = $services->getHttpRequestFactory();
				$body = $factory->get( $url, [ 'timeout' => 5 ] );
				if ( $body === null ) {
					return false;
				}
				$decoded = json_decode( $body, true );
				return is_array( $decoded ) ? $decoded : false;
			}
		) ?: null;
	}

	private function renderHeader( array $payload ): string {
		$blockheight = (int)( $payload['blockheight'] ?? 0 );
		$timestamp = $payload['timestamp_utc'] ?? '';
		$rc = (int)( $payload['returncode'] ?? 0 );

		$currentBlock = self::currentBlockheight();
		$blocksAgo = max( 0, $currentBlock - $blockheight );
		$secondsAgo = $blocksAgo * self::SLOT_TIME;
		$ageHuman = self::formatAge( $secondsAgo );

		$status = $rc === 0
			? 'OK'
			: "audit script exited {$rc}";

		$html = Html::openElement( 'div',
			[ 'class' => 'pickipedia-audit-header' ] );
		$html .= Html::element( 'p', [],
			"Last audit: {$blocksAgo} blocks ago (~{$ageHuman}), at "
			. "Ethereum block {$blockheight} ({$timestamp}) — {$status}."
		);
		$html .= Html::closeElement( 'div' );
		return $html;
	}

	private function renderBanner( array $problems ): string {
		if ( $problems ) {
			$items = '';
			foreach ( $problems as $label => $count ) {
				$items .= Html::rawElement( 'li', [],
					Html::element( 'strong', [], (string)$label )
					. ': ' . htmlspecialchars( (string)$count )
				);
			}
			return Html::rawElement( 'div',
				[ 'style' => 'background:#fef6e7; border:2px solid #ac6600; '
					. 'padding:0.75em 1em; margin:1em 0; border-radius:4px;' ],
				Html::element( 'strong', [], '⚠ Action required' )
				. Html::rawElement( 'ul', [], $items )
			);
		}
		return Html::rawElement( 'div',
			[ 'style' => 'background:#d5fdf4; border:2px solid #14866d; '
				. 'padding:0.75em 1em; margin:1em 0; border-radius:4px;' ],
			Html::element( 'strong', [], '✓ All clear' )
			. ' — no problems detected.'
		);
	}

	private function renderAuditText( string $text ): string {
		$linkified = self::linkify( $text );
		// addWikiTextAsInterface — leading-space pre block processes [[links]].
		$indented = preg_replace( '/^/m', ' ', $linkified );
		$out = $this->getOutput();
		$html = Html::element( 'h2', [], 'Full audit output' );
		$out->addHTML( $html );
		$out->addWikiTextAsInterface( $indented );
		return '';
	}

	private function errorBox( string $messageHtml ): string {
		return Html::rawElement( 'div',
			[ 'style' => 'background:#fee; border:2px solid #c33; '
				. 'padding:0.75em 1em; margin:1em 0; border-radius:4px;' ],
			Html::element( 'strong', [], 'Audit unavailable' )
			. ' — ' . $messageHtml
		);
	}

	/**
	 * Wrap recognized page references in MediaWiki link syntax.
	 *
	 * Mirrors linkify_audit() in post-audit-to-wiki.py — kept in sync so
	 * the wiki Special page produces the same links as the wiki posts did.
	 */
	private static function linkify( string $text ): string {
		// UUID4 → ReleaseDraft:<uuid>
		$text = preg_replace(
			'/\b([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\b/',
			'[[ReleaseDraft:$1|$1]]',
			$text
		);
		// CID (bafy… or Qm…) → Release:<cid>
		$text = preg_replace(
			'/\b((?:[Bb]afy[a-zA-Z0-9]{50,60}|[Qq]m[a-zA-Z0-9]{44}))\b/',
			'[[Release:$1|$1]]',
			$text
		);
		return $text;
	}

	private static function currentBlockheight(): int {
		return self::MERGE_BLOCK
			+ intdiv( time() - self::MERGE_TIMESTAMP, self::SLOT_TIME );
	}

	private static function formatAge( int $seconds ): string {
		if ( $seconds < 60 ) {
			return "{$seconds}s";
		}
		if ( $seconds < 3600 ) {
			return intdiv( $seconds, 60 ) . 'm';
		}
		if ( $seconds < 86400 ) {
			$h = intdiv( $seconds, 3600 );
			$m = intdiv( $seconds % 3600, 60 );
			return $m === 0 ? "{$h}h" : "{$h}h {$m}m";
		}
		$d = intdiv( $seconds, 86400 );
		$h = intdiv( $seconds % 86400, 3600 );
		return $h === 0 ? "{$d}d" : "{$d}d {$h}h";
	}
}
