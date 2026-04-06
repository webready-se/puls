#!/bin/sh
set -e

USERS_FILE="${USERS_FILE:-data/users.json}"
export USERS_FILE

# Generate .env from template if missing
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate APP_KEY if not set (env var or .env file)
if [ -z "$APP_KEY" ] && ! grep -q '^APP_KEY=.\+' .env 2>/dev/null; then
    echo "→ Generating APP_KEY..."
    KEY="base64:$(php -r "echo base64_encode(random_bytes(32));")"
    sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" .env
    echo "  APP_KEY set."
fi

# Write APP_KEY from env var into .env if provided externally
if [ -n "$APP_KEY" ]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" .env
fi

# Set USERS_FILE in .env so config.php picks it up
if grep -q '^USERS_FILE=' .env; then
    sed -i "s|^USERS_FILE=.*|USERS_FILE=$USERS_FILE|" .env
else
    echo "USERS_FILE=$USERS_FILE" >> .env
fi

# Create admin user if ADMIN_PASSWORD is set and users file doesn't exist
if [ -n "$ADMIN_PASSWORD" ] && [ ! -f "$USERS_FILE" ]; then
    echo "→ Creating admin user..."
    php -r "
        \$file = getenv('USERS_FILE') ?: 'data/users.json';
        \$pass = getenv('ADMIN_PASSWORD');
        if (strlen(\$pass) < 8) { fwrite(STDERR, \"ADMIN_PASSWORD must be at least 8 characters.\n\"); exit(1); }
        \$users = ['admin' => [
            'password' => password_hash(\$pass, PASSWORD_BCRYPT, ['cost' => 12]),
            'sites' => [],
        ]];
        \$dir = dirname(\$file);
        if (!is_dir(\$dir)) mkdir(\$dir, 0750, true);
        file_put_contents(\$file, json_encode(\$users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo \"  admin user created.\n\";
    "
fi

# Warn if no users exist
if [ ! -f "$USERS_FILE" ]; then
    echo "⚠ No users configured. Set ADMIN_PASSWORD or run: docker exec <container> php puls user:add admin"
fi

exec "$@"
