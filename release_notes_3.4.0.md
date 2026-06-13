# Schoolbooth Photo Manager v3.4.0

## Multi-photo sessions (WP foundation)

This release adds server-side support for grouping photos into open-ended
sessions. The corresponding photobooth client changes ("Single shot" /
"Multi shot" mode toggle + "Start New Session" button) will land in the
next photobooth build; v3.4.0 is the WordPress data-model + audit-grouping
groundwork and is fully backward compatible with single-shot uploads.

### How sessions are represented

The existing one-access-code-per-photo data model is unchanged. A new
optional `session_id` field tags each photo with the session it was
captured in. The booth controls the session lifetime entirely:

- Single-shot mode: photos are uploaded with **no** `session_id` -- v3.3.x
  behavior preserved exactly.
- Multi-shot mode: the booth generates a `session_id` once, reuses **one
  shared access code** for every photo in the session, and tags every
  upload with the same `session_id`. The session ends when the operator
  presses "Start New Session" on the booth, at which point the booth
  prints **one session QR** (no per-photo QRs) and rotates to a new
  session_id + access code.

Because the access code is shared, the existing portal `get_photos_data()`
already returns the whole session as a gallery -- no template changes
needed. Consent is keyed by the access code, so one consent covers all
session photos automatically.

### What changed in WordPress

- **`POST /schoolbooth/v1/ingest`** accepts a new optional `session_id`
  field. Format: `^[a-z0-9_]{1,64}$` (lowercase alphanumeric + underscore,
  max 64 chars). Invalid values are silently dropped.
- `access_codes.json` records now persist `session_id` when supplied.
- **All audit events** related to a photo now carry `session_id` in
  their `data` payload when known: `upload`, `access_code_gen`,
  `download_attempt` (success + every failure reason), `form_submission`
  (success + validation_failed), `manual_delete`.
- **Audit Log viewer** shows a clickable `session_id` chip on every row
  that has one -- clicking it filters the log to just that session's
  events (uses the existing free-text search filter).
- **CSV export** now includes a `session_id` column.

### What does NOT change

- Single-shot uploads remain identical at the wire level and in the
  data file.
- No data migration required. Existing photos have no `session_id` and
  continue to work as today.
- Consent cookie keying is unchanged (still keyed by access code).
- Per-photo download counters and limits are unchanged. In a multi-shot
  session, each photo retains its own counter.

### Upgrade

Upload v3.4.0 over v3.3.0 from **Plugins -> Add New -> Upload Plugin**.
No reconfiguration needed.

### Next: photobooth client (separate release)

The booth-side changes (capture-mode toggle, open-ended multi-shot loop,
"Start New Session" button, single-QR print layout) will ship in a
photobooth update. Until then, the booth continues to upload as
single-shot and v3.4.0 behaves exactly like v3.3.0.
