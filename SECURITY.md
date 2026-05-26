# Security Policy

## Supported version

The currently supported version is:

| Version | Supported |
| --- | --- |
| 1.00.00 | Yes |

## Security recommendations

- Do not publish your cron token.
- Do not commit cron URLs with real tokens to GitHub or any public repository.
- Test the module on a staging copy of the shop before using it on a production store.
- Create a full database backup before the first production cleanup.
- For large databases, use CLI cron instead of HTTP cron whenever possible.
- Start with a small batch size and increase it only after verifying that the shop remains stable.
- Restrict access to the PrestaShop administration and hosting control panel.
- Keep PrestaShop, PHP and server software updated.

## Reporting security issues

Please do not report security issues in public GitHub issues.

For security-related reports, contact JOO INTERNET MEDIA LTD through Joobox support:

https://joobox.eu/en-gb/support

Include a clear description of the issue, affected version, steps to reproduce it and any relevant logs. Do not include real customer data, passwords, API keys or cron tokens.
