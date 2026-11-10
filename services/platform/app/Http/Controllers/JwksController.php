<?php

namespace App\Http\Controllers;

use App\Infrastructure\Auth\JwtKeys;
use Illuminate\Http\JsonResponse;

final class JwksController
{
    public function __invoke(JwtKeys $keys): JsonResponse
    {
        return response()->json($keys->jwks())->header('Cache-Control', 'public, max-age=300');
    }
}
