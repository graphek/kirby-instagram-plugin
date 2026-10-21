<?php
declare(strict_types=1);

namespace Graphek\SocialMedia;

use Kirby\Cms\App;
use RuntimeException;

final class OAuth
{
    private const KEY = 'social-media.instagram.oauth';

    private static function appFingerprint(array $app): string
    {
        return hash('sha256', json_encode($app, JSON_THROW_ON_ERROR));
    }

    public static function redirectUri(App $kirby): string
    {
        return $kirby->url('base') . '/social-media/instagram/callback';
    }

    private static function binding(App $kirby): array
    {
        return Bridge::central($kirby) ? ['mode' => 'central'] + Bridge::serviceConfig($kirby) : Bridge::credentials($kirby);
    }

    public static function start(App $kirby, ?ConnectionClient $service = null): array
    {
        Bridge::guard($kirby);
        if (Bridge::central($kirby)) return self::startCentral($kirby, $service ?? Bridge::connectionClient($kirby));
        $app = Bridge::credentials($kirby);
        if ($app['app_id'] === '' || $app['app_secret'] === '') throw new RuntimeException('Bitte zuerst die Instagram-App im Panel einrichten.');
        $redirect = self::redirectUri($kirby);
        if (parse_url($redirect, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('Die Instagram-Verbindung benötigt eine HTTPS-Adresse.');
        $state = bin2hex(random_bytes(32));
        $kirby->session()->set(self::KEY, [
            'state' => $state, 'user' => $kirby->user()->id(), 'expires' => time() + 600, 'redirect' => $redirect,
            'app' => self::appFingerprint($app),
        ]);
        return ['url' => 'https://www.instagram.com/oauth/authorize?' . http_build_query([
            'client_id' => $app['app_id'],
            'redirect_uri' => $redirect, 'scope' => 'instagram_business_basic',
            'response_type' => 'code', 'state' => $state, 'enable_fb_login' => '0', 'force_authentication' => '1',
        ])];
    }

    private static function startCentral(App $kirby, ConnectionClient $service): array
    {
        if (!$kirby->request()->ssl() || parse_url(self::redirectUri($kirby), PHP_URL_SCHEME) !== 'https') throw new RuntimeException('Die Instagram-Verbindung benötigt HTTPS.');
        // Superseded starts must never leave an old session ticket usable.
        $kirby->session()->remove(self::KEY);
        $store = Bridge::store($kirby);
        $result = $store->withLock(function () use ($kirby, $service, $store): array {
            $state = bin2hex(random_bytes(32));
            $proof = bin2hex(random_bytes(32));
            $redirect = self::redirectUri($kirby);
            $started = $service->create($redirect, $state, $proof);
            $kirby->session()->set(self::KEY, [
                'state' => $state, 'proof' => $proof, 'connection_id' => $started['connection_id'],
                'user' => $kirby->user()->id(), 'expires' => $started['expires_at'], 'redirect' => $redirect,
                'app' => self::appFingerprint(self::binding($kirby)),
                'account' => self::appFingerprint($store->read()['account'] ?? []),
                'allow_replace' => $kirby->request()->body()->get('allow_replace') === true,
            ]);
            return ['url' => $started['start_url']];
        });
        if ($result === null) throw new RuntimeException('Der Feed wird gerade aktualisiert. Bitte erneut verbinden.');
        return $result;
    }

    private static function checkedState(App $kirby, string $state): array
    {
        Bridge::guard($kirby);
        $saved = $kirby->session()->get(self::KEY);
        if (!is_array($saved) || !is_string($saved['state'] ?? null)
            || !hash_equals($saved['state'], $state)
            || ($saved['expires'] ?? 0) <= time()
            || ($saved['user'] ?? '') !== $kirby->user()->id()
            || ($saved['redirect'] ?? '') !== self::redirectUri($kirby)
            || !is_string($saved['app'] ?? null) || !hash_equals($saved['app'], self::appFingerprint(self::binding($kirby)))) {
            throw new RuntimeException('Die Verbindungsanfrage ist ungültig oder abgelaufen. Bitte erneut verbinden.');
        }
        return $saved;
    }

    public static function consumeState(App $kirby, string $state): array
    {
        $saved = self::checkedState($kirby, $state);
        $kirby->session()->remove(self::KEY);
        return $saved;
    }

    public static function finish(App $kirby, ?ConnectionClient $service = null): void
    {
        Bridge::guard($kirby);
        if (Bridge::central($kirby)) { self::finishCentral($kirby, $service ?? Bridge::connectionClient($kirby)); return; }
        // Freeze app credentials during token exchange and account publication.
        $store = Bridge::store($kirby);
        try {
            $result = $store->withLock(function () use ($kirby, $store): bool {
                $saved = self::consumeState($kirby, (string)$kirby->request()->get('state', ''));
                $code = (string)$kirby->request()->get('code', '');
                if ($code === '' || strlen($code) > 4096 || $kirby->request()->get('error')) {
                    throw new RuntimeException('Die Instagram-Verbindung wurde nicht freigegeben.');
                }
                $app = Bridge::credentials($kirby);
                $client = new InstagramClient($app['app_id'], $app['app_secret'],
                    (string)$kirby->option(Bridge::OPTION . 'apiVersion', 'v25.0'));
                $token = $client->exchangeCode($code, $saved['redirect']);
                $account = $client->profile($token['access_token']);
                (new Feed($store, $client))->connect($account + [
                    'token' => $token['access_token'], 'expires_at' => time() + $token['expires_in'],
                ]);
                return true;
            });
            if ($result === null) throw new RuntimeException('Der Feed wird gerade aktualisiert. Bitte erneut verbinden.');
        } finally { Bridge::finishMutation($kirby); }
    }

    private static function finishCentral(App $kirby, ConnectionClient $service): void
    {
        if (!$kirby->request()->ssl()) throw new RuntimeException('Die Instagram-Verbindung benötigt HTTPS.');
        $store = Bridge::store($kirby);
        $result = $store->withLock(function () use ($kirby, $service, $store): bool {
            $state = $kirby->request()->query()->get('state');
            $id = $kirby->request()->query()->get('connection_id');
            $saved = self::checkedState($kirby, is_string($state) ? $state : '');
            if (!ConnectionClient::hex($id) || ($saved['connection_id'] ?? null) !== $id || !ConnectionClient::hex($saved['proof'] ?? null)
                || !is_string($saved['account'] ?? null) || !hash_equals($saved['account'], self::appFingerprint($store->read()['account'] ?? []))) {
                throw new RuntimeException('Die Verbindungsanfrage ist ungültig oder wurde überholt.');
            }
            // Unrelated callbacks cannot cancel a pending login. Consume only after
            // every local binding is verified, before attempting the one-use handoff.
            $kirby->session()->remove(self::KEY);
            $account = $service->redeem($id, $saved['proof']);
            $previous = $store->read()['account']['id'] ?? null;
            if ($previous !== null && $previous !== $account['id'] && ($saved['allow_replace'] ?? false) !== true) {
                throw new RuntimeException('Ein Kontowechsel muss vor der Anmeldung bestätigt werden.');
            }
            (new Feed($store, new InstagramClient('', '', (string)$kirby->option(Bridge::OPTION . 'apiVersion', 'v25.0'))))->connect($account);
            return true;
        });
        if ($result === null) {
            throw new RuntimeException('Der Feed wird gerade aktualisiert. Bitte erneut verbinden.');
        }
        Bridge::finishMutation($kirby);
    }
}
