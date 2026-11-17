<?php

return [

    /*
    | الواجهة على 5173 تستهلك الـ API على 8765 — النطاق محدد صراحة بدل '*'
    | (افتراضي: localhost/127.0.0.1:5173 — للنشر غيّر CORS_ALLOWED_ORIGINS في .env)
    | مع كشف Retry-After حتى تقرأ الواجهة ترقيم الطلبات (429).
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-XSRF-TOKEN'],

    // ترويسات غير Safelisted يجب كشفها صراحةً — وإلا res.headers.get('Retry-After') دائمًا null في المتصفح
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],

    'max_age' => 600,

    'supports_credentials' => false,

];
