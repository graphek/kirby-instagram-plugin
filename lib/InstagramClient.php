<?php
declare(strict_types=1);

namespace Graphek\SocialMedia;

use RuntimeException;

final class InstagramClient
{
    private $transport;
    private ?float $deadline = null;

    public function __construct(private string $appId, private string $appSecret, private string $apiVersion = 'v25.0', ?callable $transport = null)
    {
        if (!preg_match('/^v[0-9]{1,2}\.0$/D', $apiVersion)) throw new RuntimeException('Ungültige Instagram-API-Version.');
        $this->transport = $transport;
    }

    public function beginBudget(int $seconds = 90): void { $this->deadline = microtime(true) + max(1, min(90, $seconds)); }
    public function endBudget(): void { $this->deadline = null; }
    public function remainingSeconds(): float
    {
        $remaining = $this->deadline === null ? 20.0 : $this->deadline - microtime(true);
        if ($remaining <= 0) throw new RuntimeException('Das Zeitlimit für den Instagram-Abruf wurde erreicht.', 502);
        return $remaining;
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        if ($this->appId === '' || $this->appSecret === '' || $code === '' || strlen($code) > 4096) throw new RuntimeException('Instagram ist nicht vollständig eingerichtet.');
        $short = $this->request('POST', 'https://api.instagram.com/oauth/access_token', [], [
            'client_id' => $this->appId, 'client_secret' => $this->appSecret, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirectUri, 'code' => $code,
        ]);
        // Meta's Instagram Login response can wrap a single token in data[].
        if (isset($short['data'])) {
            if (isset($short['access_token']) || !is_array($short['data']) || count($short['data']) !== 1 || !is_array($short['data'][0] ?? null)) throw new RuntimeException('Instagram hat keine gültige Verbindung bestätigt.', 502);
            $short = $short['data'][0];
        }
        $token = $this->token($short['access_token'] ?? null);
        $long = $this->request('GET', 'https://graph.instagram.com/access_token', [], [
            'grant_type' => 'ig_exchange_token', 'client_secret' => $this->appSecret, 'access_token' => $token,
        ]);
        return $this->tokenResult($long);
    }

    public function profile(string $token): array
    {
        $profile = $this->graph('me', $token, ['fields' => 'user_id,username']);
        $id = (string)($profile['user_id'] ?? $profile['id'] ?? '');
        $username = $profile['username'] ?? null;
        if (!preg_match('/^[0-9]{1,40}$/D', $id) || !is_string($username) || !preg_match('/^[A-Za-z0-9_.]{1,30}$/D', $username)) throw new RuntimeException('Instagram hat kein gültiges Konto geliefert.', 502);
        return ['id' => $id, 'username' => $username];
    }

    public function refreshToken(string $token): array
    {
        $response = $this->request('GET', 'https://graph.instagram.com/refresh_access_token', [], ['grant_type' => 'ig_refresh_token', 'access_token' => $this->token($token)]);
        return $this->tokenResult($response);
    }

    /** Inspect at most 100 newest media; skip unsupported media, never follow provider URLs. */
    public function fetchPosts(string $token, int $maxPosts): array
    {
        if ($maxPosts < 1 || $maxPosts > 100) throw new RuntimeException('Ungültige Anzahl von Instagram-Beiträgen.');
        $posts = [];
        $scanned = 0;
        $after = null;
        $cursors = [];
        do {
            $params = ['fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,children.limit(20){id,media_type,media_url,thumbnail_url}', 'limit' => min(100 - $scanned, max(25, $maxPosts))];
            if ($after !== null) $params['after'] = $after;
            $result = $this->graph('me/media', $token, $params);
            if (!isset($result['data']) || !is_array($result['data']) || !array_is_list($result['data'])) throw new RuntimeException('Instagram hat keinen gültigen Feed geliefert.', 502);
            if ($result['data'] === [] && $scanned === 0) throw new RuntimeException('Instagram hat einen leeren Feed geliefert; der letzte Stand bleibt erhalten.', 502);
            foreach ($result['data'] as $media) {
                if (++$scanned > 100) break;
                if (!is_array($media)) throw new RuntimeException('Instagram hat ungültige Beitragsdaten geliefert.', 502);
                $post = $this->normalize($media);
                if ($post !== null) $posts[$post['id']] = $post;
                if (count($posts) >= $maxPosts) break;
            }
            $after = null;
            if (isset($result['paging']['next'])) {
                $cursor = $result['paging']['cursors']['after'] ?? null;
                if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 4096 || isset($cursors[$cursor])) throw new RuntimeException('Instagram hat eine ungültige Seitennavigation geliefert.', 502);
                $cursors[$cursor] = true;
                $after = $cursor;
            }
        } while ($after !== null && count($posts) < $maxPosts && $scanned < 100 && $result['data'] !== []);
        if ($posts === []) throw new RuntimeException('Instagram hat keine unterstützten Vorschaubilder geliefert.', 502);
        $posts = array_values($posts);
        usort($posts, static fn(array $a, array $b): int => strcmp($b['timestamp'], $a['timestamp']) ?: strcmp($b['id'], $a['id']));
        return array_slice($posts, 0, $maxPosts);
    }

    private function normalize(array $media): ?array
    {
        $type = $media['media_type'] ?? '';
        if (!in_array($type, ['IMAGE', 'VIDEO', 'CAROUSEL_ALBUM'], true)) return null;
        $id = (string)($media['id'] ?? '');
        $timestamp = $media['timestamp'] ?? null;
        $permalink = $media['permalink'] ?? null;
        if (!preg_match('/^[0-9]{1,40}$/D', $id) || !is_string($timestamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:?\d{2})$/D', $timestamp) || !self::permalink($permalink)) throw new RuntimeException('Instagram hat ungültige Beitragsdaten geliefert.', 502);
        try { $date = new \DateTimeImmutable($timestamp); }
        catch (\Exception) { throw new RuntimeException('Instagram hat ein ungültiges Datum geliefert.', 502); }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new RuntimeException('Instagram hat ein ungültiges Datum geliefert.', 502);
        $url = match ($type) { 'IMAGE' => $media['media_url'] ?? null, 'VIDEO' => $media['thumbnail_url'] ?? null, default => null };
        if ($type === 'CAROUSEL_ALBUM') {
            if (!is_array($media['children']['data'] ?? null)) throw new RuntimeException('Instagram hat unvollständige Albumdaten geliefert.', 502);
            foreach (array_slice($media['children']['data'], 0, 20) as $child) {
                if (!is_array($child)) throw new RuntimeException('Instagram hat ungültige Albumdaten geliefert.', 502);
                $candidate = match ($child['media_type'] ?? '') { 'IMAGE' => $child['media_url'] ?? null, 'VIDEO' => $child['thumbnail_url'] ?? null, default => null };
                if (is_string($candidate) && $candidate !== '') { $url = $candidate; break; }
            }
        }
        if (!is_string($url) || $url === '') return null;
        self::imageHost($url);
        $caption = $media['caption'] ?? '';
        if (!is_string($caption) || strlen($caption) > 10000) throw new RuntimeException('Instagram hat eine ungültige Bildunterschrift geliefert.', 502);
        return ['id' => $id, 'timestamp' => $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'caption' => $caption, 'permalink' => $permalink, 'type' => $type, 'remote_image' => $url];
    }

    public static function permalink(mixed $url): bool
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), ['instagram.com', 'www.instagram.com'], true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']) && (!isset($parts['port']) || $parts['port'] === 443)
            && preg_match('~^/(p|reel|tv)/[A-Za-z0-9_-]+/?$~D', $parts['path'] ?? '') === 1;
    }

    /** Suffix allowlist, not substring matching. Redirects are forbidden separately. */
    public static function imageHost(string $url): string
    {
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) throw new RuntimeException('Instagram hat eine unzulässige Bildadresse geliefert.', 502);
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.(?:cdninstagram\.com|fbcdn\.net)$/D', $host)) throw new RuntimeException('Instagram hat eine unzulässige Bildadresse geliefert.', 502);
        return $host;
    }

    public static function downloadImage(string $url, int $maxBytes = Store::MAX_IMAGE_BYTES, float $remainingSeconds = 20): string
    {
        $host = self::imageHost($url);
        if (!extension_loaded('curl')) throw new RuntimeException('Für den Instagram-Abruf wird PHP cURL benötigt.');
        if (!defined('CURLOPT_PREREQFUNCTION')) throw new RuntimeException('Die cURL-Version unterstützt keine sichere Bildverbindung.');
        $timeout = max(1, (int)ceil(min(20, $remainingSeconds) * 1000));
        $bytes = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeout), CURLOPT_TIMEOUT_MS => $timeout, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '',
            // Validate the actual peer after TLS and before any HTTP data is sent.
            // cURL resolves within its timeout; no separate DNS lookup can be rebound.
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PREREQFUNCTION => static function ($handle, string $primaryIp, string $localIp, int $primaryPort, int $localPort): int {
                return self::publicAddress($primaryIp) && $primaryPort === 443 ? CURL_PREREQFUNC_OK : CURL_PREREQFUNC_ABORT;
            }, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes, $maxBytes): int {
                if (strlen($bytes) + strlen($chunk) > min(Store::MAX_IMAGE_BYTES, $maxBytes)) return 0;
                $bytes .= $chunk;
                return strlen($chunk);
            }]);
        try {
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($ok === false || $status !== 200) throw new RuntimeException('Das Instagram-Vorschaubild konnte nicht geladen werden.', 502);
        } finally { curl_close($curl); }
        return $bytes;
    }

    public static function publicAddress(string $address): bool
    {
        // GLOBAL_RANGE excludes CGNAT, documentation and benchmarking networks,
        // but PHP still classifies multicast as global. Require unicast IPv4.
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) !== false
            && (int)explode('.', $address, 2)[0] < 224;
    }

    private function graph(string $path, string $token, array $params): array
    {
        return $this->request('GET', 'https://graph.instagram.com/' . $this->apiVersion . '/' . $path, ['Authorization: Bearer ' . $this->token($token)], $params);
    }

    private function token(mixed $token): string
    {
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_.|-]{1,4096}$/D', $token)) throw new RuntimeException('Instagram muss erneut verbunden werden.', 190);
        return $token;
    }

    private function tokenResult(array $result): array
    {
        $token = $this->token($result['access_token'] ?? null);
        $ttl = filter_var($result['expires_in'] ?? null, FILTER_VALIDATE_INT);
        if ($ttl === false || $ttl < 1 || $ttl > 90 * 86400) throw new RuntimeException('Instagram hat keine gültige Token-Laufzeit geliefert.', 502);
        return ['access_token' => $token, 'expires_in' => $ttl];
    }

    private function request(string $method, string $url, array $headers, array $params): array
    {
        $this->remainingSeconds();
        $body = null;
        if ($method === 'GET') $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        else { $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        try {
            if ($this->transport !== null) $response = ($this->transport)($method, $url, $headers, $body);
            else $response = $this->curl($method, $url, $headers, $body);
        } catch (\Throwable) { throw new RuntimeException('Instagram ist derzeit nicht erreichbar.', 502); }
        $this->remainingSeconds();
        if (!is_array($response)) throw new RuntimeException('Instagram hat keine gültige Antwort geliefert.', 502);
        $status = $response['status'] ?? 0;
        $data = $response['body'] ?? null;
        if (is_string($data)) {
            try { $data = json_decode($data, true, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new RuntimeException('Instagram hat keine gültige Antwort geliefert.', 502); }
        }
        if (!is_array($data)) throw new RuntimeException('Instagram hat keine gültige Antwort geliefert.', 502);
        if (isset($data['error']) || $status < 200 || $status >= 300) {
            $code = is_array($data['error'] ?? null) ? ($data['error']['code'] ?? 0) : 0;
            if ($code === 190 || $status === 401) throw new RuntimeException('Instagram muss erneut verbunden werden.', 190);
            if ($status === 429 || in_array($code, [4, 17, 32, 613], true)) throw new RuntimeException('Instagram begrenzt derzeit die Abrufe. Der letzte Stand bleibt erhalten.', 429);
            throw new RuntimeException('Instagram konnte den Abruf nicht abschließen. Der letzte Stand bleibt erhalten.', 502);
        }
        return $data;
    }

    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL fehlt.');
        $bytes = '';
        $timeout = max(1, (int)ceil(min(20, $this->remainingSeconds()) * 1000));
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeout), CURLOPT_TIMEOUT_MS => $timeout, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
                if (strlen($bytes) + strlen($chunk) > 2 * 1024 * 1024) return 0;
                $bytes .= $chunk; return strlen($chunk);
            }]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        try {
            if (curl_exec($curl) === false) throw new RuntimeException('Instagram ist nicht erreichbar.');
            return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $bytes];
        } finally { curl_close($curl); }
    }
}
