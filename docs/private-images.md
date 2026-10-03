# Private trade-image setup and behavior

Images require **PHP GD with JPEG, PNG and WebP support**, HTTPS outside development, and an existing writable **TGIT_PRIVATE_MEDIA_DIR** outside every public root. Journals and strategies work without image storage. GD is a PHP extension, not a WordPress plugin.

## Server configuration

For the confirmed Apache/Ubuntu host, choose a dedicated directory such as `/var/lib/ig-trading-journal/media`, outside the web document root and WordPress/content directories. Create it with restrictive permissions for the actual Apache/PHP worker account. Use a separate directory per WordPress installation. Do not publish it through an Alias, Nginx location, uploads path or CDN.

Add this to `wp-config.php` before WordPress bootstrap, using your actual directory:

```php
define('TGIT_PRIVATE_MEDIA_DIR', '/var/lib/ig-trading-journal/media');
```

Apply an Apache deny rule as defense in depth:

```apache
<Directory "/var/lib/ig-trading-journal/media">
    Require all denied
</Directory>
```

Reload Apache through your normal server-change procedure and verify direct HTTP requests cannot retrieve files. The plugin refuses storage within any known served root, rejects unsafe workspace directories/keys, and never creates public attachments or derivative URLs. Paths and generated keys are not returned in gallery JSON.

Confirm GD support in the **WordPress Apache runtime**, rather than only PHP CLI/phpMyAdmin. Existing screenshots do not confirm GD or BCMath. Storage health appears in the owner's image-settings section without disclosing the private path. It distinguishes missing GD, missing image codec support, unavailable private storage and unsaved owner quota policy. Each failed upload also reports its own reason.

## Owner policy

Open Investment Tracker, select the workspace, and save **Image quota and retention**. Proposed PRD defaults are prefilled: 20 images/trade, 10 MiB/file, 40 million decoded pixels, 30-day trash; the initial quota proposal is 1 GiB. These are editable within supported hard bounds and are not enabled until an owner saves them. Managers/contributors cannot change quota policy.

The decoder also enforces available PHP memory, which may reject images below the configured pixel ceiling on the 128 MiB REST runtime. For a memory-limit rejection, resize the image to about 1600 pixels on its longest side and use the failed image card's Retry button; alternatively have the host raise the WordPress PHP memory_limit to at least 256M. Raising the image quota or changing directory permissions does not address a memory rejection. Originals are normalized to a maximum 4096-pixel edge; thumbnails to 512. The adapter re-encodes and strips metadata, including EXIF/geolocation, retaining **normalized originals only**. This is not an archival-original feature.

## Upload and access

Save a journal with no images or select multiple images in its form. The journal commits first. Each file has an independent reservation/upload, progress and cancellation; failed files do not roll back notes or other ready images. Retry the same file or select a corrected/resized replacement for a failed image, preserving its slot, caption and order. Ready images cannot be silently replaced.

The server verifies reserved content hash and size, extension, MIME/magic, decoder success, dimensions, memory and quota. Normalization is synchronous and bounded per file in this build. Uploaded/validating/processing states occur inside the atomic command; final ready/failed states are durable. A fully asynchronous processing queue/lease recovery remains a later operational slice.

Every original/thumbnail request checks current workspace membership and trade relationship. REST cookie requests require a valid nonce as well as authorization. Responses are private/no-store, nosniff and non-indexable. The UI fetches authenticated blobs; it never exposes nonce-bearing public image links. Caption, accessible description, stage, timeframe and order are editable with revision checks. Two selected images can be compared side by side.

## Deletion, jobs and backup

Removing an image immediately blocks both variants and puts it in recoverable trash. Retained trash continues to consume quota and count toward the trade limit. Restore is available during the configured retention window. Cancellation uses the same recoverable action.

Saving owner policy schedules a daily WordPress cron cleanup carrying the explicit workspace and authorizing owner. Each run rechecks current membership/capability, handles at most 100 items, and schedules a continuation for a full batch. Revoked owners cannot run cleanup. A current owner can save policy to establish their own schedule or use **Run expired-image cleanup**. Expired trash and reserved/failed uploads untouched for 24 hours are eligible. Failed physical removal retains the database reservation. Cron failures emit `tgit_media_cleanup_failed`; broader operator job monitoring and crash/orphan reconciliation remain release work. Ensure cron is executed reliably before a pilot.

Deactivation/uninstall preserve data. Back up the database and private directory consistently; database-only backup is insufficient. Retention cannot erase copies already in backups, and backup expiry follows the operator's policy. Restore/hash reconciliation is still pending under the owner's fourth priority. No production purge or server configuration was performed by this implementation.
