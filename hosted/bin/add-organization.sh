#!/usr/bin/env bash
# Add one organization: a new site, its administrator, and its sealed keyring.
# Usage: bin/add-organization.sh <slug> <admin-email> "<Organization name>"
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a

slug="${1:-}"; email="${2:-}"; name="${3:-}"
if [[ ! "$slug" =~ ^[a-z0-9][a-z0-9-]{1,40}$ ]] || [ -z "$email" ] || [ -z "$name" ]; then
  echo "Usage: bin/add-organization.sh <slug> <admin-email> \"<Organization name>\""
  echo "The slug uses lowercase letters, numbers, and hyphens."
  exit 1
fi

if [ "${BG_SUBDOMAINS}" = "1" ]; then url="https://${slug}.${BG_DOMAIN}/"; else url="https://${BG_DOMAIN}/${slug}/"; fi

# wp site create would name a new account after the slug, which is public in
# the site's address. Create new accounts first, with a login that cannot be
# guessed; people can also sign in with their email address.
if bin/wp.sh user get "$email" --field=ID >/dev/null 2>&1; then
  account="their existing account ($email)"
else
  account="$(bin/wp.sh eval-file - "$slug" "$email" <<'PHP'
<?php
$login = preg_replace('/[^a-z0-9]/', '', (string) $args[0]) . bin2hex(random_bytes(3));
$password = wp_generate_password(20, true, false);
if (!wpmu_create_user($login, $password, (string) $args[1])) {
    fwrite(STDERR, "The account could not be created.\n");
    exit(1);
}
echo $login . ' / ' . $password;
PHP
)"
  account="$account  (new account; ask them to change the password)"
fi

# Creates the site and makes that account its administrator.
site_id="$(bin/wp.sh site create --slug="$slug" --title="$name" --email="$email" --porcelain)"
site_url="$(bin/wp.sh site list --site__in="$site_id" --field=url)"

# Create the schema and the sealed keyring now, and record the name.
bin/wp.sh eval-file - "$name" "$site_id" --url="$site_url" <<'PHP'
<?php
if (get_current_blog_id() !== (int) ($args[1] ?? 0)) {
    fwrite(STDERR, "wp-cli did not select the new site; nothing was written.\n");
    exit(1);
}
$c = \BosqueGrande\Core\Plugin::instance()->container();
if (!$c->get('keys')->ensure()) {
    fwrite(STDERR, 'Keyring could not be created: ' . $c->get('keys')->status() . "\n");
    exit(1);
}
\BosqueGrande\Core\Organization::save(['org_name' => (string) ($args[0] ?? '')]);
echo 'Keyring ready: ' . $c->get('encryption')->activeKeyId() . "\n";
PHP

cat <<EOF

Organization "$name" is ready at $url
Administrator: $account
They should sign in at ${url}wp-admin/ and follow BosqueGrande > Setup.
EOF
