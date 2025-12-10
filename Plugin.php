<?php namespace Mercator\Secret;

use System\Classes\PluginBase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Crypt;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'Secret',
            'description' => 'Temporary signed links for internal files and URLs.',
            'author'      => 'mercator',
            'icon'        => 'icon-lock',
        ];
    }

    public function registerMarkupTags()
    {
        return [
            'filters' => [
                // {{ target | secret() }}
                'secret' => [$this, 'makeSecretLink'],
            ],
            'functions' => [
                // {{ secret(target) }}
                'secret' => [$this, 'makeSecretLink'],
            ],
        ];
    }

    /**
     * Generate a temporary signed URL for:
     *  - a storage path (mode: "storage"), OR
     *  - an internal URL/path (mode: "url").
     *
     * Usage in Twig:
     *
     *    {# STORAGE MODE (uses disk, can delete) #}
     *    {{ file.path | secret }}                      {# defaults from config #}
     *    {{ file.path | secret(30) }}                  {# 30 minutes #}
     *    {{ file.path | secret(30, true) }}            {# 30 minutes, delete after download #}
     *    {{ file.path | secret(30, false, 'media') }}  {# explicit disk #}
     *
     *    {# URL MODE (no disk/delete, just redirect) #}
     *    {{ 'media/foo.jpg' | qresize(800, 600) | secret(15) }}
     *
     * @param string      $target  storage path OR internal URL/path
     * @param int|null    $minutes expiry in minutes (default from config)
     * @param bool|null   $delete  delete-after-download (storage mode only)
     * @param string|null $disk    storage disk (storage mode only)
     * @return string
     */
    public function makeSecretLink($target, $minutes = null, $delete = null, $disk = null)
    {
        $target = trim((string) $target);
        if ($target === '') {
            return '';
        }

        $config = (array) config('mercator.secret::config', []);

        // expiry
        $defaultExpiry = (int) ($config['expiry'] ?? 60);
        $minutes = $minutes !== null ? (int) $minutes : $defaultExpiry;
        if ($minutes <= 0) {
            $minutes = $defaultExpiry > 0 ? $defaultExpiry : 60;
        }

        // Decide mode: URL vs storage.
        // URL mode if:
        //   - starts with "http(s)://" OR
        //   - starts with "/" (absolute path on this app)
        $isUrl = preg_match('#^https?://#i', $target) || str_starts_with($target, '/');

        if ($isUrl) {
            // URL MODE
            // Only internal URLs: allow only same host as app.url or relative paths.
            if (preg_match('#^https?://#i', $target)) {
                $host    = parse_url($target, PHP_URL_HOST);
                $appUrl  = (string) config('app.url');
                $appHost = $appUrl ? parse_url($appUrl, PHP_URL_HOST) : null;

                if (!$host || !$appHost || !hash_equals($appHost, $host)) {
                    // not same host -> reject
                    return '';
                }
            }

            $payload = [
                'mode' => 'url',
                'u'    => $target,
            ];
        } else {
            // STORAGE MODE
            // delete flag
            if ($delete === null) {
                $delete = (bool) ($config['delete_after_download'] ?? false);
            } else {
                $delete = (bool) $delete;
            }

            // disk: config override → filesystem default
            $disk = $disk
                ?: (string) ($config['disk'] ?? config('filesystems.default'));

            // hard block path traversal and obvious bad stuff
            if (str_contains($target, '..')) {
                return '';
            }

            $payload = [
                'mode' => 'storage',
                'p'    => $target,             // path inside disk
                'd'    => $disk,               // disk name
                'del'  => $delete ? 1 : 0,     // delete-after-download flag
            ];
        }

        $encrypted = Crypt::encryptString(json_encode($payload));

        return URL::temporarySignedRoute(
            'mercator.secret.download',
            now()->addMinutes($minutes),
            ['t' => $encrypted]
        );
    }
}