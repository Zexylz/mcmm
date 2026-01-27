#!/bin/bash
# Build script for MCMM Unraid Plugin
# This script copies plugin files into the src/ directory structure
# required by the unraid-plugin-release-action

set -euo pipefail

PLUGIN_DIR="src/usr/local/emhttp/plugins/mcmm"

echo "Creating plugin directory structure..."
mkdir -p "$PLUGIN_DIR"

echo "Copying plugin files..."
cp -r include "$PLUGIN_DIR/"
cp -r images "$PLUGIN_DIR/"
cp -r javascript "$PLUGIN_DIR/"
cp -r styles "$PLUGIN_DIR/"
cp api.php "$PLUGIN_DIR/"
cp default.cfg "$PLUGIN_DIR/"
cp mcmm.page "$PLUGIN_DIR/"
cp .htaccess "$PLUGIN_DIR/"

echo "Build preparation complete!"
echo "Files copied to: $PLUGIN_DIR"
ls -la "$PLUGIN_DIR"
