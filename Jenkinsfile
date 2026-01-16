pipeline {
    agent any

    options {
        buildDiscarder(logRotator(numToKeepStr: '20'))
    }

    environment {
        MEDIAWIKI_VERSION = '1.43.6'
        // Bump this to force rebuild of cached MediaWiki + extensions
        BUILD_CACHE_VERSION = '2'
        SECRETS_DIR = '/var/jenkins_home/secrets'
        BUILD_DIR = "${WORKSPACE}/build"
        MW_DIR = "${BUILD_DIR}/mediawiki"
    }

    stages {
        stage('Setup Secrets') {
            steps {
                sh '''#!/bin/bash
                    # Copy LocalSettings.local.php from secrets
                    if [ -f "${SECRETS_DIR}/pickipedia/LocalSettings.local.php" ]; then
                        cp "${SECRETS_DIR}/pickipedia/LocalSettings.local.php" LocalSettings.local.php
                        chmod 644 LocalSettings.local.php
                    else
                        echo "ERROR: LocalSettings.local.php not found in secrets"
                        exit 1
                    fi
                '''
            }
        }

        stage('Download MediaWiki') {
            steps {
                sh '''#!/bin/bash
                    set -e

                    mkdir -p "${BUILD_DIR}"

                    MW_TARBALL="mediawiki-${MEDIAWIKI_VERSION}.tar.gz"
                    MW_URL="https://releases.wikimedia.org/mediawiki/${MEDIAWIKI_VERSION%.*}/mediawiki-${MEDIAWIKI_VERSION}.tar.gz"

                    # Check if we have cached version (includes BUILD_CACHE_VERSION to force rebuilds)
                    CACHE_KEY="${MEDIAWIKI_VERSION}-${BUILD_CACHE_VERSION}"
                    if [ -f "${BUILD_DIR}/.mw-version" ] && [ "$(cat "${BUILD_DIR}/.mw-version")" = "${CACHE_KEY}" ]; then
                        echo "Using cached MediaWiki ${MEDIAWIKI_VERSION} (cache v${BUILD_CACHE_VERSION})"
                    else
                        echo "Downloading MediaWiki ${MEDIAWIKI_VERSION}..."
                        curl -fSL "${MW_URL}" -o "${BUILD_DIR}/${MW_TARBALL}"

                        echo "Extracting..."
                        rm -rf "${MW_DIR}"
                        mkdir -p "${MW_DIR}"
                        tar -xzf "${BUILD_DIR}/${MW_TARBALL}" -C "${MW_DIR}" --strip-components=1

                        echo "${CACHE_KEY}" > "${BUILD_DIR}/.mw-version"
                        rm "${BUILD_DIR}/${MW_TARBALL}"
                    fi
                '''
            }
        }

        stage('Install Extensions') {
            steps {
                sh '''#!/bin/bash
                    set -e

                    # Copy our configuration into MediaWiki
                    cp LocalSettings.php "${MW_DIR}/"
                    cp LocalSettings.local.php "${MW_DIR}/"
                    cp .htaccess "${MW_DIR}/"

                    # Copy composer.json as composer.local.json for MediaWiki
                    cp composer.json "${MW_DIR}/composer.local.json"

                    # Install composer dependencies - only if composer.json changed
                    cd "${MW_DIR}"
                    COMPOSER_HASH=$(md5sum composer.local.json | cut -d' ' -f1)
                    CACHED_HASH=""
                    if [ -f ".composer-hash" ]; then
                        CACHED_HASH=$(cat .composer-hash)
                    fi

                    if [ "$COMPOSER_HASH" != "$CACHED_HASH" ] || [ ! -d "vendor" ]; then
                        echo "composer.json changed or vendor missing - running composer update..."
                        rm -f composer.lock
                        rm -rf vendor
                        composer update --no-dev --optimize-autoloader --ignore-platform-reqs
                        echo "$COMPOSER_HASH" > .composer-hash
                    else
                        echo "composer.json unchanged - using cached vendor/"
                    fi
                '''
            }
        }

        stage('Install Non-Composer Extensions') {
            steps {
                sh '''#!/bin/bash
                    set -e
                    cd "${MW_DIR}/extensions"

                    # YouTube extension (not on Packagist)
                    if [ ! -d "YouTube" ]; then
                        git clone --depth 1 https://github.com/wikimedia/mediawiki-extensions-YouTube.git YouTube
                    fi

                    # MsUpload - drag-and-drop multiple file upload
                    if [ ! -d "MsUpload" ]; then
                        git clone --depth 1 https://github.com/wikimedia/mediawiki-extensions-MsUpload.git MsUpload
                    fi

                    # TimedMediaHandler - video/audio playback with FFmpeg transcoding
                    if [ ! -d "TimedMediaHandler" ]; then
                        git clone --depth 1 --branch REL1_43 https://github.com/wikimedia/mediawiki-extensions-TimedMediaHandler.git TimedMediaHandler
                        cd TimedMediaHandler && composer install --no-dev && cd ..
                    fi

                    # RSS - embed RSS feeds in wiki pages
                    if [ ! -d "RSS" ]; then
                        git clone --depth 1 --branch REL1_43 https://github.com/wikimedia/mediawiki-extensions-RSS.git RSS
                    fi
                '''
            }
        }

        stage('Copy Custom Extensions') {
            steps {
                sh '''#!/bin/bash
                    # Copy any custom extensions we've developed
                    # Use rsync with --checksum to only transfer changed files
                    if [ -d "${WORKSPACE}/extensions" ]; then
                        echo "Copying custom extensions..."
                        rsync -a --checksum "${WORKSPACE}/extensions/" "${MW_DIR}/extensions/"
                    else
                        echo "No custom extensions directory"
                    fi
                '''
            }
        }

        stage('Copy Assets') {
            steps {
                sh '''#!/bin/bash
                    set -e
                    # Copy logo and other assets
                    if [ -d "${WORKSPACE}/assets" ]; then
                        mkdir -p "${MW_DIR}/assets"
                        cp -r "${WORKSPACE}/assets/"* "${MW_DIR}/assets/"
                    fi

                    # Copy diagnostic script if present (for debugging)
                    if [ -f "${WORKSPACE}/diag.php" ]; then
                        cp "${WORKSPACE}/diag.php" "${MW_DIR}/"
                    fi
                '''
            }
        }

        stage('Copy Chain Data') {
            steps {
                sh '''#!/bin/bash
                    set -e

                    CHAIN_DATA_SOURCE="/var/jenkins_home/shared/chain_data"
                    CHAIN_DATA_DEST="${MW_DIR}/chain-data"

                    if [ -d "${CHAIN_DATA_SOURCE}" ] && [ -f "${CHAIN_DATA_SOURCE}/chainData.json" ]; then
                        echo "Copying chain data from ${CHAIN_DATA_SOURCE}..."
                        mkdir -p "${CHAIN_DATA_DEST}"
                        cp "${CHAIN_DATA_SOURCE}/chainData.json" "${CHAIN_DATA_DEST}/"
                        echo "Chain data copied successfully"
                    else
                        echo "Warning: Chain data not found at ${CHAIN_DATA_SOURCE}"
                        echo "Blue Railroad token data will not be available"
                    fi
                '''
            }
        }

        stage('Generate Build Info') {
            steps {
                sh '''#!/bin/bash
                    set -e

                    # Fetch current Ethereum mainnet block height from Blockscout
                    echo "Fetching current Ethereum block height..."
                    BLOCK_HEIGHT=$(curl -s "https://eth.blockscout.com/api/v2/blocks?type=block" | grep -o '"height":[0-9]*' | head -1 | cut -d: -f2)

                    if [ -n "$BLOCK_HEIGHT" ] && [ "$BLOCK_HEIGHT" -gt 0 ] 2>/dev/null; then
                        echo "Current block height: ${BLOCK_HEIGHT}"
                    else
                        echo "Warning: Could not fetch block height, using 0"
                        BLOCK_HEIGHT=0
                    fi

                    # Generate build-info.php
                    cat > "${MW_DIR}/build-info.php" << 'PHPEOF'
<?php
// Auto-generated at build time - do not edit
$wgPickipediaBuildInfo = [
    'blockheight' => BLOCK_HEIGHT_PLACEHOLDER,
    'build_number' => 'BUILD_NUMBER_PLACEHOLDER',
    'commit' => 'COMMIT_PLACEHOLDER',
    'build_time' => 'BUILD_TIME_PLACEHOLDER',
];
PHPEOF
                    # Replace placeholders with actual values
                    sed -i "s/BLOCK_HEIGHT_PLACEHOLDER/${BLOCK_HEIGHT}/" "${MW_DIR}/build-info.php"
                    sed -i "s/BUILD_NUMBER_PLACEHOLDER/${BUILD_NUMBER}/" "${MW_DIR}/build-info.php"
                    sed -i "s/COMMIT_PLACEHOLDER/$(git rev-parse --short HEAD)/" "${MW_DIR}/build-info.php"
                    sed -i "s/BUILD_TIME_PLACEHOLDER/$(date -Iseconds)/" "${MW_DIR}/build-info.php"
                    echo "Generated build-info.php with block ${BLOCK_HEIGHT}"
                '''
            }
        }

        stage('Stage for Deploy') {
            // Note: This job only builds production branch (configured in job definition)
            // so no branch conditional needed
            steps {
                sh '''#!/bin/bash
                    set -e

                    STAGE_DIR="/var/jenkins_home/pickipedia_stage"
                    MARKER_FILE="${STAGE_DIR}/.deploy-ready"

                    echo "Staging build to ${STAGE_DIR}..."
                    mkdir -p "${STAGE_DIR}"

                    # Rsync the built MediaWiki to staging
                    rsync -av --delete "${MW_DIR}/" "${STAGE_DIR}/"

                    # Create deploy marker
                    COMMIT_SHA=$(git rev-parse HEAD)
                    echo "commit=${COMMIT_SHA} build=${BUILD_NUMBER} time=$(date -Iseconds)" > "${MARKER_FILE}"

                    echo "Build staged - deploy marker created"
                '''
            }
        }
    }

    post {
        success {
            echo "Build ${BUILD_NUMBER} succeeded"
            echo "MediaWiki ${MEDIAWIKI_VERSION} built at ${MW_DIR}"
        }
        failure {
            echo "Build ${BUILD_NUMBER} failed"
        }
    }
}
