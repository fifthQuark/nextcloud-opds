<?php

declare(strict_types=1);

/**
 * All catalog routes are public pages that perform their own HTTP Basic
 * authentication (user name + app password) so that e-reader apps, which do
 * not have a Nextcloud session cookie, can use them.
 */
return [
    'routes' => [
        // OPDS catalog
        ['name' => 'opds#root', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'opds#books', 'url' => '/books', 'verb' => 'GET'],
        ['name' => 'opds#search', 'url' => '/search', 'verb' => 'GET'],
        ['name' => 'opds#group', 'url' => '/group/{kind}', 'verb' => 'GET'],
        ['name' => 'opds#author', 'url' => '/author', 'verb' => 'GET'],
        ['name' => 'opds#folders', 'url' => '/folders', 'verb' => 'GET'],
        ['name' => 'opds#opensearch', 'url' => '/opensearch.xml', 'verb' => 'GET'],
        ['name' => 'opds#download', 'url' => '/download/{id}/{name}', 'verb' => 'GET', 'requirements' => ['name' => '.+']],
        ['name' => 'opds#cover', 'url' => '/cover/{id}', 'verb' => 'GET'],
        ['name' => 'opds#thumbnail', 'url' => '/thumb/{id}', 'verb' => 'GET'],
        ['name' => 'opds#news', 'url' => '/news', 'verb' => 'GET'],
        ['name' => 'opds#newsDownload', 'url' => '/news/download/{name}', 'verb' => 'GET', 'requirements' => ['name' => '.+']],

        // Personal settings (session + CSRF protected, used by the web UI)
        ['name' => 'settings#save', 'url' => '/settings', 'verb' => 'POST'],
        ['name' => 'settings#scan', 'url' => '/settings/scan', 'verb' => 'POST'],
        ['name' => 'settings#reset', 'url' => '/settings/reset', 'verb' => 'POST'],
        ['name' => 'settings#saveNews', 'url' => '/settings/news', 'verb' => 'POST'],
    ],
];
