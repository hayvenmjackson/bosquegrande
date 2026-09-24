#!/usr/bin/env bash
# Run wp-cli inside the WordPress container as the web server user, so any
# keyring or upload it creates stays readable by WordPress.
set -euo pipefail
cd "$(dirname "$0")/.."
exec docker compose exec -T -u www-data -e HOME=/tmp wordpress wp --path=/var/www/html "$@"
