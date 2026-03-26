#!/usr/bin/env bash
#
# Build a release zip containing only runtime files.
# Usage: ./scripts/build-release.sh [version]
# Example: ./scripts/build-release.sh 1.0.0

set -euo pipefail

VERSION="${1:-}"

if [ -z "$VERSION" ]; then
    echo "Usage: $0 <version>"
    echo "Example: $0 1.0.0"
    exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="$ROOT/build"
RELEASE_NAME="puls-${VERSION}"
RELEASE_DIR="$BUILD_DIR/$RELEASE_NAME"
ZIP_FILE="$BUILD_DIR/${RELEASE_NAME}.zip"

# Clean previous build
rm -rf "$BUILD_DIR"
mkdir -p "$RELEASE_DIR"

# Copy runtime files
cp "$ROOT/public/index.php" "$RELEASE_DIR/"
cp "$ROOT/public/dashboard.html" "$RELEASE_DIR/"
mkdir -p "$RELEASE_DIR/public"
mv "$RELEASE_DIR/index.php" "$RELEASE_DIR/public/"
mv "$RELEASE_DIR/dashboard.html" "$RELEASE_DIR/public/"

cp "$ROOT/config.php" "$RELEASE_DIR/"
cp "$ROOT/puls" "$RELEASE_DIR/"
cp "$ROOT/.env.example" "$RELEASE_DIR/"
cp "$ROOT/LICENSE" "$RELEASE_DIR/"
cp "$ROOT/README.md" "$RELEASE_DIR/"
cp "$ROOT/CHANGELOG.md" "$RELEASE_DIR/"

# Copy docs (useful for setup)
mkdir -p "$RELEASE_DIR/docs"
cp "$ROOT/docs/integrations.md" "$RELEASE_DIR/docs/" 2>/dev/null || true

# Create empty data directory with .gitkeep
mkdir -p "$RELEASE_DIR/data"
touch "$RELEASE_DIR/data/.gitkeep"

# Build zip
cd "$BUILD_DIR"
zip -r "$ZIP_FILE" "$RELEASE_NAME"

# Summary
echo ""
echo "Release built: $ZIP_FILE"
echo "Contents:"
cd "$RELEASE_DIR" && find . -type f | sort | sed 's|^./|  |'
echo ""
echo "Size: $(du -h "$ZIP_FILE" | cut -f1)"
