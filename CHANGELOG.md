# Changelog

## 1.00.03 - unreleased

- Fixed the critical cleanup gap in `connections_source` by deleting rows directly through the indexed `date_add` column.
- Added separate source retention, row-bounded orphan and related-statistics cleanup, and runtime checks between SQL operations.
- Require an uncached atomic MySQL named lock; fail closed when unavailable.
- Added orphan-row counts to cleanup logs.
- Disabled legacy statsdata aggregation during upgrade; analytics now belongs to `jooim_analytics`.
- Disabled automatic `layered_filter_block` clearing during upgrade. It remains available as an explicit opt-in.
- Removed the legacy statistics tab from the cleaner.
- Added an administration warning when PrestaShop `statsdata` is active.

- Added conservative cleanup of anonymous guests from expired connections, preserving customers, all carts and remaining connections across shops.
- Handle failed SELECT/DELETE operations and PHP errors, release locks in finally, and stop cleanup on schema upgrade failure.
- Only mark the schema upgraded after all required schema/configuration writes succeed.
- Preserve Joobox support, GitHub Releases links and update checking; deduplicate footer links regardless of render order or shared markers.
- Added standalone regression tests and a reproducible installation ZIP builder.

## 1.00.02

- Added multishop-aware administration, logs, database overview and legacy source summaries.
