#!/bin/bash
# Load latest PickiPedia backup into preview MySQL
# Run this after 'docker-compose up -d' to populate with production data

set -e

BACKUP_DIR="/opt/magenta/pickipedia-backups"  # Synced from maybelle daily
LATEST_BACKUP=$(ls -t ${BACKUP_DIR}/pickipedia_*.sql.gz 2>/dev/null | head -1)

if [ -z "$LATEST_BACKUP" ]; then
    echo "No backup found in $BACKUP_DIR"
    echo "Backups are created daily at 3:30am on maybelle"
    exit 1
fi

echo "Loading backup: $LATEST_BACKUP"

# Get container name from compose project
CONTAINER=$(docker ps --filter "name=pickipedia.*db" --format "{{.Names}}" | head -1)
if [ -z "$CONTAINER" ]; then
    echo "MySQL container not running. Start with: docker-compose up -d"
    exit 1
fi

# Wait for MariaDB to be ready
echo "Waiting for MariaDB..."
until docker exec "$CONTAINER" mariadb -u pickipedia -ppickipedia_dev -e "SELECT 1" &>/dev/null; do
    sleep 1
done

# Load the backup
echo "Loading data (this may take a moment)..."
gunzip -c "$LATEST_BACKUP" | docker exec -i "$CONTAINER" mariadb -u pickipedia -ppickipedia_dev pickipedia

echo "Done! PickiPedia preview now has production data."
