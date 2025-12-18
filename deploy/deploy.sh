#!/bin/bash
#
# Deploy PickiPedia to NearlyFreeSpeech
#
# This script:
# 1. Downloads MediaWiki core if needed
# 2. Installs composer dependencies
# 3. Syncs to NFS via rsync
#
# Expected environment variables:
#   MEDIAWIKI_VERSION - e.g., "1.43.0"
#   NFS_HOST - e.g., "ssh.phx.nearlyfreespeech.net"
#   NFS_USER - SSH username
#   NFS_PATH - Remote path, e.g., "/home/public"
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="${PROJECT_ROOT}/build"
MW_DIR="${BUILD_DIR}/mediawiki"

echo "=== PickiPedia Deploy ==="
echo "MediaWiki version: ${MEDIAWIKI_VERSION}"
echo "Target: ${NFS_USER}@${NFS_HOST}:${NFS_PATH}"

# Create build directory
mkdir -p "${BUILD_DIR}"

# Download MediaWiki if not cached or version changed
MW_TARBALL="mediawiki-${MEDIAWIKI_VERSION}.tar.gz"
MW_URL="https://releases.wikimedia.org/mediawiki/${MEDIAWIKI_VERSION%.*}/mediawiki-${MEDIAWIKI_VERSION}.tar.gz"

if [[ ! -d "${MW_DIR}" ]] || [[ ! -f "${BUILD_DIR}/.mw-version" ]] || [[ "$(cat "${BUILD_DIR}/.mw-version")" != "${MEDIAWIKI_VERSION}" ]]; then
    echo "Downloading MediaWiki ${MEDIAWIKI_VERSION}..."
    curl -fSL "${MW_URL}" -o "${BUILD_DIR}/${MW_TARBALL}"

    echo "Extracting..."
    rm -rf "${MW_DIR}"
    mkdir -p "${MW_DIR}"
    tar -xzf "${BUILD_DIR}/${MW_TARBALL}" -C "${MW_DIR}" --strip-components=1

    echo "${MEDIAWIKI_VERSION}" > "${BUILD_DIR}/.mw-version"
    rm "${BUILD_DIR}/${MW_TARBALL}"
else
    echo "Using cached MediaWiki ${MEDIAWIKI_VERSION}"
fi

# Copy our configuration
echo "Copying configuration..."
cp "${PROJECT_ROOT}/LocalSettings.php" "${MW_DIR}/"
cp "${PROJECT_ROOT}/LocalSettings.local.php" "${MW_DIR}/"

# Install composer dependencies into MediaWiki
echo "Installing composer dependencies..."
cp "${PROJECT_ROOT}/composer.json" "${MW_DIR}/composer.local.json"
cd "${MW_DIR}"
composer update --no-dev --optimize-autoloader

# Copy custom extensions (if any)
if [[ -d "${PROJECT_ROOT}/extensions" ]] && [[ -n "$(ls -A "${PROJECT_ROOT}/extensions" 2>/dev/null)" ]]; then
    echo "Copying custom extensions..."
    cp -r "${PROJECT_ROOT}/extensions/"* "${MW_DIR}/extensions/"
fi

# Sync to NFS
echo "Syncing to NFS..."
rsync -avz --delete \
    --exclude='.git' \
    --exclude='cache/*' \
    --exclude='images/*' \
    "${MW_DIR}/" \
    "${NFS_USER}@${NFS_HOST}:${NFS_PATH}/"

echo "=== Deploy complete ==="
