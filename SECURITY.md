# Security Policy

MY Files PRO is maintained as a private WordPress plugin for Aminudin Murad.

## Supported Version

Only the latest packaged release is actively maintained.

## Reporting Security Issues

Report security issues privately to Aminudin Murad before sharing details publicly.

Include:

- Plugin version.
- WordPress and PHP versions.
- A concise reproduction path.
- Affected user role.
- Any relevant request payload or browser console error.

## Security Baseline

- REST routes require WordPress nonces and capability checks.
- Folder management requires an allowed role with media upload permission, or an administrator.
- Default folder-manager roles are administrators and editors; non-admin role checks fail closed when no roles are selected.
- Folder deletion stops when the folder contains media the current user cannot edit.
- Requested upload destinations require folder-manager permission; non-managers only receive the configured default upload folder.
- Default upload folder settings are validated against existing folders.
- Settings, import, export, and uninstall cleanup require administrator permissions.
- Import files are restricted to supported JSON or CSV payloads with size and item-count limits.
- Output is escaped at render time; request data is validated and sanitized before use.
- SVG uploads default to "Defer to another plugin or framework", so MY Files PRO registers no SVG upload hooks unless the administrator explicitly enables its managed SVG mode.
- Use the default defer mode when UiCore Framework, Safe SVG, SVG Support, or another SVG handler is active.
- MY Files-managed SVG uploads are limited to administrators with media upload access.
- MY Files-managed SVG uploads are sanitized before WordPress accepts the file.
- MY Files-managed SVG uploads write SVG-specific metadata, suppress raster sub-size generation, and supply Media Library preview dimensions.
- MY Files-managed SVG uploads bypass WordPress' unsupported image MIME guard for SVG files only.
