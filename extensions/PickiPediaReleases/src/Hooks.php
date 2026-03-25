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
	 * Inject delivery-kid auth config when viewing ReleaseDraft pages
	 * or any page that uses the DeliveryKidUpload form input.
	 *
	 * Upload tokens (for status checks and re-uploads) are given to all
	 * logged-in users. Finalize tokens are only given to users with the
	 * 'finalize-release' permission.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		$isReleaseDraft = $title && $title->getNamespace() === NS_RELEASEDRAFT;

		// Also inject tokens on Special:FormEdit pages and any page using
		// the deliveryKidInput module (PageForms forms).
		$modules = $out->getModules();
		$hasDeliveryKidInput = in_array( 'ext.pickipediaReleases.deliveryKidInput', $modules );

		if ( !$isReleaseDraft && !$hasDeliveryKidInput ) {
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

		// Upload token — any logged-in user can upload to staging
		$uploadToken = hash_hmac( 'sha256', "upload:{$username}:{$timestamp}", $apiKey );

		$jsVars = [
			'wgDeliveryKidUrl' => $apiUrl,
			'wgUploadToken' => $uploadToken,
			'wgUploadUser' => $username,
			'wgUploadTimestamp' => $timestamp,
			'wgCanFinalize' => false,
		];

		// Finalize token — only for users with finalize-release permission
		if ( $isReleaseDraft ) {
			$canFinalize = $user->isAllowed( 'finalize-release' );
			if ( $canFinalize ) {
				$finalizeToken = hash_hmac( 'sha256', "finalize:{$username}:{$timestamp}", $apiKey );
				$jsVars['wgFinalizeToken'] = $finalizeToken;
				$jsVars['wgCanFinalize'] = true;
			}
		}

		$out->addJsConfigVars( $jsVars );
	}

	/**
	 * Register the DeliveryKidUpload input type with PageForms.
	 *
	 * @param \PFFormPrinter &$formPrinter
	 */
	public function onPageForms__FormPrinterSetup( &$formPrinter ): void {
		$formPrinter->registerInputType(
			'MediaWiki\\Extension\\PickiPediaReleases\\DeliveryKidUploadInput'
		);
	}
}
