<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    | الواجهة على 5173 تُستهلك الـ API على 8765 — عين المواقع صراحة واكشف Retry-After
    | حتى تستطيع الواجهة قراءة ترقيم الطلبات (429).
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // ترويسات غير Safelisted يجب كشفها صراحةً — وإلا res.headers.get('Retry-After') دائمًا null في المتصفح
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 0,

    'supports_credentials' => false,

];
