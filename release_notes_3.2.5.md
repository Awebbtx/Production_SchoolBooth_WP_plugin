# Schoolbooth Photo Manager v3.2.5

Re-release with a more aggressively-correct zip layout. No code changes
relative to v3.2.3 / v3.2.4.

## Why another release?

Some WordPress hosts use PHP's PclZip fallback (rather than the native
ZipArchive extension) for plugin uploads. PclZip on certain hosts will
extract zip entries with embedded `/` characters as **flat files with
literal slashes in their filename** rather than creating the folder
hierarchy. The v3.2.4 zip had no explicit directory entries, which
appears to have triggered this on at least one production host.

v3.2.5 ships **explicit directory entries** in the zip (sorted before
the file entries) so PclZip is forced to create the folder hierarchy
before writing files. This is the layout that other WordPress.org
plugins use.

The zip is also now built with Python's `zipfile` module instead of
PowerShell, eliminating any platform-specific quirks.

## Carried over from v3.2.3

- QR scan with no consent now redirects to the download portal
  (renders the consent form) instead of dying with
  "You must complete the permissions form before downloading".
- Permissions form errors surface the actual server message instead of
  a generic "An error occurred while processing your form".
- A failed audit-log write no longer blocks valid consent submissions.

## Installation -- IMPORTANT if you tried v3.2.3 or v3.2.4 already

Old broken extractions left literal-backslash filenames in
`wp-content/plugins/`. Before installing v3.2.5, **delete every file
and folder under `wp-content/plugins/` whose name starts with
`schoolbooth-photo-manager`** (use SFTP or cPanel File Manager).
Otherwise WordPress's plugin scanner will keep tripping on the
leftovers.

After cleanup, upload `schoolbooth-photo-manager-v3.2.5.zip` via
Plugins -> Add New -> Upload Plugin and activate.
