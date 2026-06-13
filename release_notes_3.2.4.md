# Schoolbooth Photo Manager v3.2.4

Re-release of v3.2.3 with a corrected installable zip. No code changes
relative to v3.2.3.

If you tried to install v3.2.3 via Plugins -> Add New -> Upload Plugin
and got "plugin does not exist", please use this v3.2.4 zip instead.
The v3.2.3 zip was built with PowerShell's Compress-Archive, which on
Windows writes ZIP entries with backslash separators that WordPress's
unzipper can't navigate.

## Carried over from v3.2.3

- QR scan with no consent now redirects to the download portal (which
  renders the consent form) instead of dying with
  "You must complete the permissions form before downloading".
- Permissions form errors surface the actual server message instead of
  a generic "An error occurred while processing your form".
- A failed audit-log write no longer blocks valid consent submissions;
  consent is still recorded and the user proceeds to their photos.

## Installation note

If a previous install (failed or otherwise) created a
`wp-content/plugins/schoolbooth-photo-manager/` directory on your
server, WordPress will refuse to upload over the top of it. Either:

- Use the existing plugin's auto-update path, or
- Delete the directory via SFTP/cPanel before uploading the zip, or
- Deactivate + delete the previous install via Plugins -> Installed Plugins.
