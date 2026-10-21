<?php
declare(strict_types=1);

namespace Graphek\SocialMedia;

use RuntimeException;

final class Feed
{
    private $downloader;

    public function __construct(private Store $store, private InstagramClient $client, ?callable $downloader = null)
    {
        $this->downloader = $downloader ?? [InstagramClient::class, 'downloadImage'];
    }

    public function status(): array { return $this->safeStatus($this->store->read()); }

    public function posts(): array
    {
        $state = $this->store->read();
        $posts = [];
        foreach ($state['posts'] as $post) {
            if (!is_array($post) || !is_string($post['local_image'] ?? null)) continue;
            try {
                if (!is_file($this->store->imagePath($post['local_image']))) continue;
                $post['image_url'] = $this->store->imageUrl($post['local_image']);
            } catch (RuntimeException) { continue; }
            unset($post['remote_image']);
            $posts[] = $post;
        }
        return $posts;
    }

    public function latest(): ?array { return $this->posts()[0] ?? null; }

    public function due(): bool { return $this->isDue($this->store->read(), time()); }

    public function sync(bool $force = false): array
    {
        $result = $this->store->withLock(function () use ($force): array {
            $state = $this->store->read();
            $now = time();
            if (!is_array($state['account'])) return $this->safeStatus($state) + ['result' => 'disconnected'];
            // Force bypasses schedule/mode only, never minimum retry spacing or backoff.
            if ($state['reconnect'] || $now - (int)$state['last_attempt'] < 60 || $now < (int)$state['next_attempt'] || (!$force && !$this->isDue($state, $now))) {
                return $this->safeStatus($state) + ['result' => 'skipped'];
            }
            $state['last_attempt'] = $now;
            $this->store->write($state);
            $this->client->beginBudget(90);
            try {
                $account = $state['account'];
                if ((int)$account['expires_at'] <= $now) throw new RuntimeException('Instagram muss erneut verbunden werden.', 190);
                if ((int)$account['expires_at'] - $now < 7 * 86400) {
                    $renewed = $this->client->refreshToken($account['token']);
                    $account['token'] = $renewed['access_token'];
                    $account['expires_at'] = $now + $renewed['expires_in'];
                    // Persist renewal even if the following image/media request fails.
                    $state['account'] = $account;
                    $this->store->write($state);
                }
                $snapshot = $this->client->fetchPosts($account['token'], $state['settings']['max_posts']);
                $existing = [];
                foreach ($state['posts'] as $previous) $existing[$previous['id']] = $previous;
                $posts = [];
                foreach ($snapshot as $post) {
                    $url = $post['remote_image'];
                    InstagramClient::imageHost($url);
                    $previous = $existing[$post['id']] ?? null;
                    if (is_array($previous) && $previous['type'] === $post['type'] && $previous['timestamp'] === $post['timestamp'] && $this->store->validImage($previous['local_image'])) {
                        $post['local_image'] = $previous['local_image'];
                    } else {
                        $bytes = ($this->downloader)($url, Store::MAX_IMAGE_BYTES, $this->client->remainingSeconds());
                        $this->client->remainingSeconds();
                        if (!is_string($bytes)) throw new RuntimeException('Das Vorschaubild konnte nicht geladen werden.', 502);
                        $post['local_image'] = $this->store->saveImage($post['id'], $bytes);
                    }
                    $post['image_url'] = $this->store->imageUrl($post['local_image']);
                    unset($post['remote_image']);
                    $posts[] = $post;
                }
                if ($posts === []) throw new RuntimeException('Instagram hat einen leeren Feed geliefert.', 502);
            } catch (\Throwable $error) {
                $state['error'] = $this->safeError($error);
                $state['reconnect'] = $error->getCode() === 190;
                $state['failures'] = min(10, (int)$state['failures'] + 1);
                $delay = $error->getCode() === 429 ? min(86400, 900 * 2 ** ($state['failures'] - 1)) : min(3600, 60 * 2 ** ($state['failures'] - 1));
                $state['next_attempt'] = $now + $delay;
                $this->store->write($state);
                // Discard only images staged by failed snapshots, retaining the committed set.
                try { $this->store->prune(array_column($state['posts'], 'local_image')); }
                catch (\Throwable) { /* Existing state is authoritative; next successful mutation retries cleanup. */ }
                return $this->safeStatus($state) + ['result' => 'error'];
            } finally { $this->client->endBudget(); }
            $state['posts'] = $posts;
            $state['last_success'] = time();
            $state['error'] = null;
            $state['reconnect'] = false;
            $state['failures'] = 0;
            $state['next_attempt'] = null;
            // Image files are immutable. A single metadata rename publishes the complete snapshot.
            $this->store->write($state);
            try { $this->store->prune(array_column($posts, 'local_image')); }
            catch (\Throwable) {
                $state['error'] = 'Der Feed wurde aktualisiert; alte Vorschaubilder konnten nicht vollständig bereinigt werden.';
                $this->store->write($state);
            }
            return $this->safeStatus($state) + ['result' => 'updated'];
        });
        return $result ?? ($this->status() + ['result' => 'busy']);
    }

    public function configure(array $settings): array
    {
        $this->store->updateSettings($settings);
        return $this->status();
    }

    public function disconnect(): void { $this->store->disconnect(); }

    /** Called only after a successful OAuth exchange and validated provider profile. */
    public function connect(array $account): void
    {
        $id = $account['id'] ?? null;
        $username = $account['username'] ?? null;
        $token = $account['token'] ?? null;
        $expiry = filter_var($account['expires_at'] ?? null, FILTER_VALIDATE_INT);
        if (!is_string($id) || !preg_match('/^[0-9]{1,40}$/D', $id) || !is_string($username) || !preg_match('/^[A-Za-z0-9_.]{1,30}$/D', $username)
            || !is_string($token) || !preg_match('/^[A-Za-z0-9_.|-]{1,4096}$/D', $token) || $expiry === false || $expiry <= time() || $expiry > time() + 90 * 86400) {
            throw new RuntimeException('Instagram hat keine gültige Kontoverbindung geliefert.');
        }
        $account = ['id' => $id, 'username' => $username, 'token' => $token, 'expires_at' => $expiry];
        $result = $this->store->withLock(function () use ($account): bool {
            $state = $this->store->read();
            $sameAccount = ($state['account']['id'] ?? null) === $account['id'];
            if (!$sameAccount) $state = array_replace(Store::defaults(), ['settings' => $state['settings']]);
            $state['account'] = $account;
            $state['error'] = null;
            $state['reconnect'] = false;
            $state['failures'] = 0;
            $state['next_attempt'] = null;
            $this->store->write($state);
            try { $this->store->prune(array_column($state['posts'], 'local_image')); }
            catch (\Throwable) {
                $state['error'] = 'Instagram wurde verbunden; alte Vorschaubilder konnten nicht vollständig bereinigt werden.';
                $this->store->write($state);
            }
            return true;
        });
        if ($result === null) throw new RuntimeException('Der Feed wird gerade aktualisiert.');
    }

    private function isDue(array $state, int $now): bool
    {
        return is_array($state['account']) && !$state['reconnect'] && $state['settings']['mode'] === 'request'
            && $now - (int)$state['last_attempt'] >= 60 && $now >= (int)$state['next_attempt']
            && ($state['last_success'] === null || $now - (int)$state['last_success'] >= $state['settings']['interval_minutes'] * 60);
    }

    private function safeStatus(array $state): array
    {
        $account = $state['account'];
        return ['connected' => is_array($account), 'account' => is_array($account) ? ['id' => $account['id'], 'username' => $account['username'], 'expires_at' => $account['expires_at']] : null,
            'settings' => $state['settings'], 'posts_count' => count($state['posts']), 'last_success' => $state['last_success'], 'last_attempt' => $state['last_attempt'],
            'error' => $state['error'], 'reconnect' => (bool)$state['reconnect'], 'next_attempt' => $state['next_attempt'],
            'external_sync_enabled' => is_string($state['sync_key_hash']) && preg_match('/^[a-f0-9]{64}$/D', $state['sync_key_hash']) === 1];
    }

    private function safeError(\Throwable $error): string
    {
        return match ($error->getCode()) {
            190 => 'Instagram muss erneut verbunden werden. Der letzte Stand bleibt erhalten.',
            429 => 'Instagram begrenzt derzeit die Abrufe. Der letzte Stand bleibt erhalten.',
            default => 'Instagram konnte nicht aktualisiert werden. Der letzte Stand bleibt erhalten.',
        };
    }
}
