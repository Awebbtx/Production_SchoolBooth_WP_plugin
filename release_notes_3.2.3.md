# Schoolbooth Photo Manager v3.2.3

## Bug fixes

### QR code scan: "You must complete the permissions form before downloading"
Scanning a photo's QR code took users straight to a `wp_die()` error
page telling them to complete the form first, but never sent them to
the form. The download handler now redirects to the download portal
when the permissions form hasn't been completed — the portal renders
the consent form automatically, so the user just sees the form and can
fill it out without hitting a dead end.

### "An error occurred while processing your form. Please try again"
Two problems:

1. **JS swallowed real server errors.** jQuery routes every non-2xx
   HTTP response (400 validation, 403 nonce, 429 rate limit, 500 server
   error) into its `error:` callback, and our handler ignored
   `xhr.responseJSON`, so users saw a generic "An error occurred"
   regardless of what actually happened. Now the JS parses
   `responseJSON` and surfaces the real server message ("Please correct
   the errors below", "Security verification failed", validation
   details, etc.).

2. **Audit log write failures killed valid consent submissions.** If
   the audit custom post type couldn't be written (DB error,
   permissions, plugin conflict), the form returned 500 even though
   consent had been validly given. We now log the audit failure to
   `error_log` and continue setting the consent cookie so the user can
   proceed to their photos.
