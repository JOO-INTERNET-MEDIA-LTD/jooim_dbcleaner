# Prestashop DB Cleaner

**Prestashop DB Cleaner** is a free module for PrestaShop that helps reduce database size by safely removing old visitor statistics records and, optionally, clearing the faceted/layered navigation cache table.

Repository: https://github.com/JOO-INTERNET-MEDIA-LTD/jooim_dbcleaner  
Author: JOO INTERNET MEDIA LTD  
Module folder name: `jooim_dbcleaner`  
Version: `1.00.04`

## What the module does

The module mainly cleans old traffic statistics that can unnecessarily increase the size of many PrestaShop databases:

- deletes old records from `ps_connections` in bounded batches,
- deletes `ps_connections_source` directly by its own `date_add`, independently of `ps_connections`,
- deletes related and orphaned records from `ps_connections_source` and `ps_connections_page`,
- checks a runtime deadline between SQL operations so large databases continue on the next cron run,
- deletes anonymous guests identified through expired connections only when no connection, cart or customer reference remains,
- can optionally clear the `ps_layered_filter_block` table,
- displays the approximate database size,
- stores logs of recent cleanup runs,
- provides both CLI cron and HTTP cron execution protected by a token.

## What the module does not do

The module intentionally avoids risky or overly aggressive database operations:

- it does not run `OPTIMIZE TABLE` automatically,
- it does not delete `ps_layered_price_index`,
- it does not modify products, orders, customers or categories,
- it does not monitor the physical disk space of the server,
- it does not collect analytics; use the separate `jooim_analytics` module for compact daily statistics.

## Installation for beginners

1. Download the module ZIP file.
2. Open your PrestaShop administration.
3. Go to **Modules → Module Manager**.
4. Click **Upload a module**.
5. Select the ZIP file, for example `jooim_dbcleaner.zip`.
6. After upload, click **Install**.
7. Open the module configuration page.
8. Review the default settings and save them.
9. Copy the generated cron command or HTTP cron URL from the module configuration.
10. Add the cron job in your hosting control panel.
11. After the first run, check the module logs and the reported database size.

## Recommended first configuration

Do not start aggressively on a large database. Test the module with conservative values first:

- **Enable cleanup:** Yes
- **Retention days for connection statistics:** 30
- **Retention days for connections_source:** 7
- **Batch size:** 5000
- **Maximum batches per run:** 10
- **Maximum runtime per execution:** 120 seconds
- **Delete orphaned statistics rows:** Yes
- **Clear ps_layered_filter_block:** No; enable it only when that cache table is demonstrably a problem

If the cron runs quickly and the shop remains stable, you can gradually increase `Batch size` to 5000 or 10000.

## Configuration explained

### Enable cleanup

Enables or disables the actual cleanup. When disabled, the module can remain installed, but cron and manual execution will not delete old statistics.

### Retention days for connection statistics

Defines how many days of original PrestaShop traffic statistics should be kept. For example, the value `60` means the module keeps the last 60 days and deletes older records.

### Retention days for connections_source

Deletes old source rows directly by their own `date_add`, even when no matching row remains in `ps_connections`. This is the critical fix for uncontrolled `ps_connections_source` growth.

### Batch size

Defines how many main records from `ps_connections` may be processed in one batch. A smaller value is safer but slower. A larger value cleans faster but may put more load on the database.

### Maximum batches per run

Defines how many batches the module may process during one cron run. The approximate amount of work per run is:

```text
Batch size × Maximum batches per run
```

### Clear ps_layered_filter_block

Optionally clears the cache table `ps_layered_filter_block`. This does not delete products, categories or the price index. PrestaShop or the faceted search module will rebuild the cache when needed.

### Cleanup locking and runtime

An atomic MySQL/MariaDB named lock prevents concurrent cleaners for the same database and prefix. If named locks are unavailable, cleanup returns an error without deleting data. No non-atomic configuration fallback is used. The old stale-lock setting is retained internally for compatibility but is no longer used or displayed.

The runtime deadline is checked between SQL operations. It cannot interrupt a running query or guarantee a hard wall-clock limit. Related statistics are deleted in bounded row batches; a parent connection is retained when the deadline is reached before child cleanup finishes. Module-data purges are bounded too. The optional cache TRUNCATE remains a single operation.

### Safe guest cleanup

`guest` has no creation timestamp in the standard PrestaShop 8/9 schema. The cleaner only considers guest IDs taken from connections older than the configured connection retention. After removing those connections, it deletes an anonymous guest only when `id_customer` is zero or NULL, no cart references it, and no connection remains in any shop. Guests without verifiable age, guests linked to customers or carts, and guests with recent connections are retained. If a run reaches its deadline immediately after connection removal, its guest candidates are conservatively retained; this release does not sweep historical orphan guests of unknown age.

All SQL uses `_DB_PREFIX_`; `ps_` in examples is only a conventional prefix. Third-party tables with custom guest references are outside this cleanup scope.

## Cron

CLI cron is recommended because it is more stable for large databases:

```bash
php /path/to/prestashop/modules/jooim_dbcleaner/cron.php --token=TOKEN
```

HTTP cron is available as an alternative:

```text
https://your-shop.com/module/jooim_dbcleaner/cron?token=TOKEN
```

You can find the token in the module configuration. Do not publish the token and do not commit it to GitHub.

## Joobox frontend attribution link

The module displays a small attribution link in the shop footer:

```text
Prestashop module by joobox.eu
```

The text `Prestashop module by` is translatable. The fixed text `joobox.eu` links to:

```text
https://joobox.eu
```

The module uses the `displayFooter` hook for the frontend attribution link.

If another installed `jooim_` module has already displayed the Joobox attribution link in the footer, this module will not display a duplicate link.

## License

Use of this module is governed by the [LICENSE](LICENSE) file. The module is free to use, but the visible frontend attribution link to `https://joobox.eu` must remain active as provided by the module.


## Update check

The module includes a safe GitHub update checker in the module configuration page. It compares the installed module version with the latest GitHub Release tag and shows whether a newer version is available.

The module does not download, unzip or overwrite files automatically. This is intentional for safety, especially on production shops. To update the module, download the installation ZIP from the latest GitHub Release assets and upload it manually in PrestaShop administration.

Do not use GitHub automatic `Source code (zip)` or `Source code (tar.gz)` files for PrestaShop installation. These archives are generated by GitHub for developers and may contain a folder name that is not valid for direct PrestaShop module installation.


## Upgrade without uninstalling

1. Keep the existing module installed; uninstalling deletes its configuration and module tables.
2. Upload the installation ZIP from GitHub Release assets, not GitHub's automatic Source code ZIP.
3. Open module configuration to apply the checked schema upgrade.
4. Review the new seven-day source retention and enabled orphan cleanup. Existing connection retention and batch settings remain unchanged. Legacy aggregation and automatic layered-cache clearing are disabled during upgrade.
5. Check cron, update status and cleanup logs on a staging copy first.

The Statistics tab displays existing historical traffic and order-source summaries with filters and pagination. Fresh installations without legacy tables display an empty-state message. CSV export remains unavailable. Existing aggregate tables are preserved during upgrade, but old rows are still purged according to the previous retention setting. This module does not install `jooim_analytics` or automatically transfer data to it.

Cleanup acts on the shared database in multishop. Guest protections check all shops; displayed logs follow the selected Back Office shop context.

## Version 1.00.04

Restores the Statistics tab alongside Settings and Important information. Existing historical traffic and order-source summaries remain available with filters, pagination and multishop filtering. Fresh installations without legacy tables show an empty state. New aggregation remains disabled and CSV export remains unavailable.

Upgrading from 1.00.03 keeps existing cleanup settings and does not reapply the 1.00.03 configuration defaults.

## Version 1.00.03

This maintenance release fixes the critical cleanup gap in `ps_connections_source`. Previous versions deleted source rows only when their parent `ps_connections` row was selected. Version 1.00.03 also deletes source rows directly by `connections_source.date_add`, adds orphan cleanup, an atomic MySQL lock and a runtime deadline checked between SQL operations.

For new page, product, category, CMS, manufacturer and faceted-filter statistics use the separate `jooim_analytics` module. Legacy aggregate tables remain readable in the Statistics tab; new aggregation remains disabled.


## Development and release preparation

- Run `php tests/run.php` (PHP with PDO SQLite). The standalone tests exercise cleanup SQL against an in-memory SQLite database with MySQL date/DELETE LIMIT syntax adapted by the test adapter; they do not replace MySQL/MariaDB or PrestaShop integration tests.
- Run `python3 tools/build-release.py` to create `dist/jooim_dbcleaner-v1.00.04.zip` and its SHA-256 checksum. The ZIP contains only installable module files under `jooim_dbcleaner/`.
- See [RELEASE.md](RELEASE.md) for release notes and the remaining PrestaShop 8/9 staging checks.
