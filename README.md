# Database Cleaner & Optimization

Deletes old connection/source/page statistics in bounded batches and removes unreferenced anonymous guests. Optional layered-filter cache cleanup and compact aggregate statistics/reporting are included. Optimization here means housekeeping; the name does not promise SQL OPTIMIZE TABLE.

| | |
| --- | --- |
| Module | `jooim_dbcleaner` |
| Source version | `1.00.04` |
| Target platforms | PrestaShop 8.x and 9.x; staging validation required |
| Author | JOO INTERNET MEDIA LTD |

## Features and scope

Manual action or token-protected cron invokes JooimDbcleanerCleaner. Runtime/batch limits bound raw-row cleanup; related records and orphan checks preserve carts/customers. Optional aggregate tables support statistics before raw retention removes records.

## Requirements and compatibility

Use a PHP version supported by your selected PrestaShop release. The full PHP/core version matrix has not been certified. Protect the generated cron URL/token. No third-party account is required.

## Installation

1. Back up shop files/database and use staging first.
2. Download an approved installation ZIP and its SHA256 from the release assets.
3. Verify the checksum and upload the ZIP through Module Manager.
4. Install and select the intended shop before configuration.
5. Test the module’s actual workflow and theme hooks before production.

Do not install GitHub’s automatic **Source code ZIP**. The archive must contain one `jooim_dbcleaner/` root with its module entry point.

## Configuration and usage

JOOIM_DBCLEANER_* uses core Configuration. Defaults: ENABLE=1, RETENTION_DAYS=30, SOURCE_RETENTION_DAYS=7, BATCH_SIZE=5000, MAX_BATCHES=10, MAX_RUNTIME=120, CLEAN_ORPHANS=1, CLEAR_LAYERED_FILTER=0, AGGREGATE_STATS=0, STATS_RETENTION_DAYS=730, STALE_LOCK_TIMEOUT=7200. Cron tokens are generated rather than predefined. The database tables are shared shop infrastructure: do not describe cleanup as isolated deletion of one shop. See source inventory for running/status/schema/update metadata and exact post-process clamps.

## Languages

English source uses the module’s existing PrestaShop translation mechanism. Bundled language coverage varies by module and installed shop languages. Belgian locales use French/Dutch/German variants; Canadian locales use English/French variants. New wording may use English fallback where a locale is incomplete. Native-speaker review is not claimed.

## Troubleshooting and known limitations

Destructive database housekeeping requires a verified backup and realistic database staging. Opening configuration can synchronize schema; this existing behavior is recorded, not redesigned. Shared raw tables need explicit merchant understanding in multishop.

Check the selected shop, configured credentials/options, assigned hooks, PHP extensions and PrestaShop cache first. Redact access URLs, secrets and customer data before sharing diagnostic output.

## Updates, uninstall and recovery

Apply reviewed installation packages on staging, retain a backup and recheck hooks/translations afterward. Source versions and existing releases are independent for each module. No automatic release or remote installation is performed.

Drops module logs and aggregate/attribution tables and deletes its Configuration keys; deleted historical raw statistics cannot be restored without backup.

For rollback, restore matching module files and the corresponding configuration/database backup when necessary.

## License and support

See [LICENSE](LICENSE) for the actual terms.

This free GitHub module is provided without individual technical support. Installation, configuration, production testing, updates and troubleshooting are available to customers of Joobox PrestaShop Cloud or another paid service expressly agreed with JOO INTERNET MEDIA LTD.

[Get Joobox support](https://support.joobox.eu) · [GitHub repository](https://github.com/JOO-INTERNET-MEDIA-LTD/jooim_dbcleaner)
