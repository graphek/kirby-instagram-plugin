<?php
declare(strict_types=1);

namespace Graphek\SocialMedia;

use Kirby\Cms\App;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\Asset;
use RuntimeException;

final class Bridge
{
    public const OPTION = 'graphek.social-media.';

    public static function enabled(App $kirby): bool
    {
        return $kirby->option(self::OPTION . 'enabled', false) === true;
    }

    public static function guard(App $kirby): void
    {
        $role = $kirby->user()?->role()->id();
        $roles = $kirby->option(self::OPTION . 'roles', ['admin']);
        if (!self::enabled($kirby) || !$role || $role === 'social-sync' || !in_array($role, $roles, true)) {
            throw new PermissionException(message: 'Keine Berechtigung für die Instagram-Verbindung.');
        }
    }

    public static function configured(App $kirby): bool
    {
        if (self::central($kirby)) {
            try { self::connectionClient($kirby); return true; }
            catch (RuntimeException) { return false; }
        }
        $app = self::credentials($kirby);
        return $app['app_id'] !== '' && $app['app_secret'] !== '';
    }

    public static function central(App $kirby): bool
    {
        $mode = $kirby->option(self::OPTION . 'connectionMode', 'direct');
        if (!in_array($mode, ['direct', 'central'], true)) throw new RuntimeException('Ungültiger Instagram-Verbindungsmodus.');
        return $mode === 'central';
    }

    public static function serviceConfig(App $kirby): array
    {
        return ['origin' => (string)$kirby->option(self::OPTION . 'serviceUrl', ''),
            'project' => (string)$kirby->option(self::OPTION . 'projectId', ''),
            'key' => (string)$kirby->option(self::OPTION . 'installationKey', '')];
    }

    public static function connectionClient(App $kirby): ConnectionClient
    {
        $config = self::serviceConfig($kirby);
        return new ConnectionClient($config['origin'], $config['project'], $config['key']);
    }

    public static function serverApp(App $kirby): bool
    {
        // Never combine a partial server configuration with a different Panel app.
        return (string)$kirby->option(self::OPTION . 'appId', '') !== ''
            || (string)$kirby->option(self::OPTION . 'appSecret', '') !== '';
    }

    public static function credentials(App $kirby): array
    {
        if (self::central($kirby)) return ['app_id' => '', 'app_secret' => ''];
        if (self::serverApp($kirby)) return [
            'app_id' => (string)$kirby->option(self::OPTION . 'appId', ''),
            'app_secret' => (string)$kirby->option(self::OPTION . 'appSecret', ''),
        ];
        return self::store($kirby)->appCredentials();
    }

    public static function appStatus(App $kirby): array
    {
        if (self::central($kirby)) return ['manageable' => false];
        $app = self::credentials($kirby);
        return ['app_id' => $app['app_id'], 'secret_saved' => $app['app_secret'] !== '',
            'server_managed' => self::serverApp($kirby), 'manageable' => $kirby->user()?->role()->id() === 'admin'];
    }

    public static function configureApp(App $kirby): array
    {
        self::guard($kirby);
        if (self::central($kirby)) throw new RuntimeException('Die Meta-App wird zentral verwaltet.');
        if ($kirby->user()?->role()->id() !== 'admin') throw new PermissionException(message: 'Nur Administratoren dürfen die App-Konfiguration ändern.');
        if (!$kirby->request()->ssl()) throw new RuntimeException('Die App-Konfiguration benötigt HTTPS.');
        if (self::serverApp($kirby)) throw new RuntimeException('Diese App wird über die Serverkonfiguration verwaltet.');
        if (array_intersect(['app_id', 'app_secret', 'action'], array_keys($kirby->request()->query()->toArray()))) {
            throw new RuntimeException('App-Zugangsdaten dürfen nicht in der URL übergeben werden.');
        }
        $input = $kirby->request()->body()->toArray();
        $action = $input['action'] ?? 'save';
        if (!in_array($action, ['save', 'clear'], true)) throw new RuntimeException('Ungültige App-Konfigurationsaktion.');
        $kirby->response()->header('Cache-Control', 'no-store');
        self::store($kirby)->configureApp($input, $action === 'clear');
        return self::status($kirby);
    }

    public static function store(App $kirby): Store
    {
        $privateRoot = (string)$kirby->option(self::OPTION . 'privateRoot', $kirby->root('site') . '/social-media/instagram');
        $privateRoot = self::canonicalRoot($privateRoot);
        $documentRoot = self::canonicalRoot($kirby->root('index'));
        if ($privateRoot === $documentRoot || str_starts_with($privateRoot, $documentRoot . '/')) {
            throw new RuntimeException('Der private Instagram-Speicher muss außerhalb des Document Root liegen.');
        }
        $store = new Store(
            $privateRoot,
            $kirby->root('index') . '/assets/social-media/instagram',
            $kirby->url('base') . '/assets/social-media/instagram'
        );
        $store->setCleanup(function (string $relative) use ($kirby): void {
            // Only this source's derivatives; another retained preview can share its post ID.
            if (!preg_match('~^[0-9]{1,40}/[a-f0-9]+\.(jpg|png|webp)$~D', $relative)) throw new RuntimeException('Ungültiger Medienpfad.');
            $root = $kirby->root('media') . '/assets/assets/social-media/instagram';
            $asset = new Asset('assets/social-media/instagram/' . $relative);
            self::removeDirectory($asset->mediaDir(), $root);
        });
        return $store;
    }

    private static function canonicalRoot(string $path): string
    {
        if (!str_starts_with($path, '/') || preg_match('~(?:^|/)\.\.?(/|$)|[\x00-\x1f\x7f]~', $path)) throw new RuntimeException('Unsicherer Instagram-Speicherpfad.');
        $path = preg_replace('~/+~', '/', $path);
        if ($path === '/') throw new RuntimeException('Unsicherer Instagram-Speicherpfad.');
        $cursor = rtrim($path, '/');
        while ($cursor !== dirname($cursor)) {
            if (is_link($cursor)) throw new RuntimeException('Unsicherer Instagram-Speicherpfad.');
            $cursor = dirname($cursor);
        }
        return rtrim($path, '/');
    }

    private static function removeDirectory(string $path, string $boundary): void
    {
        if (!is_dir($path)) return;
        // Never traverse a symlink in the namespace or in any ancestor.
        $cursor = $path;
        while ($cursor !== dirname($cursor)) {
            if (is_link($cursor)) throw new RuntimeException('Unsicherer Medienpfad.');
            $cursor = dirname($cursor);
        }
        $real = realpath($path);
        $base = realpath($boundary);
        if (!$real || !$base || !str_starts_with($real, $base . '/')) throw new RuntimeException('Unsicherer Medienpfad.');
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink()) throw new RuntimeException('Unsicherer Medienpfad.');
            if ($entry->isDir()) self::removeDirectory($entry->getPathname(), $boundary);
            elseif (!unlink($entry->getPathname())) throw new RuntimeException('Vorschaudatei konnte nicht entfernt werden.');
        }
        if (!rmdir($path)) throw new RuntimeException('Vorschauverzeichnis konnte nicht entfernt werden.');
    }

    public static function feed(App $kirby): Feed
    {
        $app = self::credentials($kirby);
        return new Feed(self::store($kirby), new InstagramClient(
            $app['app_id'],
            $app['app_secret'],
            (string)$kirby->option(self::OPTION . 'apiVersion', 'v25.0')
        ));
    }

    public static function latestImage(App $kirby, string $alt): ?LocalImage
    {
        if (!self::enabled($kirby)) return null;
        try {
            $post = self::feed($kirby)->latest();
            $relative = $post['local_image'] ?? '';
            if ($relative === '' || !is_file(self::store($kirby)->imagePath($relative))) return null;
            if (trim($alt) === '') {
                $caption = trim(preg_replace('/\s+/u', ' ', strip_tags($post['caption'] ?? '')) ?? '');
                $alt = $caption !== '' ? mb_substr($caption, 0, 240) : 'Neuester Instagram-Beitrag';
            }
            return new LocalImage($relative, $alt);
        } catch (\Throwable) {
            // The manually selected module media remain the fallback, also for broken storage.
            return null;
        }
    }

    public static function status(App $kirby): array
    {
        $kirby->response()->header('Cache-Control', 'no-store');
        $central = self::central($kirby);
        $notice = $kirby->session()->get('social-media.instagram.notice');
        $kirby->session()->remove('social-media.instagram.notice');
        $feed = self::feed($kirby);
        return $feed->status() + ['preview' => self::preview($feed), 'configured' => self::configured($kirby), 'app' => self::appStatus($kirby),
            'connection_mode' => $central ? 'central' : 'direct',
            'service_origin' => $central ? self::serviceConfig($kirby)['origin'] : '',
            'connection_notice' => in_array($notice, ['success', 'failed'], true) ? $notice : null];
    }

    /** Panel-only projection: no credentials, private paths or remote media URLs. No provider request. */
    private static function preview(Feed $feed): array
    {
        $preview = [];
        foreach ($feed->posts() as $post) {
            if (!is_string($post['id'] ?? null) || !preg_match('/^[0-9]{1,40}$/D', $post['id'])
                || !in_array($post['type'] ?? null, ['IMAGE', 'VIDEO', 'CAROUSEL_ALBUM'], true)
                || !InstagramClient::permalink($post['permalink'] ?? null)
                || !is_string($post['caption'] ?? null) || !is_string($post['timestamp'] ?? null)
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $post['timestamp'])) continue;
            try { $date = new \DateTimeImmutable($post['timestamp']); }
            catch (\Throwable) { continue; }
            $caption = trim(preg_replace('/\s+/u', ' ', strip_tags($post['caption'])) ?? '');
            $preview[] = [
                'id' => $post['id'], 'type' => $post['type'],
                'caption' => mb_substr($caption, 0, 300) . (mb_strlen($caption) > 300 ? ' …' : ''),
                'timestamp' => $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'permalink' => $post['permalink'], 'image_url' => $post['image_url'],
            ];
            if (count($preview) === 6) break;
        }
        return $preview;
    }

    public static function flushPageCache(App $kirby): void
    {
        // Rendered teaser images are part of Kirby's page cache; never flush unrelated caches.
        $kirby->cache('pages')->flush();
    }

    public static function pruneDerivatives(App $kirby): void
    {
        // Covers orphan thumbnails whose original was lost (mtime no longer recoverable).
        $store = self::store($kirby);
        $store->withLock(static function () use ($kirby, $store): void {
            $root = $kirby->root('media') . '/assets/assets/social-media/instagram';
            if (!is_dir($root)) return;
            $retained = [];
            foreach ($store->read()['posts'] as $post) {
                $relative = $post['local_image'] ?? '';
                if ($relative !== '' && is_file($store->imagePath($relative))) {
                    $retained[(new Asset('assets/social-media/instagram/' . $relative))->mediaDir()] = true;
                }
            }
            if (is_link($root)) throw new RuntimeException('Unsicherer Medienpfad.');
            foreach (new \FilesystemIterator($root) as $id) {
                if ($id->isLink() || !$id->isDir() || !preg_match('/^[0-9]{1,40}$/D', $id->getFilename())) continue;
                foreach (new \FilesystemIterator($id->getPathname()) as $directory) {
                    if ($directory->isLink() || !$directory->isDir()
                        || !preg_match('/^[a-f0-9]+-[0-9]+$/D', $directory->getFilename())
                        || isset($retained[$directory->getPathname()])) continue;
                    self::removeDirectory($directory->getPathname(), $root);
                }
                if (!(new \FilesystemIterator($id->getPathname()))->valid()) rmdir($id->getPathname());
            }
        });
    }

    public static function sync(App $kirby, bool $force = true): array
    {
        $status = self::feed($kirby)->sync($force);
        if (($status['result'] ?? '') === 'updated') {
            self::finishMutation($kirby);
            $status = self::status($kirby) + ['result' => 'updated'];
        }
        return $status;
    }

    public static function finishMutation(App $kirby): void
    {
        try { self::pruneDerivatives($kirby); }
        catch (\Throwable) {
            $store = self::store($kirby);
            $store->withLock(static function () use ($store): void {
                $state = $store->read();
                $state['error'] = 'Alte Instagram-Vorschaubilder konnten nicht vollständig bereinigt werden.';
                $store->write($state);
            });
        } finally { self::flushPageCache($kirby); }
    }

    public static function defer(App $kirby): void
    {
        // CLI/API/Panel never accidentally schedule a second sync. On non-FPM hosts use manual/cron.
        if (!self::enabled($kirby) || !self::configured($kirby) || PHP_SAPI === 'cli'
            || !function_exists('fastcgi_finish_request')) return;
        try {
            if (!self::feed($kirby)->due()) return;
        } catch (\Throwable) { return; }
        register_shutdown_function(static function () use ($kirby): void {
            fastcgi_finish_request();
            try { self::sync($kirby, false); } catch (\Throwable) { /* No credentials in PHP output/logs. */ }
        });
    }
}
