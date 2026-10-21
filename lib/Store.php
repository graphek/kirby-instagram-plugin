<?php
declare(strict_types=1);

namespace Graphek\SocialMedia;

use RuntimeException;

final class Store
{
    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
    private string $privateRoot;
    private string $publicRoot;
    private string $publicUrl;
    private bool $locked = false;
    private $cleanup = null;

    public function __construct(string $privateRoot, string $publicRoot, string $publicUrl)
    {
        $this->privateRoot = $this->root($privateRoot);
        $this->publicRoot = $this->root($publicRoot);
        $this->publicUrl = rtrim($publicUrl, '/');
        if ($this->privateRoot === $this->publicRoot || str_starts_with($this->privateRoot, $this->publicRoot . '/')) {
            throw new RuntimeException('Der private Speicher darf nicht im Bildverzeichnis liegen.');
        }
    }

    public function read(): array
    {
        $path = $this->privateRoot . '/state.json';
        if (!is_file($path)) return self::defaults();
        $this->assertSafePath($path);
        $json = file_get_contents($path);
        if ($json === false || strlen($json) > 2 * 1024 * 1024) throw new RuntimeException('Der Feed-Speicher ist nicht lesbar.');
        try {
            $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Der Feed-Speicher ist beschädigt.');
        }
        if (!is_array($state) || !is_array($state['posts'] ?? null)) throw new RuntimeException('Der Feed-Speicher ist beschädigt.');
        return array_replace(self::defaults(), $state, ['settings' => self::validateSettings($state['settings'] ?? [])]);
    }

    public static function defaults(): array
    {
        return ['account' => null, 'posts' => [], 'settings' => ['max_posts' => 20, 'interval_minutes' => 60, 'mode' => 'request'],
            'last_success' => null, 'last_attempt' => null, 'error' => null, 'reconnect' => false, 'failures' => 0, 'next_attempt' => null, 'sync_key_hash' => null];
    }

    public static function validateSettings(array $settings): array
    {
        $settings = array_replace(self::defaults()['settings'], $settings);
        foreach (['max_posts' => [1, 100], 'interval_minutes' => [15, 1440]] as $key => [$min, $max]) {
            $value = filter_var($settings[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > $max) throw new RuntimeException('Ungültige Feed-Einstellungen.');
            $settings[$key] = $value;
        }
        if (!in_array($settings['mode'], ['request', 'external'], true)) throw new RuntimeException('Ungültiger Aktualisierungsmodus.');
        return array_intersect_key($settings, self::defaults()['settings']);
    }

    /** Nonblocking cross-process lock; null means another writer is active. */
    public function withLock(callable $callback): mixed
    {
        if ($this->locked) return $callback();
        $this->directory($this->privateRoot, 0700);
        $path = $this->privateRoot . '/state.lock';
        $this->assertSafePath($path);
        $handle = fopen($path, 'c');
        if ($handle === false) throw new RuntimeException('Der Feed-Speicher ist nicht beschreibbar.');
        chmod($path, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
        $this->locked = true;
        try { $this->pruneStaging($this->privateRoot); return $callback(); }
        finally { $this->locked = false; flock($handle, LOCK_UN); fclose($handle); }
    }

    public function write(array $state): void
    {
        if (!$this->locked) {
            $result = $this->withLock(function () use ($state): bool { $this->write($state); return true; });
            if ($result === null) throw new RuntimeException('Der Feed wird gerade aktualisiert.');
            return;
        }
        $state['settings'] = self::validateSettings($state['settings'] ?? []);
        if (!is_array($state['posts'] ?? null) || count($state['posts']) > 100) throw new RuntimeException('Ungültiger Feed-Bestand.');
        $json = json_encode(array_replace(self::defaults(), $state), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 2 * 1024 * 1024) throw new RuntimeException('Der Feed-Bestand ist zu groß.');
        $this->atomicWrite($this->privateRoot . '/state.json', $json, 0600);
    }

    public function settings(): array { return $this->read()['settings']; }

    /** Separate from feed snapshots: disconnecting never loses the app setup. */
    public function appCredentials(): array
    {
        $path = $this->privateRoot . '/app.json';
        if (!is_file($path)) return ['app_id' => '', 'app_secret' => ''];
        $this->assertSafePath($path);
        $json = file_get_contents($path);
        if ($json === false || strlen($json) > 4096) throw new RuntimeException('Die App-Konfiguration ist nicht lesbar.');
        try { $app = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Die App-Konfiguration ist beschädigt.'); }
        if (!is_array($app) || !is_string($app['app_id'] ?? null) || !is_string($app['app_secret'] ?? null)) {
            throw new RuntimeException('Die App-Konfiguration ist beschädigt.');
        }
        return ['app_id' => $app['app_id'], 'app_secret' => $app['app_secret']];
    }

    /** Requires the same lock as OAuth and feed mutations. Empty secrets retain the old value. */
    public function configureApp(array $input, bool $clear = false): void
    {
        $result = $this->withLock(function () use ($input, $clear): bool {
            if ($this->read()['account'] !== null) throw new RuntimeException('Vor einem App-Wechsel bitte die Instagram-Verbindung trennen.');
            $app = ['app_id' => '', 'app_secret' => ''];
            if (!$clear) {
                $id = $input['app_id'] ?? null;
                $secret = $input['app_secret'] ?? null;
                if (!is_string($id) || !preg_match('/^[1-9][0-9]{1,39}$/D', $id) || !is_string($secret)) {
                    throw new RuntimeException('Bitte eine gültige numerische Instagram-App-ID und ein App-Geheimnis eingeben.');
                }
                $existing = $this->appCredentials();
                if ($secret === '') {
                    if ($existing['app_id'] !== $id) throw new RuntimeException('Eine neue App-ID benötigt ihr eigenes App-Geheimnis.');
                    $secret = $existing['app_secret'];
                }
                if (!preg_match('/^[\x21-\x7e]{16,512}$/D', $secret)) throw new RuntimeException('Das App-Geheimnis hat ein ungültiges Format.');
                $app = ['app_id' => $id, 'app_secret' => $secret];
            }
            $this->atomicWrite($this->privateRoot . '/app.json', json_encode($app, JSON_THROW_ON_ERROR), 0600);
            return true;
        });
        if ($result === null) throw new RuntimeException('Der Feed wird gerade aktualisiert.');
    }

    /** The 256-bit secret is returned once; only its SHA-256 digest is persisted. */
    public function rotateSyncKey(): string
    {
        $key = bin2hex(random_bytes(32));
        $result = $this->withLock(function () use ($key): bool {
            $state = $this->read();
            $state['sync_key_hash'] = hash('sha256', $key);
            $this->write($state);
            return true;
        });
        if ($result === null) throw new RuntimeException('Der Feed wird gerade aktualisiert.');
        return $key;
    }

    public function revokeSyncKey(): bool
    {
        $result = $this->withLock(function (): bool {
            $state = $this->read();
            $state['sync_key_hash'] = null;
            $this->write($state);
            return true;
        });
        if ($result === null) throw new RuntimeException('Der Feed wird gerade aktualisiert.');
        return $result;
    }

    public function acceptsSyncKey(string $key): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) return false;
        $hash = $this->read()['sync_key_hash'];
        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash)
            && hash_equals($hash, hash('sha256', $key));
    }

    public function updateSettings(array $settings): array
    {
        $result = $this->withLock(function () use ($settings): array {
            $state = $this->read();
            $state['settings'] = self::validateSettings(array_replace($state['settings'], $settings));
            $state['posts'] = array_slice($state['posts'], 0, $state['settings']['max_posts']);
            $this->write($state);
            try { $this->prune(array_column($state['posts'], 'local_image')); }
            catch (\Throwable) {
                $state['error'] = 'Die Einstellungen wurden gespeichert; alte Vorschaubilder konnten nicht vollständig bereinigt werden.';
                $this->write($state);
            }
            return $state['settings'];
        });
        if ($result === null) throw new RuntimeException('Der Feed wird gerade aktualisiert.');
        return $result;
    }

    public function disconnect(): void
    {
        $result = $this->withLock(function (): bool {
            $settings = $this->settings();
            $state = array_replace(self::defaults(), ['settings' => $settings]);
            $this->write($state);
            try { $this->prune([]); }
            catch (\Throwable) {
                $state['error'] = 'Instagram wurde getrennt; alte Vorschaubilder konnten nicht vollständig bereinigt werden.';
                $this->write($state);
            }
            return true;
        });
        if ($result === null) throw new RuntimeException('Der Feed wird gerade aktualisiert.');
    }

    /** Called with the relative source path, before removal, for Kirby-owned derivatives. */
    public function setCleanup(?callable $callback): void { $this->cleanup = $callback; }

    public function saveImage(string $id, string $bytes): string
    {
        if (!$this->locked) throw new RuntimeException('Bildänderungen benötigen die Feed-Sperre.');
        if (!preg_match('/^[0-9]{1,40}$/D', $id)) throw new RuntimeException('Ungültige Medien-ID.');
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) throw new RuntimeException('Das Vorschaubild ist zu groß oder leer.');
        $info = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$info || !isset($extensions[$mime]) || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 40_000_000) {
            throw new RuntimeException('Das Vorschaubild hat ein unzulässiges Format oder Abmessungen.');
        }
        $relative = $id . '/' . hash('sha256', $bytes) . '.' . $extensions[$mime];
        $this->directory($this->publicRoot . '/' . $id, 0755);
        $path = $this->imagePath($relative);
        if (!$this->validImage($relative)) $this->atomicWrite($path, $bytes, 0644);
        return $relative;
    }

    public function imagePath(string $relative): string
    {
        if (!preg_match('~^[0-9]{1,40}/[a-f0-9]{64}\.(jpg|png|webp)$~D', $relative)) throw new RuntimeException('Ungültiger Bildpfad.');
        $path = $this->publicRoot . '/' . $relative;
        $this->assertSafePath($path);
        return $path;
    }

    public function imageUrl(string $relative): string { $this->imagePath($relative); return $this->publicUrl . '/' . $relative; }

    public function validImage(string $relative): bool
    {
        try {
            $path = $this->imagePath($relative);
            if (!is_file($path) || filesize($path) > self::MAX_IMAGE_BYTES || hash_file('sha256', $path) !== pathinfo($path, PATHINFO_FILENAME)) return false;
            $info = @getimagesize($path);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            return $info !== false && isset($extensions[$mime]) && $extensions[$mime] === pathinfo($path, PATHINFO_EXTENSION) && ($info['mime'] ?? '') === $mime
                && $info[0] > 0 && $info[1] > 0 && $info[0] <= 12000 && $info[1] <= 12000 && $info[0] * $info[1] <= 40_000_000;
        } catch (\Throwable) { return false; }
    }

    /** Only numeric media directories and this plugin's hash filenames are owned. */
    public function prune(array $retained): void
    {
        if (!$this->locked) throw new RuntimeException('Bildänderungen benötigen die Feed-Sperre.');
        if (!is_dir($this->publicRoot)) return;
        $this->assertSafePath($this->publicRoot);
        $keep = array_fill_keys($retained, true);
        foreach (new \DirectoryIterator($this->publicRoot) as $directory) {
            $id = $directory->getFilename();
            if ($directory->isLink() || !$directory->isDir() || !preg_match('/^[0-9]{1,40}$/D', $id)) continue;
            $this->pruneStaging($directory->getPathname());
            foreach (new \DirectoryIterator($directory->getPathname()) as $file) {
                $name = $file->getFilename();
                if ($file->isLink() || !$file->isFile() || !preg_match('/^[a-f0-9]{64}\.(jpg|png|webp)$/D', $name) || isset($keep[$id . '/' . $name])) continue;
                if ($this->cleanup !== null) ($this->cleanup)($id . '/' . $name);
                if (!unlink($file->getPathname())) throw new RuntimeException('Alte Vorschaubilder konnten nicht entfernt werden.');
            }
            // Never recursively delete; non-owned files keep their directory alive.
            if (!(new \FilesystemIterator($directory->getPathname()))->valid()) rmdir($directory->getPathname());
        }
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $this->assertSafePath($path);
        $temporary = dirname($path) . '/.social-staging-' . bin2hex(random_bytes(16));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('Der Feed-Speicher ist nicht beschreibbar.');
        try {
            if (!chmod($temporary, $mode) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) throw new RuntimeException('Der Feed-Speicher konnte nicht gesichert werden.');
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $path)) throw new RuntimeException('Der Feed-Speicher konnte nicht gesichert werden.');
        } finally { if (is_resource($handle)) fclose($handle); if (is_file($temporary)) unlink($temporary); }
    }

    private function pruneStaging(string $directory): void
    {
        foreach (new \DirectoryIterator($directory) as $file) {
            if (!$file->isLink() && $file->isFile() && preg_match('/^\.social-staging-[a-f0-9]{32}$/D', $file->getFilename())) {
                if (!unlink($file->getPathname())) throw new RuntimeException('Ein unterbrochener Feed-Schreibvorgang konnte nicht bereinigt werden.');
            }
        }
    }

    private function root(string $root): string
    {
        if (!str_starts_with($root, '/') || preg_match('~(?:^|/)\.\.?(/|$)~', $root) || str_contains($root, "\0")) throw new RuntimeException('Der Speicher benötigt einen absoluten eindeutigen Pfad.');
        $root = rtrim($root, '/');
        if ($root === '') throw new RuntimeException('Ungültiger Speicherpfad.');
        $this->assertSafePath($root);
        return $root;
    }

    private function assertSafePath(string $path): void
    {
        for ($candidate = $path; $candidate !== '/' && $candidate !== '.'; $candidate = dirname($candidate)) {
            if (is_link($candidate)) throw new RuntimeException('Symbolische Links sind im Feed-Speicher nicht zulässig.');
        }
    }

    private function directory(string $path, int $mode): void
    {
        $this->assertSafePath($path);
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) throw new RuntimeException('Der Feed-Speicher ist nicht beschreibbar.');
        if ($path === $this->privateRoot) chmod($path, 0700);
    }
}
