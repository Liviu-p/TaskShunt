#!/usr/bin/env bash
#
# Build a zip of the Stagify plugin for WordPress.org review.
# Includes source files (SCSS, TypeScript) and build tooling so reviewers
# can inspect and rebuild the compiled assets.
#
# Usage: npm run zip:review
#
set -euo pipefail

PLUGIN_SLUG="stagify"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="$(mktemp -d)"
DEST="${BUILD_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${PLUGIN_DIR}/${PLUGIN_SLUG}-review.zip"

echo "=> Installing production dependencies..."
composer install --no-dev --optimize-autoloader --working-dir="$PLUGIN_DIR" --quiet

echo "=> Copying plugin files..."
mkdir -p "$DEST"

# Core plugin files.
cp "$PLUGIN_DIR/stagify.php"   "$DEST/"
cp "$PLUGIN_DIR/readme.txt"    "$DEST/"
cp "$PLUGIN_DIR/composer.json" "$DEST/"
cp -R "$PLUGIN_DIR/admin"      "$DEST/"
cp -R "$PLUGIN_DIR/api"        "$DEST/"
cp -R "$PLUGIN_DIR/includes"   "$DEST/"
cp -R "$PLUGIN_DIR/vendor"     "$DEST/"

# Assets: compiled output AND source files for review.
cp -R "$PLUGIN_DIR/assets" "$DEST/"

# Build tooling so reviewers can verify compiled output.
cp "$PLUGIN_DIR/package.json"      "$DEST/"
cp "$PLUGIN_DIR/package-lock.json" "$DEST/"
cp "$PLUGIN_DIR/webpack.config.js" "$DEST/"
cp "$PLUGIN_DIR/tsconfig.json"     "$DEST/"

echo "=> Creating zip..."
rm -f "$ZIP_FILE"
(cd "$BUILD_DIR" && zip -rq "$ZIP_FILE" "$PLUGIN_SLUG")

echo "=> Restoring dev dependencies..."
composer install --working-dir="$PLUGIN_DIR" --quiet

# Clean up.
rm -rf "$BUILD_DIR"

echo "=> Done: ${ZIP_FILE}"
