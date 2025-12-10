# Secret Plugin for WinterCMS

**Temporary signed links for internal files and internal URLs.**

This plugin provides a Twig filter and function `secret` that creates signed, expiring links without exposing your real storage paths. It supports:

  * Files on any Laravel/Winter filesystem disk (`media`, `local`, `s3`, …)
  * Internal URLs (e.g., `/queuedresize/<hash>` from a qresize plugin)
  * Optional delete-after-download for storage files
  * No external hosts (only your own app)

**Author:** Mercator
**Plugin code:** `plugins/mercator/secret`

-----

## 1\. Installation

### 1.1. From GitHub

From the root of your WinterCMS project:

```bash
cd plugins
mkdir -p mercator
cd mercator
git clone https://github.com/helmutkaufmann/wn-secret-plugin.git secret
```

The final path must be:

```text
plugins/mercator/secret/
  Plugin.php
  routes.php
  config/config.php
  http/controllers/SignedFileController.php
  README.md
  LICENSE
```

### 1.2. Clear caches

From the Winter root:

```bash
php artisan cache:clear
php artisan config:clear
```

Then log in to the backend and ensure **Mercator.Secret** is enabled (if you are using plugin auto-discovery this is normally automatic).

-----

## 2\. Configuration

The plugin’s config file is located at `plugins/mercator/secret/config/config.php`.

**Default content:**

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default storage disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used to read files unless a different disk is passed
    | explicitly to the Twig filter/function.
    |
    */

    'disk' => env('SECRET_DEFAULT_DISK', 'media'),

    /*
    |--------------------------------------------------------------------------
    | Default expiry (minutes)
    |--------------------------------------------------------------------------
    |
    | Number of minutes temporary links are valid if no explicit duration is
    | passed to the Twig filter/function.
    |
    */

    'expiry' => (int) env('SECRET_DEFAULT_EXPIRY', 60),

    /*
    |--------------------------------------------------------------------------
    | Delete after download by default
    |--------------------------------------------------------------------------
    |
    | If true, files will be removed from the disk after a successful streamed
    | download, unless you override it in the Twig call.
    |
    */

    'delete_after_download' => (bool) env('SECRET_DELETE_AFTER_DOWNLOAD', false),

];
```

### 2.1. .env variables

Typical `.env` settings:

```ini
SECRET_DEFAULT_DISK=media          # default disk for storage-mode links
SECRET_DEFAULT_EXPIRY=15           # default expiry in minutes
SECRET_DELETE_AFTER_DOWNLOAD=false # true = delete after download by default
```

> **Note:** `SECRET_DEFAULT_DISK` must be a valid disk from `config/filesystems.php`.

-----

## 3\. Route

The plugin registers a single front-end route in `plugins/mercator/secret/routes.php`:

```php
Route::get('secret-download', [SignedFileController::class, 'download'])
    ->name('mercator.secret.download');
```

This is the endpoint all signed links point to.

The controller (`SignedFileController`) validates the signed URL using `URL::hasValidSignature($request)`, so no signed middleware alias is required.

-----

## 4\. Twig API

The plugin registers both a filter and a function named `secret`.

### 4.1. Signature

**Filter form:**

```twig
{{ target | secret(minutes, delete_after_download, disk) }}
```

**Function form:**

```twig
{{ secret(target, minutes, delete_after_download, disk) }}
```

**Parameters:**

  * **target**:
      * **Storage mode:** a path like `media/foo/bar.pdf`
      * **URL mode:** internal URL or path (e.g. `/queuedresize/abcd1234`)
  * **minutes** (optional, int): expiry duration; defaults to config expiry.
  * **delete\_after\_download** (optional, bool): storage mode only; defaults to config.
  * **disk** (optional, string): storage disk; defaults to config disk or filesystem default.

**Logic:**
If `target`:

  * starts with `http://` or `https://` → **URL mode** (only your host allowed)
  * starts with `/` → **URL mode**
  * otherwise → **Storage mode**

-----

## 5\. Storage mode (files on disks)

Use this when you have a path relative to a storage disk, e.g., values coming from the media library or file uploads.

**Example:**

```twig
{# file.path = "media/Quartierszeitung/2014/OZ_Nr104_2014.pdf" #}

{# Default settings from config (disk, expiry, delete flag) #}
<a href="{{ file.path | secret }}">
    Download
</a>
```

### 5.1. Storage mode with options

```twig
{# 30 minutes, do NOT delete after download #}
<a href="{{ file.path | secret(30, false) }}">
    Download (30 minutes)
</a>

{# 10 minutes, delete after successful download #}
<a href="{{ file.path | secret(10, true) }}">
    One-time download (10 minutes)
</a>

{# Explicit disk (e.g. media) #}
<a href="{{ file.path | secret(60, false, 'media') }}">
    Download from media disk (60 minutes)
</a>
```

Function form is identical in behavior:

```twig
<a href="{{ secret(file.path, 30, true, 'media') }}">
    One-time download from media disk (30 minutes)
</a>
```

**Process:**
When the client requests the signed URL:

1.  The controller verifies the signature and expiry.
2.  Decrypts the payload (disk, path, delete flag).
3.  Streams the file from `Storage::disk($disk)`.
4.  If `delete_after_download` is true, deletes the file after streaming.

The real storage path is never exposed in clear text in the URL.

-----

## 6\. URL mode (internal URLs only)

Use this when you already have an internal URL/path and you just want an expiring signed wrapper. This is useful together with dynamic image endpoints (e.g., `qresize`).

### 6.1. Example with qresize

Assume your qresize plugin returns a URL like `/queuedresize/<hash>`:

```twig
{# Create a resized image URL and wrap it in a 15-minute secret link #}
<a href="{{ 'media/foo.jpg' | qresize(800, 600) | secret(15) }}">
    Temporary resized image (15 minutes)
</a>
```

**Process:**

1.  `qresize` → returns `/queuedresize/<hash>`.
2.  `secret(15)` sees a URL (starts with `/`) → **URL mode**.
3.  A signed link is generated pointing to `/secret-download?...`.
4.  The controller:
      * Validates the signature/expiry.
      * Decrypts the internal URL from the payload.
      * Verifies that the URL is relative or same-host.
      * Redirects to `/queuedresize/<hash>`.

> **Note:** There is no `delete-after-download` in URL mode and no external hosts are allowed.

-----

## 7\. Implementation notes

### 7.1. Payload

The link looks like:
`/secret-download?t=ENCRYPTED_PAYLOAD&expires=...&signature=...`

The encrypted payload includes:

  * `mode`: "storage" or "url"
  * **Storage mode:**
      * `p`: path inside disk (e.g. `media/foo.pdf`)
      * `d`: disk name (e.g. `media`)
      * `del`: 1 or 0 for delete-after-download
  * **URL mode:**
      * `u`: internal URL/path

Everything is encrypted via Laravel’s `Crypt` using your `APP_KEY`.

### 7.2. Security

  * Signature and expiry are validated with `URL::hasValidSignature($request)`.
  * **Storage mode rejects:**
      * URLs (`http://` / `https://`)
      * paths containing `..` (path traversal)
  * **URL mode:**
      * Only relative paths or absolute URLs with the same host as the current request.
      * External hosts are rejected.

-----

## 8\. License

This plugin is released under the MIT License.

See LICENSE in the plugin directory for full text.