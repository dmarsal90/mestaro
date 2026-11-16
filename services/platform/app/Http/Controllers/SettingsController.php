<?php

namespace App\Http\Controllers;

use App\Application\Audit\AuditTrail;
use App\Http\Requests\UpdateSettingsRequest;
use App\Infrastructure\Tenancy\RestaurantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mestaro\Kernel\Auth\AccessClaims;

/** The restaurant always comes from the token, never from the request. */
final class SettingsController
{
    public function show(Request $request, RestaurantSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->get($this->claims($request)->tenantId)]);
    }

    public function update(UpdateSettingsRequest $request, RestaurantSettings $settings, AuditTrail $audit): JsonResponse
    {
        $claims = $this->claims($request);
        $changes = $request->validated();
        $data = $settings->update($claims->tenantId, $changes);
        $audit->record('settings.updated', $claims->userId, 'restaurant', $claims->tenantId, ['fields' => array_keys($changes)]);

        return response()->json(['data' => $data]);
    }

    private function claims(Request $request): AccessClaims
    {
        return $request->attributes->get('claims');
    }
}
