# Schoolbooth Photo Manager v3.2.9

## Real fix: shorten the audit log post_type slug to fit WP's 20-char limit

v3.2.8 surfaced the actual error from WordPress:

> Processing the value for the following field failed: post_type.
> The supplied value may be too long or contains invalid data.

The audit log custom post type was registered as
`schoolbooth_audit_log` -- which is **21 characters**. WordPress
enforces a hard **20-character maximum** on `post_type` values
(`wpdb::process_field()` and `register_post_type()`). Every
`wp_insert_post()` call was being rejected by that check, and
`WP_Query` was refusing to return rows whose `post_type` value
violated the same check (which is why even though v3.2.8's direct
`$wpdb->insert()` did persist rows, the All Events tab was empty).

### Changes

- Renamed the audit CPT slug from `schoolbooth_audit_log` (21 chars)
  to `sb_audit_log` (12 chars).
- Added a one-time, idempotent migration that runs on first plugin
  load and renames any legacy rows already in `wp_posts` from the
  21-char slug to the new slug. Records the migration in
  `wp_options` (`schoolbooth_audit_cpt_migrated_v1`) so it never
  re-runs.
- The fallback file logger and direct-`$wpdb->insert()` path from
  v3.2.7/v3.2.8 are kept as belt-and-suspenders.

### After upgrade

1. Install v3.2.9 and activate.
2. Visit **Schoolbooth -> Photo Audit Log -> All Events**. The 5
   rows logged under v3.2.8 should appear, plus any new ones from
   subsequent uploads/consents/downloads.
3. The **Diagnostic Log** tab should now show
   `_via: wp_insert_post` (no longer falling back to `direct_wpdb`).

If new events still go through `direct_wpdb` after upgrading, paste
the diagnostic log -- there may be a second filter blocking writes
that we haven't seen yet.
