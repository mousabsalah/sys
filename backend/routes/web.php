<?php

use Illuminate\Support\Facades\Route;

// الصفحة الرئيسية — توجّه إلى الواجهة (React/Vite)؛ لا تعرض صفحة Laravel العامة.
Route::get('/', function () {
    $url = env('FRONTEND_URL', 'http://127.0.0.1:5173');

    return redirect()->away($url, 302);
});
