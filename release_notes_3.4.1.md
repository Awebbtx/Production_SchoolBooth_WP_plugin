# Schoolbooth Photo Manager v3.4.1

Bug-fix release on top of v3.4.0.

## Fixed

- **Download All / Delete All buttons on the portal were inert.** Both buttons
  now work. They iterate the visible photo cards and reuse the existing
  per-photo download URLs / `schoolbooth_delete_photo` endpoint, so no new
  server endpoints or permissions were added.
  - "Download All" spaces the per-photo downloads ~800 ms apart so browsers
    don't suppress the burst, shows a live `(done/total)` progress label,
    then refreshes the page so download-remaining counters update.
  - "Delete All" requires a confirmation (`Delete ALL photos for this access
    code? This cannot be undone.`), then fires the existing single-photo
    AJAX delete for each card. Still admin-only (`manage_options`).

## Compatibility

Fully backward compatible with v3.4.0. No data model changes, no migration.
