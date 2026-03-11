<?php
/**
 * Hook handlers for PickiPediaReleases extension
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaReleases;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use Skin;

class Hooks implements LoadExtensionSchemaUpdatesHook, BeforePageDisplayHook {

	/**
	 * Handle schema updates
	 *
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): void {
		// No schema updates needed at this time
	}

	/**
	 * Inject delivery-kid auth config when viewing ReleaseDraft pages.
	 *
	 * The content handler loads the JS module, but HMAC tokens must be
	 * generated per-request with the current user's identity.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title || $title->getNamespace() !== NS_RELEASEDRAFT ) {
			return;
		}

		$config = $out->getConfig();
		$apiKey = $config->get( 'DeliveryKidApiKey' );
		$apiUrl = $config->get( 'DeliveryKidUrl' );

		if ( !$apiKey || !$apiUrl ) {
			return;
		}

		$user = $out->getUser();
		$username = $user->getName();
		$timestamp = (int)( microtime( true ) * 1000 );
		$token = hash_hmac( 'sha256', "upload:{$username}:{$timestamp}", $apiKey );

		$out->addJsConfigVars( [
			'wgDeliveryKidUrl' => $apiUrl,
			'wgUploadToken' => $token,
			'wgUploadUser' => $username,
			'wgUploadTimestamp' => $timestamp,
		] );
	}
}
