# Schoolbooth Photo Manager Release Notes

## Install Source
Use only the release asset ZIP from this release page.
Do not install GitHub auto-generated source archives ("Source code (zip)" / "Source code (tar.gz)").

## Install Steps
1. Download the asset named `schoolbooth-photo-manager-vX.Y.Z.zip`.
2. In WordPress Admin, go to Plugins > Add New > Upload Plugin.
3. Upload ZIP, click Install Now, then Activate.

## Shared Hosting / WAF Note
On some shared hosts (including Bluehost and similar providers), ModSecurity or WAF layers may block enrollment or upload routes.
If needed, ask hosting support for POST exceptions on:
- /wp-json/schoolbooth/v1/enroll
- /wp-json/schoolbooth/v1/ingest
- /wp-json/pta-schoolbooth/v1/enroll
- /wp-json/pta-schoolbooth/v1/ingest
- /wp-json/nbpta/v1/enroll
- /wp-json/nbpta/v1/ingest

## Changelog
- Add release-specific changes here.
