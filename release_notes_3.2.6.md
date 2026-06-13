# Schoolbooth Photo Manager v3.2.6

## Fix: audit log was not capturing events on some hosts

The audit logger registers a custom post type (`schoolbooth_audit_log`)
on `init` and writes events into it via `wp_insert_post()`. On hosts
where the audit logger was constructed during the same `init` action
(common when other plugins/themes preload our bootstrap), the CPT was
not yet registered when the first events tried to log, and
`wp_insert_post()` silently returned 0 -- so events vanished without
any error.

### What changed

- Audit logger now registers the CPT **immediately** if `init` has
  already fired, and on `init` priority 0 otherwise (before any other
  code tries to log an event).
- Before each `wp_insert_post()` call, the logger verifies the CPT is
  registered and registers it on the spot if not.
- All `wp_insert_post()` failures are now written to PHP's
  `error_log()` (visible in `wp-content/debug.log` when `WP_DEBUG_LOG`
  is on) so future regressions are not silent.

### No upgrade dance required

Same plugin slug, same data layout. Upload v3.2.6 over v3.2.5 from
WordPress -> Plugins -> Add New -> Upload Plugin and activate.

New events going forward will appear in **Schoolbooth -> Photo Audit
Log**. (Pre-3.2.6 events that failed to insert cannot be recovered --
they were never written to the database.)
