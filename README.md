# BosqueGrande

A CRM that lives in WordPress: people, events, newsletters, and money in one plugin. It ships with no organization data and no connected services. Each organization brings its own keys and its own contacts.

Personal data is encrypted with XChaCha20-Poly1305 before it reaches the database, under a keyring file the organization holds. Lookups use keyed blind indexes.

## Two editions, one plugin

| Folder | Edition | For |
| --- | --- | --- |
| [`bosquegrande/`](bosquegrande/) | WordPress plugin | One organization on its own WordPress site. Install the zip from Plugins > Add New > Upload Plugin. See [`bosquegrande/readme.txt`](bosquegrande/readme.txt). |
| [`hosted/`](hosted/) | Hosted | Many organizations on one server. A Docker stack with a locked-down WordPress multisite, a sealed keyring per organization, and an encrypted database. See [`hosted/README.md`](hosted/README.md). |
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

# Hosted edition, with a copy of the plugin in plugin/bosquegrande
rm -rf dist/bosquegrande-hosted && mkdir -p dist/bosquegrande-hosted/plugin
git archive HEAD:hosted | tar -x -C dist/bosquegrande-hosted
git archive HEAD bosquegrande | tar -x -C dist/bosquegrande-hosted/plugin
(cd dist && zip -qr BosqueGrande-Hosted-v5.0.0.zip bosquegrande-hosted)
```

`bosquegrande/MANIFEST.sha256` lists a SHA-256 hash for every file in the plugin. After unpacking, `sha256sum -c MANIFEST.sha256` inside the plugin folder checks that nothing was changed.

## License

MIT. See [`bosquegrande/LICENSE`](bosquegrande/LICENSE).
