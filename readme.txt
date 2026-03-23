=== Schoolbooth Photo Manager ===
Contributors: ikapsystems
Tags: photo download, secure downloads, access codes
Requires at least: 5.6
Tested up to: 6.0
Stable tag: 3.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure photo downloads with access codes and portal interface.

== Description ==

This plugin provides:
- Secure photo downloads with access codes
- Download portal interface
- Download limits per image
- Automatic image expiration
- Access-code based photo portals
- One-click page creation
- Bulk download/delete options
- Signed REST API for app uploads

== Installation ==

1. Upload the plugin files to the /wp-content/plugins/schoolbooth-photo-manager directory
2. Activate the plugin through the Plugins menu in WordPress
3. Configure settings under Settings > Photo Downloads
4. Use the [schoolbooth_download_portal] shortcode or create a page automatically

== Changelog ==

= 3.2.1 =
* Fixed activation failures by making setup checks non-fatal and surfacing warnings in wp-admin.
* Automatically regenerates an invalid/too-short shared secret during activation.

= 3.0.2 =
* Rebranding cleanup for Schoolbooth naming
* API namespace and settings naming alignment
* Production-focused packaging updates

= 3.0.0 =
* Complete redesign with portal interface
* Added bulk download/delete options
* Improved security
* Better error handling
* Added expiration countdown display

