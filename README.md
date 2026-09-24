# BosqueGrande

A CRM that lives in WordPress: people, events, newsletters, and money in one plugin. It ships with no organization data and no connected services. Each organization brings its own keys and its own contacts.

Personal data is encrypted with XChaCha20-Poly1305 before it reaches the database, under a keyring file the organization holds. Lookups use keyed blind indexes.

## Two editions, one plugin

| Folder | Edition | For |
| --- | --- | --- |
| [`bosquegrande/`](bosquegrande/) | WordPress plugin | One organization on its own WordPress site. Install the zip from Plugins > Add New > Upload Plugin. See [`bosquegrande/readme.txt`](bosquegrande/readme.txt). |
| [`hosted/`](hosted/) | Hosted | Many organizations on one server. A Docker stack with a locked-down WordPress multisite, a sealed keyring per organization, and an encrypted database. See [`hosted/README.md`](hosted/README.md). |
| [`companions/`](companions/) | Companion plugins | Optional add-ons that need BosqueGrande: **Floating Assistant** (a question button answered from training info you upload, with your own service provider key), **Floating Actions** (subscribe, donate, and custom buttons with a QR code), and **Organizer Launcher** (who sees the Organizer App button, and where). Each has its own readme.txt. |
| [`dev/`](dev/) | Development | Lint and functional tests for disposable test sites. See [`dev/README.md`](dev/README.md). |

Both editions run the same plugin code. The hosted edition adds a must-use plugin, server configuration, and setup scripts around it.

## Requirements

- WordPress 6.4 or later (tested through 7.0)
- PHP 8.0 or later with the sodium extension (tested on 8.3 and 8.4)
- MySQL or MariaDB, as WordPress requires (tested on MariaDB 10.11)

## Building the release zips

From a clean checkout:

```sh
mkdir -p dist
# WordPress plugin
git archive --format=zip -o dist/BosqueGrande-v5.0.0.zip HEAD bosquegrande

# Companion plugins
git archive --format=zip -o dist/BosqueGrande-Floating-Assistant-v5.0.0.zip HEAD:companions bosquegrande-floating-assistant
git archive --format=zip -o dist/BosqueGrande-Floating-Actions-v5.0.0.zip HEAD:companions bosquegrande-floating-actions
git archive --format=zip -o dist/BosqueGrande-Organizer-Launcher-v5.0.0.zip HEAD:companions bosquegrande-organizer-launcher

# Hosted edition, with the plugin and companions in plugin/
rm -rf dist/bosquegrande-hosted && mkdir -p dist/bosquegrande-hosted/plugin
git archive HEAD:hosted | tar -x -C dist/bosquegrande-hosted
git archive HEAD bosquegrande | tar -x -C dist/bosquegrande-hosted/plugin
git archive HEAD:companions | tar -x -C dist/bosquegrande-hosted/plugin
(cd dist && zip -qr BosqueGrande-Hosted-v5.0.0.zip bosquegrande-hosted)
```

`bosquegrande/MANIFEST.sha256` lists a SHA-256 hash for every file in the plugin. After unpacking, `sha256sum -c MANIFEST.sha256` inside the plugin folder checks that nothing was changed.

## License

MIT. See [`bosquegrande/LICENSE`](bosquegrande/LICENSE).
