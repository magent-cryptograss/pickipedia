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
$wgUseImageMagick = true;
$wgImageMagickConvertCommand = "/usr/bin/convert";

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

# Semantic MediaWiki
wfLoadExtension( 'SemanticMediaWiki' );
enableSemantics( parse_url($wgServer, PHP_URL_HOST) );

# YouTube - for embedding YouTube videos
wfLoadExtension( 'YouTube' );

## Email (disabled by default)
$wgEnableEmail = false;
$wgEnableUserEmail = false;

## Debugging (disable in production)
$wgShowExceptionDetails = false;
$wgShowDBErrorBacktrace = false;
$wgShowSQLErrors = false;
