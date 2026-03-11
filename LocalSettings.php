<?php
/**
 * PickiPedia - Traditional Music Knowledge Base
 *
 * This file is tracked in version control.
 * Secrets are loaded from LocalSettings.local.php (not tracked).
 */

# Suppress deprecation warnings early to prevent them from corrupting
# ResourceLoader JS output (EmbedVideo extension has compatibility issues)
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

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

## Custom namespaces
# Cryptograss namespace for infrastructure and project documentation
define( "NS_CRYPTOGRASS", 3000 );
define( "NS_CRYPTOGRASS_TALK", 3001 );
$wgExtraNamespaces[NS_CRYPTOGRASS] = "Cryptograss";
$wgExtraNamespaces[NS_CRYPTOGRASS_TALK] = "Cryptograss_talk";

# BlueRailroad namespace for NFT token pages
define( "NS_BLUERAILROAD", 3002 );
define( "NS_BLUERAILROAD_TALK", 3003 );
$wgExtraNamespaces[NS_BLUERAILROAD] = "BlueRailroad";
$wgExtraNamespaces[NS_BLUERAILROAD_TALK] = "BlueRailroad_talk";

# Make Cryptograss namespace searchable by default
$wgNamespacesToBeSearchedDefault[NS_CRYPTOGRASS] = true;
$wgNamespacesToBeSearchedDefault[NS_BLUERAILROAD] = true;

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
# Disable strict MIME verification for iPhone HEVC videos (mov containers)
$wgVerifyMimeType = false;

## InstantCommons allows wiki to use images from commons.wikimedia.org
$wgUseInstantCommons = true;

## Logos
$wgLogos = [
    '1x' => "$wgResourceBasePath/assets/logo.png",
];
$wgFavicon = "$wgResourceBasePath/assets/favicon.ico";

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
# Account creation requires an invite code (handled by PickiPediaInvitations extension)
$wgGroupPermissions['*']['createaccount'] = true;
# Anonymous users can read but not edit
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['read'] = true;
# Allow sysops to merge/delete users (for bot cleanup)
$wgGroupPermissions['sysop']['usermerge'] = true;
# Release group - users who can add trusted IPFS/torrent content to delivery-kid
$wgGroupPermissions['release']['edit'] = true;

# Restrict Release namespace to users in the 'release' group
# NS_RELEASE (3004) is defined by the PickiPediaReleases extension
$wgNamespaceProtection[3004] = ['release-edit'];
$wgGroupPermissions['release']['release-edit'] = true;

# Restrict ReleaseDraft namespace (3006) to users with upload-to-delivery-kid permission
$wgNamespaceProtection[3006] = ['upload-to-delivery-kid'];

# Make release-edit available as a BotPasswords grant
$wgGrantPermissions['release-edit']['release-edit'] = true;
$wgGrantPermissionGroups['release-edit'] = 'other';

## Extensions

# Semantic MediaWiki (installed via Composer)
wfLoadExtension( 'SemanticMediaWiki' );
enableSemantics( parse_url($wgServer, PHP_URL_HOST) );

# Enable SMW semantic links for Cryptograss namespace
$smwgNamespacesWithSemanticLinks[NS_CRYPTOGRASS] = true;

# Enable semantic links for Release namespace (after PickiPediaReleases is loaded)
# Note: NS_RELEASE (3004) and NS_RELEASEDRAFT (3006) are defined by PickiPediaReleases
$smwgNamespacesWithSemanticLinks[3004] = true;
$smwgNamespacesWithSemanticLinks[3006] = true;

# Page Forms - create forms for SMW data entry (installed via Composer)
wfLoadExtension( 'PageForms' );

# YouTube - for embedding YouTube videos
wfLoadExtension( 'YouTube' );

# ParserFunctions - {{#if:}}, {{#switch:}}, etc. for templates (bundled with MediaWiki)
wfLoadExtension( 'ParserFunctions' );
$wgPFEnableStringFunctions = true;  # Enable #explode, #sub, #len, etc. for parsing

# WikiEditor - enhanced editing toolbar (bundled with MediaWiki)
wfLoadExtension( 'WikiEditor' );

# LinkSuggest - autocomplete when typing [[ or {{ in standard editor
wfLoadExtension( 'LinkSuggest' );

# VisualEditor - rich editing experience (available, but source editing is default)
wfLoadExtension( 'VisualEditor' );
$wgVisualEditorParsoidAutoConfig = true;  # Parsoid runs in-process (MW 1.35+)
$wgDefaultUserOptions['visualeditor-enable'] = 1;  # VE available
$wgDefaultUserOptions['visualeditor-newwikitext'] = 0;  # Use classic WikiEditor for source, not 2017 wikitext
$wgDefaultUserOptions['visualeditor-editor'] = 'wikitext';  # Default to source editing
$wgDefaultUserOptions['usebetatoolbar'] = 0;  # Disable WikiEditor toolbar
$wgVisualEditorUseSingleEditTab = false;  # Show both "Edit" and "Edit source" tabs
$wgVisualEditorAvailableNamespaces = [
    NS_MAIN => true,
    NS_USER => true,
    NS_PROJECT => true,
    NS_TEMPLATE => true,
    NS_HELP => true,
    NS_CATEGORY => true,
    NS_CRYPTOGRASS => true,
    NS_BLUERAILROAD => true,
];

# CodeMirror - syntax highlighting in the editor
# DISABLED in test environment (not in Docker image)
# wfLoadExtension( 'CodeMirror' );
# $wgDefaultUserOptions['usecodemirror'] = 1;  # Enable by default for all users

# MultimediaViewer - modern lightbox for images (bundled with MediaWiki)
wfLoadExtension( 'MultimediaViewer' );

# MsUpload - drag-and-drop multiple file upload in edit page
wfLoadExtension( 'MsUpload' );
$wgMSU_useDragDrop = true;
$wgMSU_showAutoCat = true;
$wgMSU_checkAutoCat = true;
$wgMSU_imgParams = '400px';
$wgMSU_uploadsize = '1024mb';
$wgMaxUploadSize = 1024 * 1024 * 1024;  // 1GB in bytes

# TimedMediaHandler - video/audio playback with transcoding
wfLoadExtension( 'TimedMediaHandler' );
$wgFFmpegLocation = '/usr/local/bin/ffmpeg';

# HitCounters - page view statistics (installed via Composer)
wfLoadExtension( 'HitCounters' );

# RSS - embed RSS feeds in wiki pages
wfLoadExtension( 'RSS' );
$wgRSSUrlWhitelist = array( "*" );

# Gadgets - user-customizable JavaScript/CSS tools
wfLoadExtension( 'Gadgets' );

# PickiPediaVerification - enforce verification workflow for bot edits
# Also provides Special:VerifyBotEdits for bulk verification
wfLoadExtension( 'PickiPediaVerification' );

# Exempt trusted bots from verification requirement
# Add bot accounts to this group via Special:UserRights
$wgGroupPermissions['exempt-from-verification']['read'] = true;
$wgPickiPediaVerificationExemptGroups = ['exempt-from-verification'];

# RambutanMode - adds "Rambutan" as a middle name/alias to person and band articles
# Users can toggle via sidebar; auto-disables at midnight Florida time
wfLoadExtension( 'RambutanMode' );

# PickiPediaInvitations - gate account creation behind invite codes
# Creates an accountability chain via EntityAttestation pages
wfLoadExtension( 'PickiPediaInvitations' );

# EmbedVideo - embed external video files (MP4, etc.)
wfLoadExtension( 'EmbedVideo' );

# PickiPediaReleases - Release namespace with YAML content model
# Stores IPFS CIDs and BitTorrent infohashes for pinning service sync
wfLoadExtension( 'PickiPediaReleases' );
$wgDeliveryKidUrl = getenv('DELIVERY_KID_URL') ?: 'https://delivery-kid.cryptograss.live';
if ( getenv('DELIVERY_KID_API_KEY') ) {
	$wgDeliveryKidApiKey = getenv('DELIVERY_KID_API_KEY');
}

# Echo - notifications for talk page messages, mentions, watchlist changes
wfLoadExtension( 'Echo' );

# Thanks - thank editors for contributions
wfLoadExtension( 'Thanks' );

# UserMerge - merge and delete user accounts (for bot cleanup)
# DISABLED: Not in Docker image
# wfLoadExtension( 'UserMerge' );

# WikiSEO - social sharing cards and SEO meta tags (installed via Composer)
# DISABLED in test environment (not in Docker image)
# wfLoadExtension( 'WikiSEO' );
# $wgWikiSeoDefaultImage = "$wgServer/w/images/pickipedia-card.png";
# $wgTwitterSiteHandle = "@cryptograss";

# Add custom 'videolink' service for direct video URLs (MP4 or IPFS gateway)
$wgHooks['SetupAfterCache'][] = function() {
    \EmbedVideo\VideoService::addService('videolink', [
        'embed' => '<video width="%2$d" controls><source src="%1$s" type="video/mp4">Your browser does not support video.</video>',
        'default_width' => 320,
        'default_ratio' => 1.77777777777778,
        'https_enabled' => true,
        'url_regex' => ['#^(https?://.+)$#is'],
        'id_regex' => ['#^(https?://.+)$#is']
    ]);
};

## Email (disabled by default)
$wgEnableEmail = false;
$wgEnableUserEmail = false;

## Debugging (disable in production)
$wgShowExceptionDetails = false;
$wgShowDBErrorBacktrace = false;
$wgShowSQLErrors = false;
$wgDevelopmentWarnings = (getenv('WIKI_DEV_MODE') === 'true');

# Note: Deprecation warnings are suppressed at the top of this file to prevent
# them from corrupting ResourceLoader JS output (EmbedVideo has compatibility issues)

## Allow embedding in iframes (for rabbithole integration)
$wgEditPageFrameOptions = false;

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
