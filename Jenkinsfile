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
                '''
            }
        }

        stage('Copy Custom Extensions') {
            steps {
                sh '''#!/bin/bash
                    # Copy any custom extensions we've developed
                    if [ -d "extensions" ] && [ -n "$(ls -A extensions 2>/dev/null)" ]; then
                        echo "Copying custom extensions..."
                        cp -r extensions/* "${MW_DIR}/extensions/"
                    else
                        echo "No custom extensions to copy"
                    fi
                '''
            }
        }

        // TODO: Add production deploy stage once hunter preview is working
        // Will use marker-file pattern like justinholmes.com for security
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
