<?php
/**
 * Import Blue Railroad token data from chain data JSON into Semantic MediaWiki
 *
 * Creates/updates pages in the BlueRailroad namespace with SMW properties:
 * - Token ID
 * - Owner address
 * - Owner display (ENS name or address)
 * - Video URI (IPFS)
 * - Song ID
 * - Date minted
 *
 * Usage: php importBlueRailroads.php
 */

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class ImportBlueRailroads extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription('Import Blue Railroad tokens from chain data JSON into SMW');
        $this->addOption('dry-run', 'Show what would be imported without making changes', false, false);
        $this->addOption('chain-data', 'Path to chainData.json', false, true);
    }

    public function execute() {
        $dryRun = $this->hasOption('dry-run');

        // Find chain data file
        $chainDataPath = $this->getOption('chain-data');
        if (!$chainDataPath) {
            // Default location in MediaWiki install
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

        if (!isset($chainData['blueRailroads'])) {
            $this->fatalError("No blueRailroads data found in chain data");
        }

        $blueRailroads = $chainData['blueRailroads'];
        $count = count($blueRailroads);
        $this->output("Found $count Blue Railroad tokens\n");

        if ($dryRun) {
            $this->output("DRY RUN - no changes will be made\n\n");
        }

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

        $this->output("\n");
        $this->output("Summary:\n");
        $this->output("  Imported: $imported\n");
        $this->output("  Updated: $updated\n");
        $this->output("  Errors: $errors\n");
    }

    private function importToken($tokenId, $token, $dryRun) {
        $titleText = "BlueRailroad:Token_$tokenId";
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
        // Extract values, handling potential BigInt serialization
        $id = is_array($token['id']) ? $token['id'][0] : $token['id'];
        $songId = is_array($token['songId']) ? $token['songId'][0] : $token['songId'];
        $date = is_array($token['date']) ? $token['date'][0] : $token['date'];
        $owner = $token['owner'] ?? '';
        $ownerDisplay = $token['ownerDisplay'] ?? $owner;
        $uri = $token['uri'] ?? '';

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
            "}}",
            "",
            "[[Category:Blue Railroad Tokens]]",
        ];

        return implode("\n", $lines);
    }
}

$maintClass = ImportBlueRailroads::class;
require_once RUN_MAINTENANCE_IF_MAIN;
