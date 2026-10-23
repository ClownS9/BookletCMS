<?php

/*
| Booklet answers browsers on other sites itself, for the content API only,
| from the origins allowed in Settings › Content API (see ContentApiAccess).
| Laravel's own CORS handling is kept out of the way: without this file it
| would let any website read /api/* by default.
*/

return [
    'paths' => [],
    'allowed_methods' => ['GET', 'OPTIONS'],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
