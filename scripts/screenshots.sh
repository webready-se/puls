#!/bin/bash
# Generate dashboard screenshots for README using seeded demo data.
# Usage: bash scripts/screenshots.sh
#
# Requires: Google Chrome (macOS default path), Python 3 with Pillow
# Uses URL hash params (#theme=dark&compare=1&tabs=...) to control
# dashboard state — no redirects needed, Chrome captures in one load.

set -e

CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
PORT=8765
OUT_DIR="docs/screenshots"

cd "$(dirname "$0")/.."

if [ ! -f "$CHROME" ]; then
    echo "Error: Google Chrome not found at $CHROME"
    exit 1
fi

python3 -c "from PIL import Image" 2>/dev/null || {
    echo "Error: Python Pillow required for image cropping"
    echo "  pip3 install Pillow"
    exit 1
}

echo "→ Seeding demo database..."
php scripts/seed-demo.php data/demo.sqlite > /tmp/seed-output.txt
TOKEN=$(grep -oE 'Share token: [a-f0-9]+' /tmp/seed-output.txt | awk '{print $3}')
echo "  token: $TOKEN"

echo "→ Starting dev server on port $PORT..."
lsof -ti:$PORT 2>/dev/null | xargs kill 2>/dev/null || true
DB_PATH=data/demo.sqlite php -S localhost:$PORT -t public > /dev/null 2>&1 &
SERVER_PID=$!
sleep 1

cleanup() {
    kill $SERVER_PID 2>/dev/null || true
}
trap cleanup EXIT

mkdir -p "$OUT_DIR"

BASE="http://localhost:$PORT/?share=$TOKEN"

shoot() {
    local name=$1
    local hash=$2
    local width=${3:-1400}
    local height=${4:-1900}
    echo "→ $name.png (${width}x${height})"
    "$CHROME" \
        --headless \
        --disable-gpu \
        --hide-scrollbars \
        --no-first-run \
        --no-default-browser-check \
        --disable-extensions \
        --force-device-scale-factor=2 \
        --window-size=$width,$height \
        --screenshot="$OUT_DIR/$name.png" \
        "${BASE}#${hash}" 2>/dev/null || true
}

echo ""
echo "Taking screenshots..."

# 1. Hero: dark mode, default tabs
shoot dashboard "theme=dark"

# 2. Compare mode: dark, previous period overlay
shoot dashboard-compare "theme=dark&compare=1"

# 3. Events + Countries tabs active
shoot events 'theme=dark&tabs={"traffic":"events","visitors":"countries"}'

# 4. Full page capture (tall) — will be cropped to bots + broken links
shoot dashboard-full "theme=dark" 1400 4000

# 5. Light mode
shoot dashboard-light "theme=light"

# Crop bots + broken links section from the full-page capture (42%–88% of height).
if [ -f "$OUT_DIR/dashboard-full.png" ]; then
    echo "→ Cropping bots.png from full-page capture..."
    python3 -c "
from PIL import Image
img = Image.open('$OUT_DIR/dashboard-full.png')
w, h = img.size
cropped = img.crop((0, int(h * 0.42), w, int(h * 0.88)))
cropped.save('$OUT_DIR/bots.png')
print(f'  {cropped.size[0]}x{cropped.size[1]}px')
"
    rm -f "$OUT_DIR/dashboard-full.png"
fi

echo ""
echo "Screenshots saved to $OUT_DIR/"
ls -la "$OUT_DIR/"*.png
