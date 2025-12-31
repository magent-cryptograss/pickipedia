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

## Error tracking with Sentry/GlitchTip
# DSN is set in LocalSettings.local.php as $wgSentryDsn
if ( !empty( $wgSentryDsn ) ) {
    \Sentry\init([
        'dsn' => $wgSentryDsn,
        'environment' => getenv('WIKI_DEV_MODE') === 'true' ? 'development' : 'production',
        'release' => $wgPickipediaBuildInfo['commit'] ?? 'unknown',
    ]);

    // Use MediaWiki's LogException hook to capture ALL exceptions, including
    // those caught internally by MediaWiki (like DBQueryError)
    $wgHooks['LogException'][] = function ( Throwable $e, bool $suppressed ) {
        \Sentry\captureException( $e );
    };

    // Also keep shutdown handler for fatal errors that bypass exception handling
    register_shutdown_function( function () {
        $error = error_get_last();
        if ( $error !== null && in_array( $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR] ) ) {
            \Sentry\captureException( new \ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            ) );
        }
    });
}

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

# Allow video uploads (HTML5 playback, no transcoding)
$wgFileExtensions = array_merge( $wgFileExtensions, ['mp4', 'webm', 'mov', 'ogv'] );

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

# TimedMediaHandler - video/audio playback with transcoding
wfLoadExtension( 'TimedMediaHandler' );
$wgFFmpegLocation = '/usr/local/bin/ffmpeg';

# HitCounters - page view statistics (installed via Composer)
wfLoadExtension( 'HitCounters' );

# RSS - embed RSS feeds in wiki pages
wfLoadExtension( 'RSS' );
$wgRSSUrlWhitelist = array( "*" );

## Email (disabled by default)
$wgEnableEmail = false;
$wgEnableUserEmail = false;

## Debugging (disable in production)
$wgShowExceptionDetails = false;
$wgShowDBErrorBacktrace = false;
$wgShowSQLErrors = false;
$wgDevelopmentWarnings = (getenv('WIKI_DEV_MODE') === 'true');

# Suppress deprecation warnings in production (SMW 6.0 has some with MW 1.45)
if (getenv('WIKI_DEV_MODE') !== 'true') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

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
