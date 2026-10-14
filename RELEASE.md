# Release preparation: v1.00.03

Status: release assets prepared; GitHub Release publication pending.

Suggested commit title: `Fix bounded statistics cleanup and safely remove expired guests`

Suggested release title: `v1.00.03 — safer database cleanup`

## Release notes

- Clean old connections and sources using the installation's database prefix; sources also have independent retention.
- Remove anonymous guests identified from expired connections only when no customer, cart or remaining connection references them. Guests of unknown age are retained.
- Bound related and orphan page deletes by actual rows and check a runtime deadline between operations.
- Require an atomic MySQL lock, bypass lock-query caching and fail closed if locks are unavailable.
- Handle failed queries and PHP errors, release locks on errors, and validate schema upgrades before recording their version.
- Preserve Joobox support buttons, GitHub links and the update checker. Improve footer deduplication for later links and links without shared module markers.
- Remove legacy aggregation, the statistics tab and CSV export; retain legacy tables during upgrade. Disable automatic layered-cache clearing on upgrade; it remains an explicit option.

## Build and checks

Run `php tests/run.php`, lint all PHP files, then run `python3 tools/build-release.py`.

Upload `dist/jooim_dbcleaner-v1.00.03.zip` and its `.sha256` file as Release assets. Do not upload the original root ZIP: it is the uncorrected input archive. The builder excludes Git metadata, test/development files and other ZIPs.

Before publishing, test installation and upgrade from 1.00.02 on PrestaShop 8.x and 9.x with supported PHP and MySQL/MariaDB. Verify custom prefixes, two concurrent cron processes, multishop guest protections, runtime interruption, Joobox support/update panels, and footer deduplication before and after another module renders. No live PrestaShop integration environment is included in this repository.

The public GitHub Releases list checked on 2026-10-14 showed v1.00.02 as the latest release. Recheck whether tag `v1.00.03` already exists before creating the GitHub Release. If it has already been published, use a new version consistently in PHP, XML, README, changelog, tests and release notes instead of replacing a released asset silently.

Upgrade without uninstalling. Review seven-day source retention and enabled orphan cleanup; existing connection retention and batch settings remain unchanged. Runtime limits are cooperative, not hard query timeouts.
