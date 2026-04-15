#!/usr/bin/env bash
#
# Build a clean zip of the Stagify plugin for WordPress.org submission.
# Usage: npm run zip
#
set -euo pipefail

PLUGIN_SLUG="stagify"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="$(mktemp -d)"
DEST="${BUILD_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${PLUGIN_DIR}/${PLUGIN_SLUG}.zip"

echo "=> Installing production dependencies..."
composer install --no-dev --optimize-autoloader --working-dir="$PLUGIN_DIR" --quiet

echo "=> Copying plugin files..."
mkdir -p "$DEST"

# Copy only what WordPress.org needs.
cp "$PLUGIN_DIR/stagify.php"   "$DEST/"
cp "$PLUGIN_DIR/readme.txt"    "$DEST/"
cp "$PLUGIN_DIR/composer.json" "$DEST/"
cp -R "$PLUGIN_DIR/admin"      "$DEST/"
cp -R "$PLUGIN_DIR/api"        "$DEST/"
cp -R "$PLUGIN_DIR/includes"   "$DEST/"
cp -R "$PLUGIN_DIR/vendor"     "$DEST/"

# Assets: only compiled output, not source.
mkdir -p "$DEST/assets"
cp -R "$PLUGIN_DIR/assets/dist" "$DEST/assets/"
cp -R "$PLUGIN_DIR/assets/css"  "$DEST/assets/"
cp -R "$PLUGIN_DIR/assets/img"  "$DEST/assets/"

echo "=> Creating zip..."
rm -f "$ZIP_FILE"
(cd "$BUILD_DIR" && zip -rq "$ZIP_FILE" "$PLUGIN_SLUG")

echo "=> Restoring dev dependencies..."
composer install --working-dir="$PLUGIN_DIR" --quiet

# Clean up.
rm -rf "$BUILD_DIR"

echo "=> Done: ${ZIP_FILE}"
