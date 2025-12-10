<?php namespace Mercator\Secret\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignedFileController extends Controller
{
    public function download(Request $request)
    {
        // Manually validate the temporary signed URL
        if (!URL::hasValidSignature($request)) {
            abort(403); // invalid or expired link
        }

        $token = $request->query('t');
        if (!$token) {
            abort(404);
        }

        // Decrypt and decode payload
        try {
            $json    = Crypt::decryptString($token);
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            abort(404);
        }

        $mode = $payload['mode'] ?? 'storage';

        if ($mode === 'url') {
            return $this->handleUrlMode($payload, $request);
        }

        // default: storage mode
        return $this->handleStorageMode($payload);
    }

    protected function handleUrlMode(array $payload, Request $request)
    {
        $url = isset($payload['u']) ? trim((string) $payload['u']) : '';
        if ($url === '') {
            abort(404);
        }

        // Only internal URLs:
        // - relative paths, OR
        // - absolute URLs with same host as this request
        if (preg_match('#^https?://#i', $url)) {
            $host    = parse_url($url, PHP_URL_HOST);
            $reqHost = $request->getHost();

            if (!$host || !hash_equals($host, $reqHost)) {
                abort(403);
            }

            return redirect()->to($url);
        }

        // Relative path (e.g. "/queuedresize/abc123" or "queuedresize/abc123")
        if (!str_starts_with($url, '/')) {
            $url = '/' . ltrim($url, '/');
        }

        return redirect()->to($url);
    }

    protected function handleStorageMode(array $payload)
    {
        $path   = isset($payload['p']) ? trim((string) $payload['p']) : '';
        $disk   = isset($payload['d']) ? (string) $payload['d'] : config('filesystems.default');
        $delete = !empty($payload['del']);

        if ($path === '') {
            abort(404);
        }

        // Block external-looking stuff and traversal
        if (preg_match('#^https?://#i', $path) || str_contains($path, '..')) {
            abort(403);
        }

        $storage = Storage::disk($disk);

        if (!$storage->exists($path)) {
            abort(404);
        }

        $filename = basename($path);
        $mime     = $storage->mimeType($path) ?: 'application/octet-stream';

        $response = new StreamedResponse(function () use ($storage, $path, $delete) {
            $stream = $storage->readStream($path);
            if (!$stream) {
                return;
            }

            while (!feof($stream)) {
                echo fread($stream, 8192);
            }

            fclose($stream);

            if ($delete) {
                $storage->delete($path);
            }
        });

        $response->headers->set('Content-Type', $mime);
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="' . addslashes($filename) . '"'
        );

        return $response;
    }
}