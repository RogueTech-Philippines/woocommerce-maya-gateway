#!/usr/bin/env bash
#
# Build a production-installable zip of the plugin.
#
# Produces `dist/wc-maya-gateway-<version>.zip` containing only the runtime
# files: src/, vendor/ (composer install --no-dev), assets/, templates/,
# languages/, the main plugin file, README, LICENSE, CHANGELOG. Dev files
# (tests/, docs/, bin/, .git*, composer.lock, phpcs/phpunit configs, etc.)
# are excluded.
#
# Usage:
#
#     bin/build-release.sh
#
# CI calls this with no flags and uploads dist/*.zip as the release asset.

set -euo pipefail

PLUGIN_SLUG="wc-maya-gateway"
PLUGIN_DIR_NAME="woocommerce-maya-gateway"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGING_DIR="${DIST_DIR}/${PLUGIN_DIR_NAME}"

# Read the Version: header out of the main plugin file.
VERSION="$(grep -E '^[ ]*\*[ ]*Version:' "${ROOT_DIR}/wc-maya-payment-gateway.php" | head -n 1 | awk -F: '{ gsub(/^[ ]+|[ ]+$/, "", $2); print $2 }')"
if [ -z "${VERSION}" ]; then
    echo "Could not read plugin version from main plugin file." >&2
    exit 1
fi

echo "Building ${PLUGIN_SLUG} v${VERSION}…"

rm -rf "${DIST_DIR}"
mkdir -p "${STAGING_DIR}"

# rsync the runtime files into the staging dir, filtering out dev junk.
RSYNC_EXCLUDES=(
    --exclude='.git'
    --exclude='.git/**'
    --exclude='.gitignore'
    --exclude='.github'
    --exclude='.github/**'
    --exclude='.phpunit.cache'
    --exclude='.phpunit.cache/**'
    --exclude='.php-cs-fixer.cache'
    --exclude='.php-cs-fixer.php'
    --exclude='.idea'
    --exclude='.vscode'
    --exclude='tests'
    --exclude='tests/**'
    --exclude='docs'
    --exclude='docs/**'
    --exclude='bin'
    --exclude='bin/**'
    --exclude='dist'
    --exclude='dist/**'
    --exclude='node_modules'
    --exclude='node_modules/**'
    --exclude='phpcs.xml'
    --exclude='phpunit.xml'
    --exclude='composer.lock'
)

rsync -a "${RSYNC_EXCLUDES[@]}" "${ROOT_DIR}/" "${STAGING_DIR}/"

# Rebuild vendor/ without dev deps. We're staging — don't touch the repo.
( cd "${STAGING_DIR}" && composer install --no-dev --optimize-autoloader --classmap-authoritative --quiet )

# Strip ./vendor caches that composer leaves but the plugin doesn't need.
# `set -e` aborts on any failure here. The hygiene strip below is best-effort
# (a stray locked file shouldn't fail the build), so we capture the exit code
# and warn loudly instead of silencing with `|| true` — silent suppression
# masked the case where a permission error left dev files in the release zip.
find "${STAGING_DIR}/vendor" -type d \( -name 'tests' -o -name 'test' -o -name 'docs' -o -name 'doc' \) -prune -exec rm -rf {} +

set +e
find "${STAGING_DIR}/vendor" -type f \( -iname 'CHANGELOG*' -o -iname '*.md' -o -name 'phpunit.xml*' -o -name 'phpstan.neon*' -o -name '.php-cs-fixer*' \) -delete
FIND_EXIT=$?
set -e
if [ "${FIND_EXIT}" -ne 0 ]; then
    echo "Warning: vendor file-strip exited ${FIND_EXIT}; release zip may include dev files." >&2
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
( cd "${DIST_DIR}" && zip -qr "${ZIP_NAME}" "${PLUGIN_DIR_NAME}" )

echo "Built ${DIST_DIR}/${ZIP_NAME}"

du -h "${DIST_DIR}/${ZIP_NAME}" | awk '{ print "Size:  " $1 }'
