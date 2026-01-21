<?php
/**
 * Import Blue Railroad token data from chain data JSON into Semantic MediaWiki
 *
 * Reads configuration from PickiPedia:BlueRailroadConfig wiki page to determine:
 * - Data sources (contract addresses, chain data keys)
 * - Leaderboards to generate (with optional filters)
 *
 * Creates/updates pages in the BlueRailroad namespace with SMW properties:
 * - Token ID, Owner address, Owner display, Video URI, Song ID, Date minted
 *
 * Usage: php importBlueRailroads.php
 */

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class ImportBlueRailroads extends Maintenance {

    /** @var string Config page title */
    private const CONFIG_PAGE = 'PickiPedia:BlueRailroadConfig';

    public function __construct() {
        parent::__construct();
        $this->addDescription('Import Blue Railroad tokens from chain data JSON into SMW');
        $this->addOption('dry-run', 'Show what would be imported without making changes', false, false);
        $this->addOption('chain-data', 'Path to chainData.json', false, true);
        $this->addOption('config-page', 'Wiki page with bot configuration (default: ' . self::CONFIG_PAGE . ')', false, true);
    }

    public function execute() {
        $dryRun = $this->hasOption('dry-run');
        $configPage = $this->getOption('config-page') ?: self::CONFIG_PAGE;

        // Read configuration from wiki
        $this->output("Reading configuration from: $configPage\n");
        $config = $this->readConfig($configPage);

        if (!$config) {
            $this->output("Warning: Could not read config page, using defaults\n");
            $config = $this->getDefaultConfig();
        }

        $this->output("  Found " . count($config['sources']) . " source(s)\n");
        $this->output("  Found " . count($config['leaderboards']) . " leaderboard(s)\n\n");

        // Find chain data file
        $chainDataPath = $this->getOption('chain-data');
        if (!$chainDataPath) {
            $chainDataPath = MW_INSTALL_PATH . '/chain-data/chainData.json';
        }

        if (!file_exists($chainDataPath)) {
            $this->fatalError("Chain data file not found: $chainDataPath");
        }

        $this->output("Reading chain data from: $chainDataPath\n");

        $chainDataJson = file_get_contents($chainDataPath);
        $chainData = json_decode($chainDataJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fatalError("Failed to parse JSON: " . json_last_error_msg());
        }

        // Process each source
        foreach ($config['sources'] as $source) {
            $chainDataKey = $source['chain_data_key'] ?? 'blueRailroads';

            if (!isset($chainData[$chainDataKey])) {
                $this->error("No '$chainDataKey' data found in chain data, skipping source");
                continue;
            }

            $blueRailroads = $chainData[$chainDataKey];
            $count = count($blueRailroads);
            $this->output("\nProcessing source: " . ($source['name'] ?? $chainDataKey) . " ($count tokens)\n");

            if ($dryRun) {
                $this->output("DRY RUN - no changes will be made\n\n");
            }

            // Import tokens
            $imported = 0;
            $updated = 0;
            $errors = 0;

            foreach ($blueRailroads as $tokenId => $token) {
                $result = $this->importToken($tokenId, $token, $dryRun);
                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $errors++;
                }
            }

            $this->output("\nToken Import Summary:\n");
            $this->output("  Imported: $imported\n");
            $this->output("  Updated: $updated\n");
            $this->output("  Errors: $errors\n");

            // Generate leaderboards from config
            $this->output("\nGenerating leaderboards...\n");
            foreach ($config['leaderboards'] as $leaderboard) {
                $this->generateLeaderboard($blueRailroads, $leaderboard, $dryRun);
            }
        }
    }

    /**
     * Read configuration from wiki page
     */
    private function readConfig($configPageTitle) {
        $title = Title::newFromText($configPageTitle);
        if (!$title || !$title->exists()) {
            return null;
        }

        $services = MediaWiki\MediaWikiServices::getInstance();
        $revisionLookup = $services->getRevisionLookup();
        $revision = $revisionLookup->getRevisionByTitle($title);

        if (!$revision) {
            return null;
        }

        $content = $revision->getContent('main');
        if (!$content) {
            return null;
        }

        $text = $content->getText();

        return $this->parseConfig($text);
    }

    /**
     * Parse configuration from wiki page content
     * Parses raw wikitext template calls like {{BlueRailroadLeaderboard|page=...|filter_song_id=...}}
     */
    private function parseConfig($text) {
        $config = [
            'sources' => [],
            'leaderboards' => []
        ];

        // Strip content inside <pre> tags to avoid matching example/documentation templates
        $textWithoutPre = preg_replace('/<pre>.*?<\/pre>/s', '', $text);

        // Parse {{BlueRailroadSource|...}} template calls from raw wikitext
        // Match template calls, handling newlines within the template
        preg_match_all('/\{\{BlueRailroadSource\s*\n?((?:[^{}]|\{[^{]|\}[^}])*)\}\}/s', $textWithoutPre, $sourceMatches);
        foreach ($sourceMatches[1] as $sourceStr) {
            $source = $this->parseTemplateParams($sourceStr);
            if (!empty($source)) {
                $config['sources'][] = $source;
            }
        }

        // Parse {{BlueRailroadLeaderboard|...}} template calls from raw wikitext
        preg_match_all('/\{\{BlueRailroadLeaderboard\s*\n?((?:[^{}]|\{[^{]|\}[^}])*)\}\}/s', $textWithoutPre, $leaderboardMatches);
        foreach ($leaderboardMatches[1] as $lbStr) {
            $leaderboard = $this->parseTemplateParams($lbStr);
            if (!empty($leaderboard['page'])) {
                $config['leaderboards'][] = $leaderboard;
            }
        }

        // If no config found, return null to trigger defaults
        if (empty($config['sources']) && empty($config['leaderboards'])) {
            return null;
        }

        // Ensure at least one source
        if (empty($config['sources'])) {
            $config['sources'][] = [
                'network_id' => '10',
                'contract' => '0xCe09A2d0d0BDE635722D8EF31901b430E651dB52',
                'chain_data_key' => 'blueRailroads',
                'name' => 'Blue Railroad (Optimism)'
            ];
        }

        return $config;
    }

    /**
     * Parse pipe-separated template parameters
     */
    private function parseTemplateParams($paramStr) {
        $params = [];
        $parts = explode('|', $paramStr);
        foreach ($parts as $part) {
            $eqPos = strpos($part, '=');
            if ($eqPos !== false) {
                $key = trim(substr($part, 0, $eqPos));
                $value = trim(substr($part, $eqPos + 1));
                $params[$key] = $value;
            }
        }
        return $params;
    }

    /**
     * Get default configuration if wiki page is not available
     */
    private function getDefaultConfig() {
        return [
            'sources' => [
                [
                    'network_id' => '10',
                    'contract' => '0xCe09A2d0d0BDE635722D8EF31901b430E651dB52',
                    'chain_data_key' => 'blueRailroads',
                    'name' => 'Blue Railroad (Optimism)'
                ]
            ],
            'leaderboards' => [
                [
                    'page' => 'Blue Railroad Leaderboard',
                    'title' => 'Blue Railroad Leaderboard',
                    'description' => 'Overall token holdings across all exercises',
                    'sort' => 'count'
                ]
            ]
        ];
    }

    /**
     * Generate a leaderboard page based on configuration
     */
    private function generateLeaderboard($blueRailroads, $leaderboardConfig, $dryRun) {
        $pageName = $leaderboardConfig['page'] ?? '';
        if (empty($pageName)) {
            $this->error("Leaderboard config missing 'page' parameter");
            return;
        }

        $filterSongId = $leaderboardConfig['filter_song_id'] ?? '';
        $filterOwner = $leaderboardConfig['filter_owner'] ?? '';
        $description = $leaderboardConfig['description'] ?? '';
        $sortBy = $leaderboardConfig['sort'] ?? 'count';
        $displayTitle = $leaderboardConfig['title'] ?? $pageName;

        // Filter tokens if needed
        $filteredTokens = [];
        foreach ($blueRailroads as $tokenId => $token) {
            // Apply song filter
            if (!empty($filterSongId)) {
                $tokenSongId = isset($token['songId'])
                    ? (is_array($token['songId']) ? $token['songId'][0] : $token['songId'])
                    : '';
                if ((string)$tokenSongId !== (string)$filterSongId) {
                    continue;
                }
            }

            // Apply owner filter
            if (!empty($filterOwner)) {
                $tokenOwner = $token['owner'] ?? '';
                if (strtolower($tokenOwner) !== strtolower($filterOwner)) {
                    continue;
                }
            }

            $filteredTokens[$tokenId] = $token;
        }

        // Calculate ownership counts
        $ownerCounts = [];
        $ownerDisplayNames = [];
        $ownerNewestToken = [];
        $ownerOldestToken = [];

        foreach ($filteredTokens as $tokenId => $token) {
            $owner = $token['owner'] ?? '';
            $ownerDisplay = $token['ownerDisplay'] ?? $owner;
            $date = isset($token['date']) ? (is_array($token['date']) ? $token['date'][0] : $token['date']) : 0;

            if (empty($owner)) {
                continue;
            }

            if (!isset($ownerCounts[$owner])) {
                $ownerCounts[$owner] = 0;
                $ownerDisplayNames[$owner] = $ownerDisplay;
                $ownerNewestToken[$owner] = $date;
                $ownerOldestToken[$owner] = $date;
            }
            $ownerCounts[$owner]++;

            if ($date > $ownerNewestToken[$owner]) {
                $ownerNewestToken[$owner] = $date;
            }
            if ($date < $ownerOldestToken[$owner] || $ownerOldestToken[$owner] == 0) {
                $ownerOldestToken[$owner] = $date;
            }
        }

        // Sort based on config
        switch ($sortBy) {
            case 'newest':
                arsort($ownerNewestToken);
                $sortedOwners = array_keys($ownerNewestToken);
                break;
            case 'oldest':
                asort($ownerOldestToken);
                $sortedOwners = array_keys($ownerOldestToken);
                break;
            case 'count':
            default:
                arsort($ownerCounts);
                $sortedOwners = array_keys($ownerCounts);
                break;
        }

        // Build leaderboard page content
        $content = $this->buildLeaderboardContent(
            $sortedOwners,
            $ownerCounts,
            $ownerDisplayNames,
            $filteredTokens,
            $displayTitle,
            $description,
            $filterSongId
        );

        $title = Title::newFromText($pageName);

        if (!$title) {
            $this->error("Invalid title: $pageName");
            return;
        }

        $exists = $title->exists();
        $this->output("  " . ($exists ? "Updating" : "Creating") . " $pageName");
        if (!empty($filterSongId)) {
            $this->output(" (song_id=$filterSongId)");
        }
        $this->output("\n");

        if ($dryRun) {
            $this->output("    Would set leaderboard content (" . strlen($content) . " bytes)\n");
            return;
        }

        // Save the page
        $page = MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle($title);
        $contentObj = ContentHandler::makeContent($content, $title);

        $updater = $page->newPageUpdater(User::newSystemUser('BlueRailroad Import'));
        $updater->setContent('main', $contentObj);

        $comment = "Updated leaderboard from chain data";
        if (!empty($filterSongId)) {
            $comment .= " (song_id=$filterSongId)";
        }

        try {
            $updater->saveRevision(
                CommentStoreComment::newUnsavedComment($comment),
                $exists ? EDIT_UPDATE : EDIT_NEW
            );
            $this->output("    Saved successfully\n");
        } catch (Exception $e) {
            $this->error("Failed to save leaderboard: " . $e->getMessage());
        }
    }

    private function buildLeaderboardContent($sortedOwners, $ownerCounts, $ownerDisplayNames, $tokens, $title, $description, $filterSongId) {
        $totalTokens = count($tokens);
        $totalHolders = count($ownerCounts);

        // Determine exercise name for filtered leaderboards
        $exerciseName = '';
        if (!empty($filterSongId)) {
            $exerciseMap = [
                '5' => 'Squats ([[Blue Railroad Train]])',
                '6' => 'Pushups ([[Nine Pound Hammer]])',
                '7' => 'Squats ([[Blue Railroad Train]]) (legacy)',
                '10' => 'Army Crawls ([[Ginseng Sullivan]])',
            ];
            $exerciseName = $exerciseMap[$filterSongId] ?? "Exercise ID $filterSongId";
        }

        $lines = [
            "'''$title''' tracks ownership of [[Blue Railroad]] NFT tokens.",
        ];

        if (!empty($description)) {
            $lines[] = "";
            $lines[] = $description;
        }

        if (!empty($exerciseName)) {
            $lines[] = "";
            $lines[] = "'''Exercise:''' $exerciseName";
        }

        $lines[] = "";
        $lines[] = "''This page is automatically generated. See [[PickiPedia:BlueRailroadConfig|bot configuration]] to modify.''";
        $lines[] = "";
        $lines[] = "== Statistics ==";
        $lines[] = "* '''Total Tokens:''' $totalTokens";
        $lines[] = "* '''Total Holders:''' $totalHolders";
        $lines[] = "";
        $lines[] = "== Leaderboard ==";
        $lines[] = "{| class=\"wikitable sortable\"";
        $lines[] = "! Rank !! Holder !! Tokens !! Token IDs";

        $rank = 1;
        foreach ($sortedOwners as $owner) {
            $count = $ownerCounts[$owner];
            $displayName = $ownerDisplayNames[$owner];

            // Collect token IDs for this owner
            $tokenIds = [];
            foreach ($tokens as $tokenId => $token) {
                if (($token['owner'] ?? '') === $owner) {
                    $tokenIds[] = $tokenId;
                }
            }
            sort($tokenIds, SORT_NUMERIC);

            // Format token links
            $tokenLinks = [];
            foreach ($tokenIds as $tid) {
                $tokenLinks[] = "[[Blue Railroad Token $tid|#$tid]]";
            }
            $tokenLinksStr = implode(", ", $tokenLinks);

            // Format holder link
            $holderLink = $this->formatHolderLink($owner, $displayName);

            $lines[] = "|-";
            $lines[] = "| $rank || $holderLink || $count || $tokenLinksStr";

            $rank++;
        }

        $lines[] = "|}";
        $lines[] = "";
        $lines[] = "[[Category:Blue Railroad]]";
        $lines[] = "[[Category:Leaderboards]]";

        return implode("\n", $lines);
    }

    private function formatHolderLink($owner, $displayName) {
        // Query SMW for user with this Ethereum address
        $services = MediaWiki\MediaWikiServices::getInstance();

        // Try to find a user page with this Ethereum address
        // Using direct database query since SMW API is complex in maintenance scripts
        $dbr = $services->getDBLoadBalancer()->getConnection(DB_REPLICA);

        // Look for pages with Has Ethereum address property matching this owner
        // This is a simplified check - ideally would use SMW store directly
        $userTitle = null;

        // Check if display name looks like an ENS name and has a user page
        if (preg_match('/\.eth$/i', $displayName)) {
            $userTitle = Title::newFromText("User:$displayName");
            if ($userTitle && $userTitle->exists()) {
                return "[[User:$displayName|$displayName]]";
            }
        }

        // Check for user pages by common name patterns
        // Look for User:Justin Holmes style pages that might have the address
        $result = $dbr->newSelectQueryBuilder()
            ->select(['smw_title'])
            ->from('smw_object_ids')
            ->join('smw_di_blob', null, 'smw_id = p_id')
            ->where([
                'smw_namespace' => NS_USER,
                'o_blob' => $owner,
            ])
            ->caller(__METHOD__)
            ->fetchResultSet();

        foreach ($result as $row) {
            $foundTitle = Title::makeTitle(NS_USER, $row->smw_title);
            if ($foundTitle && $foundTitle->exists()) {
                return "[[User:" . $foundTitle->getText() . "|" . $foundTitle->getText() . "]]";
            }
        }

        // Fallback: just return the display name (possibly truncated address)
        return $displayName;
    }

    private function importToken($tokenId, $token, $dryRun) {
        $titleText = "Blue Railroad Token $tokenId";
        $title = Title::newFromText($titleText);

        if (!$title) {
            $this->error("Invalid title: $titleText");
            return 'error';
        }

        $exists = $title->exists();
        $action = $exists ? 'Updating' : 'Creating';

        // Build wiki content with SMW properties
        $content = $this->buildTokenPageContent($tokenId, $token);

        $this->output("$action $titleText\n");

        if ($dryRun) {
            $this->output("  Would set content:\n");
            foreach (explode("\n", $content) as $line) {
                $this->output("    $line\n");
            }
            return $exists ? 'updated' : 'imported';
        }

        // Actually create/update the page
        $page = MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle($title);
        $contentObj = ContentHandler::makeContent($content, $title);

        $updater = $page->newPageUpdater(User::newSystemUser('BlueRailroad Import'));
        $updater->setContent('main', $contentObj);

        $comment = $exists
            ? "Updated Blue Railroad token #$tokenId from chain data"
            : "Imported Blue Railroad token #$tokenId from chain data";

        try {
            $updater->saveRevision(
                CommentStoreComment::newUnsavedComment($comment),
                $exists ? EDIT_UPDATE : EDIT_NEW
            );
            return $exists ? 'updated' : 'imported';
        } catch (Exception $e) {
            $this->error("Failed to save $titleText: " . $e->getMessage());
            return 'error';
        }
    }

    private function buildTokenPageContent($tokenId, $token) {
        // Extract values, handling potential BigInt serialization and missing keys
        $id = isset($token['id']) ? (is_array($token['id']) ? $token['id'][0] : $token['id']) : $tokenId;
        $songId = isset($token['songId']) ? (is_array($token['songId']) ? $token['songId'][0] : $token['songId']) : '';
        $date = isset($token['date']) ? (is_array($token['date']) ? $token['date'][0] : $token['date']) : '';
        $owner = $token['owner'] ?? '';
        $ownerDisplay = $token['ownerDisplay'] ?? $owner;
        $uri = $token['uri'] ?? '';

        // Determine URI type for template
        $uriType = 'unknown';
        if (strpos($uri, 'ipfs://') === 0) {
            $uriType = 'ipfs';
        } elseif (strpos($uri, 'https://') === 0) {
            $uriType = 'https';
        }

        // Extract IPFS CID if applicable
        $ipfsCid = '';
        if ($uriType === 'ipfs') {
            $ipfsCid = substr($uri, 7);
        }

        // Convert date from YYYYMMDD to readable format
        $dateStr = (string)$date;
        $formattedDate = '';
        if (strlen($dateStr) === 8) {
            $year = substr($dateStr, 0, 4);
            $month = substr($dateStr, 4, 2);
            $day = substr($dateStr, 6, 2);
            $formattedDate = "$year-$month-$day";
        }

        // Build page content using a template
        $lines = [
            "{{Blue Railroad Token",
            "|token_id=$id",
            "|song_id=$songId",
            "|date=$formattedDate",
            "|date_raw=$date",
            "|owner=$owner",
            "|owner_display=$ownerDisplay",
            "|uri=$uri",
            "|uri_type=$uriType",
            "|ipfs_cid=$ipfsCid",
            "}}",
            "",
            "[[Category:Blue Railroad Tokens]]",
        ];

        return implode("\n", $lines);
    }
}

$maintClass = ImportBlueRailroads::class;
require_once RUN_MAINTENANCE_IF_MAIN;
