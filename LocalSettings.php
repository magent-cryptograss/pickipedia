<?php
/**
 * PickiPedia - Traditional Music Knowledge Base
 *
 * This file is tracked in version control.
 * Secrets are loaded from LocalSettings.local.php (not tracked).
 */

# Protect against web entry
if ( !defined( 'MEDIAWIKI' ) ) {
    exit;
}

## Load secrets from local config
require_once __DIR__ . '/LocalSettings.local.php';

## Site identity
$wgSitename = getenv('WIKI_NAME') ?: "PickiPedia";
$wgMetaNamespace = "PickiPedia";

## URLs
$wgServer = getenv('WIKI_URL') ?: "https://pickipedia.xyz";
$wgScriptPath = "";
$wgArticlePath = "/wiki/$1";
$wgUsePathInfo = true;

## Database settings (from LocalSettings.local.php)
# $wgDBtype, $wgDBserver, $wgDBname, $wgDBuser, $wgDBpassword
# are set in LocalSettings.local.php

$wgDBprefix = "";
$wgDBTableOptions = "ENGINE=InnoDB, DEFAULT CHARSET=binary";

## Shared memory / caching
$wgMainCacheType = CACHE_ACCEL;
$wgMemCachedServers = [];

## File uploads
$wgEnableUploads = true;
$wgUploadPath = "$wgScriptPath/images";
$wgUploadDirectory = "$IP/images";
$wgUseImageMagick = false;
# GD library is used for thumbnails instead
$wgMaxImageArea = 50e6;  # 50 megapixels (default is 12.5MP)

# Allow uploads from URLs (e.g., Instagram, external sources)
$wgAllowCopyUploads = true;
$wgCopyUploadsFromSpecialUpload = true;
$wgGroupPermissions['user']['upload_by_url'] = true;

## InstantCommons allows wiki to use images from commons.wikimedia.org
$wgUseInstantCommons = true;

## Logos
$wgLogos = [
    '1x' => "$wgResourceBasePath/assets/logo.png",
];

## Skins
wfLoadSkin( 'MonoBook' );
wfLoadSkin( 'Vector' );
$wgDefaultSkin = "monobook";

## Rights
$wgRightsPage = "";
$wgRightsUrl = "";
$wgRightsText = "";
$wgRightsIcon = "";

## Permissions
$wgGroupPermissions['*']['createaccount'] = false;
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['read'] = true;

## Extensions

# Semantic MediaWiki (installed via Composer)
wfLoadExtension( 'SemanticMediaWiki' );
enableSemantics( parse_url($wgServer, PHP_URL_HOST) );

# YouTube - for embedding YouTube videos
wfLoadExtension( 'YouTube' );

# ParserFunctions - {{#if:}}, {{#switch:}}, etc. for templates (bundled with MediaWiki)
wfLoadExtension( 'ParserFunctions' );

# WikiEditor - enhanced editing toolbar (bundled with MediaWiki)
wfLoadExtension( 'WikiEditor' );

# MultimediaViewer - modern lightbox for images (bundled with MediaWiki)
wfLoadExtension( 'MultimediaViewer' );

# MsUpload - drag-and-drop multiple file upload in edit page
wfLoadExtension( 'MsUpload' );
$wgMSU_useDragDrop = true;
$wgMSU_showAutoCat = true;
$wgMSU_checkAutoCat = true;
$wgMSU_imgParams = '400px';
$wgMSU_uploadsize = '100mb';

## Email (disabled by default)
$wgEnableEmail = false;
$wgEnableUserEmail = false;

## Debugging (disable in production)
$wgShowExceptionDetails = false;
$wgShowDBErrorBacktrace = false;
$wgShowSQLErrors = false;

## Build info footer (generated at build time)
if ( file_exists( __DIR__ . '/build-info.php' ) ) {
    require_once __DIR__ . '/build-info.php';
    if ( isset( $wgPickipediaBuildInfo ) && $wgPickipediaBuildInfo['blockheight'] > 0 ) {
        $blockheight = number_format( $wgPickipediaBuildInfo['blockheight'] );
        $commit = $wgPickipediaBuildInfo['commit'];
        $wgFooterIcons['poweredby']['pickipedia-build'] = [
            'src' => '',
            'url' => "https://etherscan.io/block/{$wgPickipediaBuildInfo['blockheight']}",
            'alt' => "Built at block {$blockheight}",
            'height' => false,
            'width' => false,
        ];
        // Also add to site notice / footer text
        $wgHooks['SkinAfterContent'][] = function ( &$data, $skin ) use ( $blockheight, $commit ) {
            $data .= "<div style='text-align: center; font-size: 0.8em; color: #666; padding: 5px;'>"
                . "Built at Ethereum block <a href='https://etherscan.io/block/{$GLOBALS['wgPickipediaBuildInfo']['blockheight']}'>{$blockheight}</a>"
                . " | commit {$commit}"
                . "</div>";
            return true;
        };
    }
}
