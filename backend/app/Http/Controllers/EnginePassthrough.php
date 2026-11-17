<?php

namespace App\Http\Controllers;

use App\Services\Engine\EngineClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnginePassthrough extends Controller
{
    public function health(EngineClient $engine): JsonResponse
    {
        return response()->json($engine->health());
    }

    public function check(Request $request, EngineClient $engine): JsonResponse
    {
        return response()->json($engine->check($request->all()));
    }
}
