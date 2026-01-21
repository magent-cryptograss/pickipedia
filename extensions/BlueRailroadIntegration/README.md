# Blue Railroad Integration

Imports Blue Railroad NFT token data from chain data JSON into Semantic MediaWiki.

## Features

- Imports token data from on-chain JSON into wiki pages
- **Wiki-based configuration** - leaderboards and sources defined on wiki pages
- Multiple leaderboards with filtering (by exercise, owner, etc.)
- Automatic owner resolution via SMW properties

## Setup

1. Enable the extension in `LocalSettings.php`:
   ```php
   wfLoadExtension('BlueRailroadIntegration');
   ```

2. Create the configuration templates on the wiki:
   - `Template:BlueRailroadSource` - defines data sources
   - `Template:BlueRailroadLeaderboard` - defines leaderboard pages to generate

3. Create the configuration page `PickiPedia:BlueRailroadConfig`

## Wiki Configuration

The import bot reads its configuration from `PickiPedia:BlueRailroadConfig`. This allows
wiki editors to add/modify leaderboards without touching code.

### Data Source Template

```wiki
{{BlueRailroadSource
|network_id=10
|contract=0xCe09A2d0d0BDE635722D8EF31901b430E651dB52
|chain_data_key=blueRailroads
|name=Blue Railroad (Optimism)
}}
```

### Leaderboard Template

```wiki
{{BlueRailroadLeaderboard
|page=Blue Railroad Leaderboard
|description=Overall token holdings across all exercises
|sort=count
}}

{{BlueRailroadLeaderboard
|page=Blue Railroad Squats Leaderboard
|filter_song_id=5
|description=Leaderboard for Squats (Blue Railroad Train)
}}
```

### Leaderboard Parameters

| Parameter | Description |
|-----------|-------------|
| `page` | Wiki page title for the leaderboard (required) |
| `title` | Display title on the page (defaults to page name) |
| `filter_song_id` | Only include tokens with this song ID |
| `filter_owner` | Only include tokens owned by this address |
| `description` | Description shown at top of leaderboard |
| `sort` | Sort order: `count` (default), `newest`, `oldest` |

### Song IDs

| ID | Exercise | Song |
|----|----------|------|
| 5 | Squats | Blue Railroad Train |
| 6 | Pushups | Nine Pound Hammer |
| 10 | Army Crawls | Ginseng Sullivan |

## Running the Import

The import script reads from `chain-data/chainData.json` in the MediaWiki install directory.

```bash
# Dry run to see what would be imported
php extensions/BlueRailroadIntegration/maintenance/importBlueRailroads.php --dry-run

# Actually import
php extensions/BlueRailroadIntegration/maintenance/importBlueRailroads.php

# Use a different config page
php extensions/BlueRailroadIntegration/maintenance/importBlueRailroads.php --config-page="MyWiki:CustomConfig"
```

## Deployment Architecture

The import runs automatically after each successful PickiPedia deployment via the
host deploy cron on maybelle. See `/var/log/pickipedia-deploy.log` for status.

To run manually:
```bash
ssh nfs-pickipedia "cd ~/public && php extensions/BlueRailroadIntegration/maintenance/importBlueRailroads.php"
```

## Querying Tokens

Once imported, you can query tokens using SMW:

```wiki
{{#ask:
 [[Category:Blue Railroad Tokens]]
 |?Has token id
 |?Has song id
 |?Has date minted
 |?Has owner
 |format=table
}}
```

List tokens by owner:
```wiki
{{#ask:
 [[Category:Blue Railroad Tokens]]
 [[Has owner::justinholmes.eth]]
 |?Has token id
 |?Has song id
 |?Has date minted
}}
```

## Architecture

```
PickiPedia:BlueRailroadConfig (wiki page)
    │
    ├── {{BlueRailroadSource}} → defines where to read chain data
    │
    └── {{BlueRailroadLeaderboard}} → defines pages to generate
            │
            ▼
importBlueRailroads.php
    │
    ├── Reads config from wiki
    ├── Reads chain-data/chainData.json
    ├── Creates/updates Blue Railroad Token N pages
    └── Generates configured leaderboard pages
```
