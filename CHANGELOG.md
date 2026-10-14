# Changelog

## 1.00.04 - unreleased

- Restore the Statistics tab alongside Settings and Important information.
- Display historical traffic and order-source data with filters, pagination and multishop scope.
- Show an empty state when legacy tables are absent on fresh installations.
- Add Slovak translations and regression tests for statistics navigation and rendering.
- Keep new aggregation disabled and CSV export unavailable; preserve cleanup settings when upgrading from 1.00.03.

## 1.00.03

- Fixed the critical cleanup gap in `connections_source` by deleting rows directly through the indexed `date_add` column.
- Added separate source retention, row-bounded orphan and related-statistics cleanup, and runtime checks between SQL operations.
- Require an uncached atomic MySQL named lock; fail closed when unavailable.
- Added orphan-row counts to cleanup logs.
- Disabled legacy statsdata aggregation during upgrade; analytics now belongs to `jooim_analytics`.
- Disabled automatic `layered_filter_block` clearing during upgrade. It remains available as an explicit opt-in.
- Removed the legacy statistics tab from the cleaner; it is restored in 1.00.04.
- Added an administration warning when PrestaShop `statsdata` is active.

- Added conservative cleanup of anonymous guests from expired connections, preserving customers, all carts and remaining connections across shops.
- Handle failed SELECT/DELETE operations and PHP errors, release locks in finally, and stop cleanup on schema upgrade failure.
- Only mark the schema upgraded after all required schema/configuration writes succeed.
- Preserve Joobox support, GitHub Releases links and update checking; deduplicate footer links regardless of render order or shared markers.
- Added standalone regression tests and a reproducible installation ZIP builder.

## 1.00.02

- Added multishop-aware administration, logs, database overview and legacy source summaries.
