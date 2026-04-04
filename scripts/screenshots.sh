#!/bin/bash
# Generate dashboard screenshots for README using seeded demo data.
# Usage: bash scripts/screenshots.sh
#
# Requires: Google Chrome (macOS default path)

set -e

CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
PORT=8765
OUT_DIR="docs/screenshots"
PROFILE="/tmp/puls-screenshots-profile-$$"

cd "$(dirname "$0")/.."

if [ ! -f "$CHROME" ]; then
    echo "Error: Google Chrome not found at $CHROME"
    exit 1
fi

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
    rm -rf "$PROFILE"
    rm -f public/_prime.html
}
trap cleanup EXIT

mkdir -p "$OUT_DIR"

SHARE_URL="http://localhost:$PORT/?share=$TOKEN"

shoot() {
    local name=$1
    local url=$2
    local width=${3:-1400}
    local height=${4:-1900}
    echo "→ $name.png"
    "$CHROME" \
        --headless \
        --disable-gpu \
        --hide-scrollbars \
        --no-first-run \
        --no-default-browser-check \
        --disable-extensions \
        --force-device-scale-factor=2 \
        --window-size=$width,$height \
        --virtual-time-budget=4000 \
        --screenshot="$OUT_DIR/$name.png" \
        "$url" 2>/dev/null || true
}

# For themed views, use a tiny prime page on same origin that sets localStorage and redirects.
prime_and_shoot() {
    local name=$1
    local theme=$2
    local compare=$3
    local width=${4:-1400}
    local height=${5:-1900}

    cat > public/_prime.html <<EOF
<!DOCTYPE html>
<html><body><script>
localStorage.setItem('puls-theme', '$theme');
localStorage.setItem('puls-compare', '$compare');
setTimeout(function() { location.href = '$SHARE_URL'; }, 100);
</script></body></html>
EOF
    shoot "$name" "http://localhost:$PORT/_prime.html" "$width" "$height"
}

# Default theme (respects system) — probably dark for most devs
shoot dashboard "$SHARE_URL"

# Explicit dark with compare mode on
prime_and_shoot dashboard-compare dark 1

# Light mode
prime_and_shoot dashboard-light light 0

echo ""
echo "Screenshots saved to $OUT_DIR/"
ls -la "$OUT_DIR/"*.png
