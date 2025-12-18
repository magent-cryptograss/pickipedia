# PickiPedia

Traditional music knowledge base powered by MediaWiki and Semantic MediaWiki.

## Architecture

- **MediaWiki core**: Downloaded at deploy time (version specified in `.env`)
- **Extensions**: Managed via composer + custom extensions in `extensions/`
- **Production**: NearlyFreeSpeech (rsync deploy)
- **Preview**: Docker on hunter (same DB, different MW version for testing)

## Quick Start (Local Development)

```bash
cp .env.example .env
# Edit .env with your settings

docker-compose up -d
```

## Deployment

Production deploys happen via Jenkins. The pipeline:
1. Pulls specified MediaWiki version
2. Installs composer dependencies (SMW, EmbedVideo)
3. Copies configuration (secrets from Vault)
4. Rsyncs to NearlyFreeSpeech

## Extensions

- **Semantic MediaWiki**: Structured data, queries, RDF export
- **EmbedVideo**: YouTube/Vimeo embeds for tune references
- Custom extensions go in `extensions/`

## Configuration

- `LocalSettings.php` - Main config (tracked)
- `LocalSettings.local.php` - Secrets (generated at deploy, not tracked)

## Links

- Production: https://pickipedia.xyz
- [Semantic MediaWiki docs](https://www.semantic-mediawiki.org/)
