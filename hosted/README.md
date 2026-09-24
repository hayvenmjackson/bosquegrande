# BosqueGrande hosted edition

Runs BosqueGrande for many organizations on one server. Each organization gets its own WordPress site in a multisite network, its own database tables, and its own encryption keys. The code is the same plugin as the WordPress edition. This folder adds a locked-down WordPress image, a must-use plugin, encrypted storage, and scripts to set it all up.

This is an early release. Read "What this protects against" before putting real data in it.

## What you need

- A Linux server with Docker Engine and the Docker Compose plugin.
- A domain name pointing at the server, for example `crm.example.org`. Ports 80 and 443 must be open for automatic HTTPS.
- An outgoing mail service (SMTP relay) for the network's own messages, such as password resets. Set it in `.env` (`BG_SMTP_*`, `BG_MAIL_FROM`) and put its password in `secrets/smtp_password`. Organizations can enter their own relay in BosqueGrande > Correspondence; until they do, their mail goes through yours, sent from `BG_MAIL_FROM` with replies going to the organization.

## Layout

| Path | What it is |
| --- | --- |
| `docker-compose.yml` | Three containers: MariaDB, WordPress (Apache and PHP 8.3), and Caddy for HTTPS |
| `wordpress/` | Image build: hardened PHP and Apache settings, multisite rewrite rules, the start script |
| `mu-plugins/bosquegrande-hosted.php` | Per-organization keyrings, sealed with the master key; lockdown of the public surface and admin |
| `plugin/` | The plugins that go into the image: BosqueGrande and its three companions (included in the release zip) |
| `mariadb/encryption.cnf` | Encrypts database files, logs, and temporary files at rest |
| `Caddyfile` | HTTPS, security headers, and a content security policy for public pages |
| `bin/` | `init.sh`, `install.sh`, `add-organization.sh`, `backup.sh`, `wp.sh` |

## First-time setup

```sh
bin/init.sh          # creates .env and secrets/ (never overwrites existing secrets)
nano .env            # set BG_DOMAIN, BG_ACME_EMAIL, BG_ADMIN_EMAIL, and the mail relay
nano secrets/smtp_password
bin/install.sh       # builds the image, starts everything, creates the network
```

`install.sh` prints the network administrator's password once. Sign in at `https://BG_DOMAIN/wp-admin/network/` and change it.

Right away, copy `secrets/bg_master_key` and `secrets/mariadb_keyfile` to safe offline storage, away from the server and away from backups.

## Adding an organization

```sh
bin/add-organization.sh riverbend director@riverbend.example "Riverbend Community Collective"
```

This creates the site (`https://crm.example.org/riverbend/`), makes that email its administrator, creates the site's database tables and sealed keyring, and records the organization name. For a new account it prints a random login name and a temporary password; the organization can also sign in with its email address. It then follows BosqueGrande > Setup to add its own keys and contacts.

## How the encryption fits together

- **Personal data** (names, emails, phones, addresses, answers, saved API keys) is encrypted by BosqueGrande with XChaCha20-Poly1305 before it reaches the database. Each organization has its own keyring file in `/var/lib/bosquegrande/keyrings/site-N.php`.
- **Keyrings are sealed.** Each keyring file is itself encrypted with a key derived from the master key and the site number. The master key lives only in `secrets/bg_master_key` and is handed to WordPress as a Docker secret. It is never stored in WordPress or the database. A keyring copied to another site's slot will not open.
- **Signed links** (sign-in, unsubscribe, survey, confirmation) use keys from each organization's own keyring. A link from one organization does not work on another's site.
- **The database is encrypted at rest** by MariaDB with `secrets/mariadb_keyfile`, including logs and temporary files.
- **Backups** made by `bin/backup.sh` are encrypted with `secrets/backup_passphrase`. They contain sealed keyrings but not the master key.

## What this protects against

It protects against:
- a stolen database dump, backup file, or disk;
- one organization's data being read through another organization's site;
- ordinary web attacks on the public surface (see the lockdown list below).

It does not protect against someone with root on the running server. They can read the master key and every key it unlocks. The operator (whoever runs the server) is trusted by every organization on it. Say so plainly in your terms of service.

## Lockdown

The must-use plugin and image:
- frame every public page with BosqueGrande's header and footer (calendar, events, registration, giving, My Profile, and any pages the organization publishes). Comments, feeds, author archives, and the users sitemap are off;
- allow anonymous REST calls only to BosqueGrande's own routes; block XML-RPC and application passwords;
- remove plugin, theme, tool, and import screens from organization administrators;
- put the image's code in place on every start, owned by root, so PHP cannot change its own code and a rebuild always takes effect. Only uploads and `/var/lib/bosquegrande` are writable;
- throttle failed logins (10 per 15 minutes per address) and pass real client addresses from Caddy to WordPress;
- disable PHP's shell functions and refuse to run PHP from uploads;
- keep uploaded import spreadsheets in `/var/lib/bosquegrande/private/`, outside the web root, one folder per organization.

## Companion plugins

The image also carries the three companion plugins: Floating Assistant (a question button answered from each organization's own training info), Floating Actions (subscribe, donate, and custom buttons with a QR code), and Organizer Launcher. Organization administrators cannot install or switch plugins in the hosted edition, so the operator turns them on for an organization:

```sh
bin/wp.sh plugin activate bosquegrande-floating-assistant --url=https://crm.example.org/riverbend/
```

or for every organization at once with `--network`. Each organization then manages its own training info and button settings under BosqueGrande in its dashboard, and answers use the service provider key that organization entered in Assisted Tools.

## Routine operation

- **Cron** runs inside the WordPress container every minute for every site; nothing to set up.
- **Updates:** replace `plugin/bosquegrande/` with the new release, pin `WP_IMAGE` to the version you want, then `docker compose build && docker compose up -d`. The container copies the new code into place when it starts, and each site upgrades its tables on its next request. Run `bin/backup.sh` before changing `DB_IMAGE`: MariaDB's own pre-upgrade backup is turned off because it would sit unencrypted in the data volume.
- **Backups:** `bin/backup.sh /path/to/backups`. Run it daily from the host's cron and copy the files offsite.
- **wp-cli:** `bin/wp.sh <command> --url=https://crm.example.org/riverbend/`. It always runs as the web server user, so any keyring it creates stays readable.

## Restoring from a backup

```sh
mkdir -p restore
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:secrets/backup_passphrase \
  -in bosquegrande-XXXX.tar.gz.enc | tar -xzf - -C restore/
docker compose up -d --wait db
docker compose exec -T db sh -c 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" mariadb -uroot' < restore/database.sql
docker compose up -d wordpress
docker compose exec -T wordpress tar -C /var/www/html/wp-content -xf - < restore/uploads.tar
docker compose exec -T wordpress tar -C /var/lib -xf - < restore/state.tar
docker compose exec wordpress chown -R www-data:www-data /var/lib/bosquegrande /var/www/html/wp-content/uploads
```

The restored server needs the same `secrets/bg_master_key`, and a MariaDB volume created with the same `secrets/mariadb_keyfile` if you restore volumes directly instead of from the SQL dump.

## Subdomains instead of folders

Set `BG_SUBDOMAINS=1` before `bin/install.sh` to give each organization `name.crm.example.org`. That needs a wildcard DNS record and a wildcard certificate. Caddy can only get one through a DNS provider plugin, so build a Caddy image with your provider's module and change the site address in `Caddyfile` to `*.{$BG_DOMAIN}, {$BG_DOMAIN}` with a `tls { dns <provider> ... }` block.

## Status

The plugin side (sealed per-site keyrings, per-site tables and storage, cross-site link isolation, the lockdown rules, login throttling, the mail relay, `bin/add-organization.sh`, and the tenant admin screens) was tested on a local multisite network. The compose file, Caddyfile, and scripts were validated, and the start script's code sync was tested on its own, but the full Docker stack has not yet been run end to end on a real server. Try a full install on a test server before inviting organizations.
