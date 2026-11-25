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
        ['name' => 'calibre#enable', 'url' => '/settings/calibre', 'verb' => 'POST'],

        // Book browser (session + CSRF protected)
        ['name' => 'library#index', 'url' => '/library', 'verb' => 'GET'],
        ['name' => 'library#books', 'url' => '/api/books', 'verb' => 'GET'],
        ['name' => 'library#book', 'url' => '/api/books/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'library#browse', 'url' => '/api/browse/{kind}', 'verb' => 'GET'],
        ['name' => 'library#author', 'url' => '/api/author', 'verb' => 'GET'],
        ['name' => 'library#refresh', 'url' => '/api/refresh', 'verb' => 'POST'],

        // Calibre libraries (beta, has to be turned on by the user)
        ['name' => 'calibre#update', 'url' => '/api/books/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'calibre#names', 'url' => '/api/books/{id}/names', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'calibre#setCover', 'url' => '/api/books/{id}/cover', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'calibre#coverFromFile', 'url' => '/api/books/{id}/cover/file', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'calibre#removeCover', 'url' => '/api/books/{id}/cover', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'calibre#libraries', 'url' => '/api/calibre', 'verb' => 'GET'],
        ['name' => 'calibre#create', 'url' => '/api/calibre', 'verb' => 'POST'],
        ['name' => 'calibre#import', 'url' => '/api/calibre/import', 'verb' => 'POST'],
        ['name' => 'calibre#useLibrary', 'url' => '/api/calibre/use', 'verb' => 'POST'],
        ['name' => 'calibre#backup', 'url' => '/api/calibre/backup', 'verb' => 'GET'],
    ],
];
