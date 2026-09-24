#!/usr/bin/env bash
# Create .env and every secret the hosted edition needs. Safe to rerun:
# existing secrets are never replaced.
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { cp .env.example .env; echo "Created .env from .env.example. Edit BG_DOMAIN and the email addresses."; }

mkdir -p secrets
chmod 700 secrets

make_secret() { # name, generator command
  local file="secrets/$1"
  if [ -s "$file" ]; then echo "kept    secrets/$1"; return; fi
  umask 077
  eval "$2" > "$file"
  echo "created secrets/$1"
}

make_secret db_password        'openssl rand -base64 33 | tr -d "\n"'
make_secret db_root_password   'openssl rand -base64 33 | tr -d "\n"'
for salt in wp_auth_key wp_secure_auth_key wp_logged_in_key wp_nonce_key \
            wp_auth_salt wp_secure_auth_salt wp_logged_in_salt wp_nonce_salt; do
  make_secret "$salt" 'openssl rand -base64 48 | tr -d "\n"'
done
# 32 random bytes, base64: seals every organization's keyring.
make_secret bg_master_key      'openssl rand -base64 32 | tr -d "\n"'
# MariaDB at-rest encryption key (key id 1, 256-bit hex).
make_secret mariadb_keyfile    'printf "1;%s\n" "$(openssl rand -hex 32)"'
# Passphrase for bin/backup.sh archives.
make_secret backup_passphrase  'openssl rand -base64 33 | tr -d "\n"'
# Password for the operator's mail relay (BG_SMTP_* in .env). Starts empty.
if [ ! -e secrets/smtp_password ]; then
  : > secrets/smtp_password
  echo "created secrets/smtp_password (empty: put your mail relay password in it)"
fi

# The containers read these as non-root users; the folder itself stays private.
chmod 444 secrets/*
chmod 400 secrets/backup_passphrase

cat <<'NOTE'

Store a copy of secrets/bg_master_key and secrets/mariadb_keyfile somewhere
safe and offline, separate from backups. Without the master key no
organization's data can be read; without the MariaDB key file the database
cannot be opened.
NOTE
