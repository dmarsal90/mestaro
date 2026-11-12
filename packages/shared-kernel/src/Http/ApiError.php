<?php

namespace Mestaro\Kernel\Http;

use Illuminate\Http\JsonResponse;

/** The single error envelope from docs/api.md; never leaks exception details. */
final class ApiError
{
    /** @param array<string, list<string>>|null $details */
    public static function response(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        return response()->json(['error' => array_filter(
            ['code' => $code, 'message' => $message, 'details' => $details],
            fn ($v) => $v !== null,
        )], $status);
    }
}
