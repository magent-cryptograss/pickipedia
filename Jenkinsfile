pipeline {
    agent any

    options {
        buildDiscarder(logRotator(numToKeepStr: '20'))
    }

    environment {
        MEDIAWIKI_VERSION = '1.43.0'
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
                        chmod 600 LocalSettings.local.php
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

                    # Check if we have cached version
                    if [ -f "${BUILD_DIR}/.mw-version" ] && [ "$(cat "${BUILD_DIR}/.mw-version")" = "${MEDIAWIKI_VERSION}" ]; then
                        echo "Using cached MediaWiki ${MEDIAWIKI_VERSION}"
                    else
                        echo "Downloading MediaWiki ${MEDIAWIKI_VERSION}..."
                        curl -fSL "${MW_URL}" -o "${BUILD_DIR}/${MW_TARBALL}"

                        echo "Extracting..."
                        rm -rf "${MW_DIR}"
                        mkdir -p "${MW_DIR}"
                        tar -xzf "${BUILD_DIR}/${MW_TARBALL}" -C "${MW_DIR}" --strip-components=1

                        echo "${MEDIAWIKI_VERSION}" > "${BUILD_DIR}/.mw-version"
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

                    # Copy composer.json as composer.local.json for MediaWiki
                    cp composer.json "${MW_DIR}/composer.local.json"

                    # Install composer dependencies
                    cd "${MW_DIR}"
                    composer update --no-dev --optimize-autoloader
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
                '''
            }
        }

        stage('Copy Custom Extensions') {
            steps {
                sh '''#!/bin/bash
                    # Copy any custom extensions we've developed
                    if [ -d "${WORKSPACE}/extensions" ]; then
                        echo "Copying custom extensions..."
                        cp -r "${WORKSPACE}/extensions/"* "${MW_DIR}/extensions/" 2>/dev/null || echo "No custom extensions found"
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
                    cat > "${MW_DIR}/build-info.php" << PHPEOF
<?php
// Auto-generated at build time - do not edit
\$wgPickipediaBuildInfo = [
    'blockheight' => ${BLOCK_HEIGHT},
    'build_number' => '${BUILD_NUMBER}',
    'commit' => '$(git rev-parse --short HEAD)',
    'build_time' => '$(date -Iseconds)',
];
PHPEOF
                    echo "Generated build-info.php with block ${BLOCK_HEIGHT}"
                '''
            }
        }

        stage('Stage for Deploy') {
            when {
                branch 'production'
            }
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
