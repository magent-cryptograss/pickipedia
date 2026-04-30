<?php
/**
 * Hook handlers for PickiPediaRecordingMetadata.
 *
 * Detects /Metadata subpages of the Release: namespace and routes them
 * to the recording-metadata-yaml content model.
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\PickiPediaRecordingMetadata;

use MediaWiki\Hook\ContentHandlerDefaultModelForHook;

class Hooks implements ContentHandlerDefaultModelForHook {

	// NS_RELEASE is registered by PickiPediaReleases (id 3004). We refer to
	// it by literal id rather than constant so this hook works regardless
	// of extension load order.
	private const NS_RELEASE = 3004;

	/**
	 * Route Release:*\/Metadata pages to recording-metadata-yaml.
	 *
	 * Other pages in Release: continue to use the namespace default
	 * (release-yaml), and pages outside Release: are untouched.
	 *
	 * @param \Title $title
	 * @param string &$model
	 * @return bool|void
	 */
	public function onContentHandlerDefaultModelFor( $title, &$model ) {
		if ( $title->getNamespace() !== self::NS_RELEASE ) {
			return;
		}
		if ( str_ends_with( $title->getDBkey(), '/Metadata' ) ) {
			$model = 'recording-metadata-yaml';
			return false;
		}
	}
}
