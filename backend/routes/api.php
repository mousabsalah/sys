<?php

use App\Services\Engine\EngineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/syst-engine/health', function (EngineClient $engine) {
    return $engine->health();
});

Route::post('/syst-engine/check', function (Request $request, EngineClient $engine) {
    return $engine->check($request->all());
});
