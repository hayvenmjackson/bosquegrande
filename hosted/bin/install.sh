#!/usr/bin/env bash
# First-time setup: build and start the stack, install WordPress as a
# multisite network, and network-activate BosqueGrande.
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a

[ -s secrets/bg_master_key ] || { echo "Run bin/init.sh first."; exit 1; }
[ -d plugin/bosquegrande ] || { echo "plugin/bosquegrande is missing. Unpack the plugin there (see README.md)."; exit 1; }

docker compose build
docker compose up -d
echo "Waiting for WordPress to start..."
for _ in $(seq 1 60); do
  docker compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null && break
  sleep 3
done

if bin/wp.sh core is-installed 2>/dev/null; then
  echo "WordPress is already installed."
else
  password="$(openssl rand -base64 18)"
  bin/wp.sh core install \
    --url="https://${BG_DOMAIN}" \
    --title="${BG_NETWORK_TITLE}" \
    --admin_user="${BG_ADMIN_USER}" \
    --admin_email="${BG_ADMIN_EMAIL}" \
    --admin_password="${password}" \
    --skip-email
  echo "Network administrator: ${BG_ADMIN_USER} / ${password}  (change it after signing in)"
fi

if ! docker compose exec -T wordpress test -f /var/lib/bosquegrande/network-installed; then
  subdomains=()
  [ "${BG_SUBDOMAINS}" = "1" ] && subdomains=(--subdomains)
  bin/wp.sh core multisite-convert --title="${BG_NETWORK_TITLE}" --skip-config "${subdomains[@]}"
  docker compose exec -T -u www-data wordpress touch /var/lib/bosquegrande/network-installed
fi

bin/wp.sh plugin activate bosquegrande --network
echo
echo "Done. Sign in at https://${BG_DOMAIN}/wp-admin/network/ and add organizations with bin/add-organization.sh."
