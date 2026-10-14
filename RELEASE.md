# Release preparation: v1.00.04

Status: release assets prepared; GitHub Release publication pending.

Suggested release title: `PrestaShop DB Cleaner v1.00.04`

## Release notes

Restores the missing Statistics tab alongside Settings and Important information.

- Display existing historical traffic and order-source summaries with filters, pagination and multishop filtering.
- Show an empty state when legacy statistics tables are absent on fresh installations.
- Add Slovak translations and regression coverage for statistics navigation and rendering.
- Preserve existing cleanup settings when upgrading from 1.00.03.

New statistics collection remains disabled and CSV export remains unavailable. Joobox support, GitHub links, update checking and footer attribution are preserved.

## Installation and upgrade

Upload the attached `jooim_dbcleaner-v1.00.04.zip` through PrestaShop Module Manager. Upgrade without uninstalling the existing module. Do not use GitHub's automatic Source code ZIP.

## Validation

Standalone cleaner, upgrade and Statistics tab regression tests and changed-file PHP syntax checks are run during release preparation. Verify the generated ZIP's integrity and source parity. Live PrestaShop 8.x/9.x, MySQL/MariaDB and browser integration checks remain outside the standalone test environment.

## Publication

Before creating the release, merge the version bump and Statistics fix into the target branch. Create tag `v1.00.04` from that merged revision. Confirm PHP and `config.xml` both declare 1.00.04; naming a GitHub Release alone does not change the installed module version.

Run `python3 tools/build-release.py` and attach `dist/jooim_dbcleaner-v1.00.04.zip` plus its `.sha256` file. Do not reuse the older v1.00.03 package. Local documentation, tests and development tools are excluded from the installation ZIP.
