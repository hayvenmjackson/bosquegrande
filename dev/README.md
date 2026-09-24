# Development checks

These scripts are for working on BosqueGrande itself. They are not part of the plugin or hosted release zips.

**Use a disposable test site.** The tests create and delete people, events, payments, newsletters, grant tags, and QuickBooks mappings. They refuse to run unless `BG_TEST_DISPOSABLE=1` is set.

## Lint

```sh
PHP_BINS="php8.0 php8.3 php8.4" dev/lint.sh
```

Checks every PHP file with each PHP binary listed, and every JavaScript file with `node --check` when Node is installed.

## Functional tests

Set up a throwaway WordPress site with BosqueGrande active and the setup finished far enough that the keyring exists (BosqueGrande > Setup, step 3). Then:

```sh
export BG_TEST_DISPOSABLE=1
export BG_TEST_HOST=localhost:8080     # the host the site answers on
# export BG_TEST_PATH=/riverbend       # on a multisite network, the site to test
for t in keystore registration admin newsletter walkin assistant companions multisite; do
  php dev/tests/$t.php /path/to/wordpress
done
```

| Test | Covers |
| --- | --- |
| `keystore.php` | Plain and sealed keyring files, resealing, wrong and malformed seal keys, key rotation |
| `registration.php` | Registration nonce, a first registration, the confirmation email, repeat registrations that must not overwrite saved answers, duplicates, bad nonces, signed email links |
| `admin.php` | Regions and postal rules, the payment ledger and currency lock, the QuickBooks worksheet with classes by region, grant reports, grant tag keyword rules |
| `newsletter.php` | Queuing and sending to the default group, the one-click unsubscribe header, signed unsubscribe links |
| `walkin.php` | Organizer walk-ins: a saved person is used only on a name plus a matching email or phone; near matches go to staff review |
| `assistant.php` | Floating Assistant companion: training info reaches the right audience, visitors never get staff-only material or the staff reference, the chat route answers and enforces its limits, escalation replaces guesses. Uses a recording stand-in for the service provider, so no key is needed. Skips when the companion is inactive |
| `companions.php` | Floating Actions and Organizer Launcher: actions follow settings, unsafe custom links are dropped, QR codes and capacity tables, launcher audience rules. Skips unless both are active |
| `multisite.php` | On a network: signed links and account links from one site do not work on another, with a shared keyring or per-site keyrings. Skips on a single site |

## HTTP tests

These run against the site over HTTP. Copy `dev/mail-capture.php` into the site's `wp-content/mu-plugins/` first so outgoing mail is written to a file instead of sent.

```sh
BG_TEST_DISPOSABLE=1 BASE=http://localhost:8080 WPDIR=/path/to/wordpress dev/http.sh
```

Covers newsletter sign-up and its double opt-in, the unsubscribe page, forged unsubscribe tokens, neutral answers to sign-in link requests, and the public calendar and My Profile pages. Set `MAIL` if you pointed `BG_TEST_MAIL_LOG` somewhere other than the system temp folder, and `PHP` to pick a PHP binary.
