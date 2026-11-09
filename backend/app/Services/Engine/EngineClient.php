<?php

namespace App\Services\Engine;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * CheckerClient → عميل Go engine HTTP/JSON.
 * Laravel ينسّق فقط: يجمع payload من DB ويمرره، لا يقرر التعارض.
 */
class EngineClient
{
    public function health(): array
    {
        $url = config('services.syst_engine.url');
        $response = Http::timeout(3)->get($url . '/health');
        if (!$response->successful()) {
            throw new RuntimeException("syst-engine unhealthy at {$url}: HTTP {$response->status()}");
        }

        return $response->json();
    }

    public function check(array $payload): array
    {
        $url = config('services.syst_engine.url');
        $response = Http::timeout(5)->post($url . '/check', $payload);
        if (!$response->successful()) {
            throw new RuntimeException("syst-engine /check failed at {$url}: HTTP {$response->status()} — {$response->body()}");
        }

        return $response->json();
    }
}
