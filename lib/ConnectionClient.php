<?php
declare(strict_types=1);

namespace Graphek\SocialMedia;

use RuntimeException;

final class ConnectionClient
{
    public const CLOCK_SKEW_SECONDS = 60;
    private $transport;

    public function __construct(private string $origin, private string $project, private string $key, ?callable $transport = null)
    {
        self::origin($origin);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $project) || !self::hex($key)) {
            throw new RuntimeException('Die Instagram-Projektfreigabe ist unvollständig.');
        }
        $this->transport = $transport;
    }

    public static function hex(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }

    public static function origin(string $origin): void
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $parts['host'] ?? '')
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '' || strlen($origin) > 253) {
            throw new RuntimeException('Der Verbindungsdienst benötigt eine gültige HTTPS-Basisadresse ohne Pfad.');
        }
    }

    public function create(string $redirect, string $state, string $proof): array
    {
        $started = time();
        $data = $this->post('/v1/connections', ['redirect_uri' => $redirect, 'return_state' => $state, 'proof_hash' => hash('sha256', $proof)]);
        $expires = $data['expires_at'] ?? null;
        if (!self::hex($data['connection_id'] ?? null) || !is_string($data['start_url'] ?? null)
            || preg_match('~^' . preg_quote($this->origin, '~') . '/connect/[a-f0-9]{64}$~D', $data['start_url']) !== 1
            || !is_int($expires) || $expires <= time() || $expires > time() + 600 + self::CLOCK_SKEW_SECONDS) {
            throw new RuntimeException('Der Verbindungsdienst hat eine ungültige Anmeldung geliefert.');
        }
        // The local session ceiling never grows with provider/manager clock skew or latency.
        $data['expires_at'] = min($expires, $started + 600);
        return array_intersect_key($data, array_flip(['connection_id', 'start_url', 'expires_at']));
    }

    public function redeem(string $id, string $proof): array
    {
        if (!self::hex($id) || !self::hex($proof)) throw new RuntimeException('Ungültige Verbindungsanfrage.');
        $data = $this->post('/v1/connections/' . $id . '/redeem', ['proof' => $proof]);
        $account = $data['account'] ?? null;
        if (($data['connection_id'] ?? null) !== $id || !is_array($account)
            || !is_string($account['id'] ?? null) || !preg_match('/^[0-9]{1,40}$/D', $account['id'])
            || !is_string($account['username'] ?? null) || !preg_match('/^[A-Za-z0-9_.]{1,30}$/D', $account['username'])
            || !is_string($account['token'] ?? null) || !preg_match('/^[A-Za-z0-9_.|-]{1,4096}$/D', $account['token'])
            || !is_int($account['expires_at'] ?? null) || $account['expires_at'] <= time() || $account['expires_at'] > time() + 90 * 86400 + self::CLOCK_SKEW_SECONDS) {
            throw new RuntimeException('Der Verbindungsdienst hat keine gültige Kontoverbindung geliefert.');
        }
        $account['expires_at'] = min($account['expires_at'], time() + 90 * 86400);
        return array_intersect_key($account, array_flip(['id', 'username', 'token', 'expires_at']));
    }

    private function post(string $path, array $data): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Instagram-Project: ' . $this->project, 'Authorization: Bearer ' . $this->key];
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        try {
            $response = $this->transport !== null ? ($this->transport)('POST', $this->origin . $path, $headers, $body) : $this->curl($path, $headers, $body);
            if (!is_array($response) || !is_int($response['status'] ?? null) || $response['status'] < 200 || $response['status'] >= 300) throw new RuntimeException();
            $result = $response['body'] ?? null;
            if (is_string($result)) {
                if (strlen($result) > 16384) throw new RuntimeException();
                $result = json_decode($result, true, 8, JSON_THROW_ON_ERROR);
            }
            if (!is_array($result) || array_is_list($result) || strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 16384) throw new RuntimeException();
            return $result;
        } catch (\Throwable) {
            throw new RuntimeException('Der Instagram-Verbindungsdienst ist nicht erreichbar oder die Anfrage ist abgelaufen. Bitte erneut verbinden.');
        }
    }

    private function curl(string $path, array $headers, string $body): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException();
        $bytes = '';
        $handle = curl_init($this->origin . $path);
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => 5000, CURLOPT_TIMEOUT_MS => 20000, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
                if (strlen($bytes) + strlen($chunk) > 16384) return 0;
                $bytes .= $chunk; return strlen($chunk);
            }]);
        try {
            if (curl_exec($handle) === false) throw new RuntimeException();
            return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $bytes];
        } finally { unset($handle); }
    }
}
