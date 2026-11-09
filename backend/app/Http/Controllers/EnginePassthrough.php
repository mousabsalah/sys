<?php

namespace App\Http\Controllers;

use App\Services\Engine\EngineClient;
use Illuminate\Http\Request;

class EnginePassthrough extends Controller
{
    public function health(EngineClient $engine)
    {
        return response()->json($engine->health());
    }

    public function check(Request $request, EngineClient $engine)
    {
        return response()->json($engine->check($request->all()));
    }
}
