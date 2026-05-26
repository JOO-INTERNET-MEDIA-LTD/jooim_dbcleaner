# Prestashop DB Cleaner

**Prestashop DB Cleaner** is a free module for PrestaShop that helps reduce database size by safely removing old visitor statistics records and, optionally, clearing the faceted/layered navigation cache table.

Repository: https://github.com/JOO-INTERNET-MEDIA-LTD/jooim_dbcleaner  
Author: JOO INTERNET MEDIA LTD  
Module folder name: `jooim_dbcleaner`  
Version: `1.00.00`

## What the module does

The module mainly cleans old traffic statistics that can unnecessarily increase the size of many PrestaShop databases:

- deletes old records from `ps_connections`,
- deletes related records from `ps_connections_source`,
- deletes related records from `ps_connections_page`,
- can save simple daily traffic source summaries before deleting old records,
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
- it does not store IP addresses, full referrer URLs or user-agent strings in its own aggregated statistics.

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
- **Retention days for connection statistics:** 60
- **Batch size:** 1000
- **Maximum batches per run:** 5
- **Clear ps_layered_filter_block:** No for the first test; enable it only after verifying the basic cleanup
- **Aggregate traffic source stats before deletion:** Yes, if you want to keep a simple summary of traffic sources
- **Aggregated stats retention days:** 730
- **Stale lock timeout in seconds:** 7200

If the cron runs quickly and the shop remains stable, you can gradually increase `Batch size` to 5000 or 10000.

## Configuration explained

### Enable cleanup

Enables or disables the actual cleanup. When disabled, the module can remain installed, but cron and manual execution will not delete old statistics.

### Retention days for connection statistics

Defines how many days of original PrestaShop traffic statistics should be kept. For example, the value `60` means the module keeps the last 60 days and deletes older records.

### Batch size

Defines how many main records from `ps_connections` may be processed in one batch. A smaller value is safer but slower. A larger value cleans faster but may put more load on the database.

### Maximum batches per run

Defines how many batches the module may process during one cron run. The approximate amount of work per run is:

```text
Batch size × Maximum batches per run
```

### Clear ps_layered_filter_block

Optionally clears the cache table `ps_layered_filter_block`. This does not delete products, categories or the price index. PrestaShop or the faceted search module will rebuild the cache when needed.

### Aggregate traffic source stats before deletion

Before deleting old records, the module can save a compact daily summary of traffic sources. This is a lightweight overview, not a full analytics replacement.

### Aggregated stats retention days

Defines how long daily summaries should be kept in the module table `ps_jooim_dbcleaner_traffic_daily`.

### Stale lock timeout in seconds

Prevents two cleanup processes from running at the same time. If a previous run gets stuck, the lock is considered stale after this number of seconds.

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
