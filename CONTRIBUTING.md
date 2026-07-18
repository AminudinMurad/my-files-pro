# Contributing

## Workflow

1. Create a focused branch from `main` using `feature/`, `fix/`, or `chore/`.
2. Make one cohesive change and add or update tests.
3. Run `bash tools/check.sh`.
4. Submit the focused change with its test results and any relevant screenshots.

Do not commit generated ZIP files, credentials, customer data, or licensed third-party assets.

## Code expectations

- Follow the WordPress Coding Standards.
- Support the PHP and WordPress versions declared in the plugin header.
- Prefix global symbols and hooks with `my_files_pro_`.
- Check capabilities and nonces before state changes; gate REST routes with nonce + capability callbacks.
- Sanitize input, validate business rules, and escape output at the point of rendering.
- Use prepared SQL for any query that cannot use a WordPress data API.
- Verify `edit_post` per attachment on moves; keep JSON/CSV import limits and opt-in uninstall cleanup intact.
- Keep managed SVG uploads admin-only and sanitized; the default mode defers to other SVG handlers.
- Avoid loading admin assets globally.

## Commits and pull requests

Use short, imperative commit subjects, for example `Add folder duplication`. Pull requests should explain the user-facing outcome, testing performed, screenshots for UI changes, and compatibility or migration impact.

Breaking changes require a migration plan and a clearly documented deprecation period.
