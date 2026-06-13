# Schoolbooth Photo Manager v3.3.0

Auditing is now stable. This release removes the diagnostic
scaffolding from v3.2.7-v3.2.9 and ships a real, full-featured audit
log viewer.

## What is new

### Searchable, filterable event log

The **All Events** tab now has a filter bar with:

- **Free-text search** across the entire event payload (file name,
  access code, consent name / email, IP, reason, source, JSON data).
- **Event type** dropdown (upload, access code generated, download
  attempt, consent form submission, manual delete, auto delete).
- **Status** dropdown (success / failure).
- **Date range** (From / To, calendar pickers).
- **Reset** button to clear all filters.
- Live "N matching events" count.

Results are paginated 50 per page. Every row has a `+` toggle that
expands the full event JSON inline, including digests, prev_digest,
data payload, and post ID.

### CSV export

The **Export CSV** button on the filter bar exports **the currently
filtered set** (not all events, not just the visible page) to a UTF-8
CSV with a BOM so Excel opens it cleanly. Columns:

`post_id, timestamp_utc, event_type, status, user_id, ip_address,
file, access_code, consent_name, consent_email, email_domain, reason,
source, downloads_used, digest, prev_digest, data_json`

Capability check: only users with `manage_options` or
`schoolbooth_audit_read` can export. Nonce-protected
(`admin-post.php`).

### Cleanup of v3.2.7-v3.2.9 diagnostics

- The "Diagnostic Log" admin tab is removed.
- The fallback file logger no longer writes a copy of every event.
  It now only writes a line if `wp_insert_post()` AND the direct
  `$wpdb->insert()` fallback both fail (i.e. the event was
  unrecoverable). This file is rare/empty in normal operation.
- All `_via`, `_inserted_post_id`, and per-event success markers
  are gone.

The audit CPT slug fix from v3.2.9 (`sb_audit_log`, 12 chars) and
the direct-`$wpdb` insert fallback from v3.2.8 are kept.

## Upgrade

Upload v3.3.0 over v3.2.9 from **Plugins -> Add New -> Upload
Plugin**. No data migration needed -- v3.2.9 already migrated the
legacy 21-char rows.
