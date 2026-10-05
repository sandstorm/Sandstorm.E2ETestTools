#!/bin/bash
set -eou pipefail

echo "Waiting for database..."
until mariadb -h"${DB_NEOS_HOST}" -P"${DB_NEOS_PORT}" -u"${DB_NEOS_USER}" -p"${DB_NEOS_PASSWORD}" -D"${DB_NEOS_DATABASE}" --disable-ssl --silent -e "SELECT 1;" 1>/dev/null 2>/dev/null; do
    sleep 2
done
echo "Database is ready."

./flow flow:cache:flush
./flow doctrine:migrate
./flow cr:setup
./flow resource:publish --collection static

# no site import: every scenario starts with an empty content repository and creates its own nodes
exec frankenphp run --config /etc/frankenphp/Caddyfile
