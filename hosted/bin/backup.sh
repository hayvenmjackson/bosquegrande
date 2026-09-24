#!/usr/bin/env bash
# Encrypted backup of the database, uploads, and sealed keyrings.
# Usage: bin/backup.sh [output-folder]   (default: ./backups)
# The master key and MariaDB key file are deliberately NOT included; keep them
# separately. Restore steps are in README.md.
set -euo pipefail
cd "$(dirname "$0")/.."
out="${1:-backups}"
mkdir -p "$out"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

docker compose exec -T db sh -c 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb-dump -uroot --single-transaction --routines --triggers --databases wordpress' > "$work/database.sql"
docker compose exec -T wordpress tar -C /var/www/html/wp-content -cf - uploads > "$work/uploads.tar"
docker compose exec -T wordpress tar -C /var/lib -cf - bosquegrande > "$work/state.tar"

tar -C "$work" -czf - database.sql uploads.tar state.tar \
  | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass file:secrets/backup_passphrase \
  > "$out/bosquegrande-$stamp.tar.gz.enc"
chmod 600 "$out/bosquegrande-$stamp.tar.gz.enc"
echo "Wrote $out/bosquegrande-$stamp.tar.gz.enc"
