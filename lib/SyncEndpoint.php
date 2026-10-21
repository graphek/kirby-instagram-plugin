<?php
declare(strict_types=1);

namespace Graphek\SocialMedia;

use Kirby\Cms\App;
use Kirby\Http\Response;

final class SyncEndpoint
{
    public static function url(App $kirby): string
    {
        return $kirby->url('base') . '/social-media/instagram/sync';
    }

    public static function handle(App $kirby): Response
    {
        if (!Bridge::enabled($kirby)) return self::response(['result' => 'disabled'], 404);
        if (!$kirby->request()->ssl()) return self::response(['result' => 'https_required'], 403);
        try {
            // Accept only the dedicated header: no query/body credentials or CMS sessions.
            $key = $kirby->request()->header('X-Instagram-Sync-Key', '');
            if (!is_string($key) || !Bridge::store($kirby)->acceptsSyncKey($key)) {
                return self::response(['result' => 'unauthorized'], 401);
            }
            if (!Bridge::configured($kirby)) return self::response(['result' => 'not_configured'], 503);
            $status = Bridge::sync($kirby);
            $result = $status['result'];
            $code = match ($result) {
                'updated', 'skipped' => 200,
                'busy', 'disconnected' => 409,
                default => 502,
            };
            // No account details, settings, provider messages, tokens or key hashes.
            return self::response(['result' => $result, 'posts_count' => $status['posts_count']], $code);
        } catch (\Throwable) {
            return self::response(['result' => 'error'], 500);
        }
    }

    private static function response(array $data, int $code): Response
    {
        return new Response(json_encode($data, JSON_THROW_ON_ERROR), 'application/json', $code, [
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
