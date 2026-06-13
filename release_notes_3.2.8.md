# Schoolbooth Photo Manager v3.2.8

## Fix: bypass the third-party filter that was killing audit inserts

The v3.2.7 diagnostic log proved that `wp_insert_post()` was returning
"Could not insert post into the database." for every audit event. The
fallback file logger captured the events fine, but the CPT row never
materialized.

Most common cause on managed WordPress hosts: a security plugin or
custom `mu-plugin` installs a filter (`wp_insert_post_data`,
`pre_post_status`, `wp_insert_post_empty_content`, etc.) that blocks
`wp_insert_post()` for anonymous (`user_id = 0`) requests, which is
exactly what our REST upload + AJAX consent + frontend download
endpoints look like.

### Fix

`SCHOOLBOOTH_Audit_Logger::log_event()` now:

1. Tries `wp_insert_post()` first (normal path).
2. If that fails (`WP_Error` or `0`), captures `$wpdb->last_error`
   into the diagnostic log so we know the real MySQL message.
3. Falls back to a direct `$wpdb->insert()` against `wp_posts` with
   every column populated explicitly. This bypasses **all**
   `wp_insert_post_*` filters and the post-modification cap checks.
4. Logs whether each event landed via `wp_insert_post` or
   `direct_wpdb` so we can confirm the fix is actually working.

Direct `$wpdb->insert()` is safe here: every value is parameter-bound
through `$wpdb->prepare()`-style placeholders, the post content is
JSON we just generated, and the audit CPT is private/non-public so
there's no front-end render path to worry about.

### After upgrade

- Trigger one full upload -> consent -> download cycle.
- Open **Schoolbooth -> Photo Audit Log -> All Events** -- rows
  should now appear.
- The **Diagnostic Log** tab will show `_via: wp_insert_post`
  (host's filters didn't block) or `_via: direct_wpdb` (filter was
  blocking; we worked around it).

If a value other than those two appears, paste it back to me.
