# Schoolbooth Photo Manager v3.2.7

## Diagnostic release: surface why audit events are not appearing

v3.2.6's fix didn't help on your host -- events still aren't landing in
the CPT. Rather than guess again, this release adds a **fallback log
file** that captures every audit event independently of the database,
plus a **Diagnostic Log** tab in the admin viewer to read it.

### Behavior changes

- Every call to `log_event()` now writes one JSON line to
  `wp-content/uploads/schoolbooth/data/audit-fallback.log` *before*
  attempting `wp_insert_post()`.
- If `wp_insert_post()` fails (returns `WP_Error` or 0), the failure
  reason is appended to the same file as a `_error` entry.
- If `wp_insert_post()` succeeds, an `_inserted_post_id` confirmation
  is appended.
- New tab **Schoolbooth -> Photo Audit Log -> Diagnostic Log** shows
  the last 200 lines of that file.

### How to use after upgrade

1. Install v3.2.7 and activate.
2. Take one photo on the photobooth, scan the QR, complete the
   consent form, download the image (the same flow as before).
3. Visit **Schoolbooth -> Photo Audit Log** and click the
   **Diagnostic Log** tab.

You will see one of three patterns:

- **Lines for upload, access_code_gen, form_submission,
  download_attempt, plus an `_inserted_post_id` after each one.**
  Then the All Events tab is the bug -- send me a screenshot of the
  Diagnostic Log and I will fix the viewer query.
- **Lines for the events but `_error` entries instead of
  `_inserted_post_id`.** That tells us exactly why
  `wp_insert_post()` is failing on your host (capability filter,
  database error, etc.). Send me the `_error` text.
- **No lines at all (empty file or "file does not exist").** That
  means `log_event()` is never being called. Likely cause: the
  upload / consent / download flows are bypassing the plugin code
  entirely -- send a screenshot and I will trace the call sites.

No code paths other than auditing have changed.
