<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Admin-Frontend und Viewer laufen auf eigenen Subdomains und greifen damit
    | cross-origin auf die API zu. Laravels Default `['*']` würde jeder beliebigen
    | Webseite erlauben, die API im Browser eines eingeloggten Nutzers aufzurufen.
    |
    | Das ist hier keine Katastrophe, weil die Authentifizierung über Bearer-Token
    | aus dem localStorage läuft und nicht über Cookies — ein fremder Origin bekommt
    | den Token nicht automatisch mitgeschickt. Die Einschränkung ist Härtung, keine
    | Voraussetzung; sie begrenzt, wer die öffentlichen Endpunkte im Browser fremder
    | Nutzer auslesen kann.
    |
    | Die erlaubten Origins stehen in der .env (`CORS_ALLOWED_ORIGINS`, kommagetrennt),
    | damit sie je Umgebung abweichen können.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Content-Encoding', 'X-Requested-With'],

    // Damit das Frontend das Rate-Limit sehen kann, ohne raten zu müssen.
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 3600,

    // Bearer-Token statt Cookies — Credentials werden nicht mitgesendet.
    'supports_credentials' => false,

];
