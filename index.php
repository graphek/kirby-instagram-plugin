<?php
declare(strict_types=1);

use Graphek\SocialMedia\Bridge;
use Graphek\SocialMedia\OAuth;
use Graphek\SocialMedia\SyncEndpoint;
use Kirby\Cms\App;
use Kirby\Http\Response;

foreach (['Store', 'InstagramClient', 'Feed', 'LocalImage', 'ConnectionClient', 'Bridge', 'OAuth', 'SyncEndpoint'] as $class) {
    require_once __DIR__ . '/lib/' . $class . '.php';
}

App::plugin('graphek/social-media', [
    'options' => [
        'enabled' => false,
        'appId' => getenv('GRAPHEK_INSTAGRAM_APP_ID') ?: '',
        'appSecret' => getenv('GRAPHEK_INSTAGRAM_APP_SECRET') ?: '',
        'apiVersion' => 'v25.0',
        'roles' => ['admin'],
        'connectionMode' => 'direct',
        'serviceUrl' => getenv('GRAPHEK_INSTAGRAM_SERVICE_URL') ?: '',
        'projectId' => getenv('GRAPHEK_INSTAGRAM_PROJECT_ID') ?: '',
        'installationKey' => getenv('GRAPHEK_INSTAGRAM_INSTALLATION_KEY') ?: '',
    ],
    'assets' => ['index.js' => __DIR__ . '/index.js', 'index.css' => __DIR__ . '/index.css'],
    'api' => [
        'routes' => [
            [
                'pattern' => 'social-media/instagram/app', 'method' => 'POST',
                'action' => fn (): array => Bridge::configureApp(App::instance()),
            ],
            [
                'pattern' => 'social-media/instagram/status', 'method' => 'GET',
                'action' => function (): array {
                    $kirby = App::instance(); Bridge::guard($kirby);
                    return Bridge::status($kirby);
                },
            ],
            [
                'pattern' => 'social-media/instagram/settings', 'method' => 'POST',
                'action' => function (): array {
                    $kirby = App::instance(); Bridge::guard($kirby);
                    $settings = [];
                    foreach (['max_posts', 'interval_minutes', 'mode'] as $key) {
                        $value = $kirby->request()->get($key);
                        if ($value !== null) $settings[$key] = $value;
                    }
                    try { Bridge::feed($kirby)->configure($settings); }
                    finally { Bridge::finishMutation($kirby); }
                    return Bridge::status($kirby);
                },
            ],
            [
                'pattern' => 'social-media/instagram/connect', 'method' => 'POST',
                'action' => fn (): array => OAuth::start(App::instance()),
            ],
            [
                'pattern' => 'social-media/instagram/disconnect', 'method' => 'POST',
                'action' => function (): array {
                    $kirby = App::instance(); Bridge::guard($kirby);
                    try { Bridge::feed($kirby)->disconnect(); }
                    finally { Bridge::finishMutation($kirby); }
                    return Bridge::status($kirby);
                },
            ],
            [
                'pattern' => 'social-media/instagram/sync', 'method' => 'POST',
                'action' => function (): array {
                    $kirby = App::instance(); Bridge::guard($kirby);
                    return Bridge::sync($kirby);
                },
            ],
            [
                'pattern' => 'social-media/instagram/sync-key', 'method' => 'POST',
                'action' => function (): array {
                    $kirby = App::instance(); Bridge::guard($kirby);
                    if (!$kirby->request()->ssl()) throw new RuntimeException('Sync-Schlüssel benötigen HTTPS.');
                    $kirby->response()->header('Cache-Control', 'no-store');
                    $store = Bridge::store($kirby);
                    return match ($kirby->request()->get('action')) {
                        'rotate' => ['key' => $store->rotateSyncKey()],
                        'revoke' => ['revoked' => $store->revokeSyncKey()],
                        default => throw new RuntimeException('Ungültige Schlüssel-Aktion.'),
                    };
                },
            ],
        ],
    ],
    'areas' => [
        'social-media' => static function ($kirby): array {
            try { Bridge::guard($kirby); } catch (Throwable) { return []; }
            return [
                'label' => 'Instagram', 'icon' => 'image', 'menu' => true, 'link' => 'social-media',
                'views' => [[
                    'pattern' => 'social-media',
                    'action' => function () use ($kirby): array {
                        Bridge::guard($kirby);
                        return [
                            'component' => 'social-media-view', 'title' => 'Instagram',
                            'props' => [
                                'status' => Bridge::status($kirby),
                                'configured' => Bridge::configured($kirby),
                                'redirectUri' => Bridge::central($kirby) ? '' : OAuth::redirectUri($kirby),
                                'automaticAvailable' => function_exists('fastcgi_finish_request'),
                                'syncUrl' => SyncEndpoint::url($kirby),
                            ],
                        ];
                    },
                ]],
            ];
        },
    ],
    'routes' => [
        [
            'pattern' => 'social-media/instagram/sync', 'method' => 'POST',
            'action' => fn () => SyncEndpoint::handle(App::instance()),
        ],
        [
            'pattern' => 'social-media/instagram/callback', 'method' => 'GET',
            'action' => function () {
                $kirby = App::instance();
                if (Bridge::central($kirby)) {
                    try { OAuth::finish($kirby); $notice = 'success'; }
                    catch (Throwable) { $notice = 'failed'; }
                    $kirby->session()->set('social-media.instagram.notice', $notice);
                    return new Response('', 'text/html', 303, [
                        'Location' => $kirby->url('panel') . '/social-media', 'Cache-Control' => 'no-store',
                        'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
                    ]);
                }
                try {
                    OAuth::finish($kirby);
                    $message = 'Instagram ist verbunden. Bitte den ersten Abruf im Panel starten.';
                    $code = 200;
                } catch (Throwable) {
                    $message = 'Die Instagram-Verbindung ist fehlgeschlagen oder abgelaufen. Bitte im Panel erneut verbinden.';
                    $code = 400;
                }
                return new Response('<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Instagram-Verbindung</title><body><main><h1>Instagram-Verbindung</h1><p>' . esc($message) . '</p><p><a href="' . esc($kirby->url('panel') . '/social-media', 'attr') . '">Zurück zum Kirby Panel</a></p></main></body></html>', 'text/html', $code, [
                    'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer',
                    'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; frame-ancestors 'none'",
                ]);
            },
        ],
    ],
    'hooks' => [
        // Kirby binds hooks to its App and route/view actions to their Router context.
        // These callbacks must not be static, even when they do not use $this.
        'route:after' => function ($route, $path, $method, $result, $final) {
            $kirby = App::instance();
            $api = trim((string)$kirby->option('api.slug', 'api'), '/');
            $panel = trim((string)$kirby->option('panel.slug', 'panel'), '/');
            if ($final && $method === 'GET' && !preg_match('~^(?:' . preg_quote($api, '~') . '|' . preg_quote($panel, '~') . '|social-media|media)(?:/|$)~', $path)) {
                Bridge::defer($kirby);
            }
            return $result;
        },
    ],
    'commands' => [
        'social:sync' => [
            'description' => 'Synchronisiert die lokal gespeicherten Instagram-Beiträge.',
            'command' => static function ($cli): void {
                $kirby = $cli->kirby();
                if (!Bridge::enabled($kirby)) throw new RuntimeException('Das Instagram-Plugin ist deaktiviert.');
                $status = Bridge::sync($kirby);
                if (($status['result'] ?? '') === 'error') throw new RuntimeException($status['error'] ?? 'Instagram-Abruf fehlgeschlagen.');
                $cli->out('Instagram: ' . ($status['result'] ?? 'unbekannt') . ', ' . $status['posts_count'] . ' Beiträge.');
            },
        ],
    ],
]);
