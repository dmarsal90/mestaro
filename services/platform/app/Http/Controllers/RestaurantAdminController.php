<?php

namespace App\Http\Controllers;

use App\Application\Audit\AuditTrail;
use App\Application\Tenancy\RegisterRestaurant;
use App\Application\Tenancy\RestaurantNotFound;
use App\Application\Tenancy\RetryProvisioning;
use App\Application\Tenancy\SlugAlreadyTaken;
use App\Application\Tenancy\SuspendRestaurant;
use App\Domain\Tenancy\Restaurant;
use App\Domain\Tenancy\RestaurantRepository;
use App\Http\Requests\RegisterRestaurantRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Http\ApiError;

final class RestaurantAdminController
{
    public function __construct(private RestaurantRepository $restaurants, private AuditTrail $audit) {}

    public function store(RegisterRestaurantRequest $request, RegisterRestaurant $register): JsonResponse
    {
        $id = (string) Str::ulid();
        $name = $request->string('name')->toString();
        $slug = $request->string('slug')->toString();

        try {
            // Aggregate, outbox row and audit entry commit together or not at all.
            DB::transaction(function () use ($register, $id, $name, $slug, $request) {
                $register($id, $name, $slug);
                $this->audit->record('restaurant.registered', $this->actor($request), 'restaurant', $id, ['slug' => $slug, 'name' => $name]);
            });
        } catch (SlugAlreadyTaken) {
            return ApiError::response('SLUG_TAKEN', 'That slug is already in use.', 409);
        }

        return $this->resource($this->restaurants->find($id) ?? throw new RestaurantNotFound($id), 202);
    }

    public function show(string $id): JsonResponse
    {
        $restaurant = $this->restaurants->find($id);

        return $restaurant === null ? $this->notFound() : $this->resource($restaurant);
    }

    public function suspend(string $id, Request $request, SuspendRestaurant $suspend): JsonResponse
    {
        return $this->transition('restaurant.suspended', $id, $request, fn () => $suspend($id));
    }

    public function retryProvisioning(string $id, Request $request, RetryProvisioning $retry): JsonResponse
    {
        return $this->transition('restaurant.provisioning_retried', $id, $request, fn () => $retry($id), 202);
    }

    private function transition(string $action, string $id, Request $request, callable $change, int $status = 200): JsonResponse
    {
        try {
            DB::transaction(function () use ($action, $id, $request, $change) {
                $change();
                $this->audit->record($action, $this->actor($request), 'restaurant', $id);
            });
        } catch (RestaurantNotFound) {
            return $this->notFound();
        } catch (DomainException $e) {
            return ApiError::response('INVALID_STATE_TRANSITION', $e->getMessage(), 409);
        }

        return $this->resource($this->restaurants->find($id) ?? throw new RestaurantNotFound($id), $status);
    }

    private function actor(Request $request): string
    {
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');

        return $claims->userId;
    }

    private function notFound(): JsonResponse
    {
        return ApiError::response('NOT_FOUND', 'Restaurant not found.', 404);
    }

    private function resource(Restaurant $r, int $status = 200): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $r->id(),
            'name' => $r->name(),
            'slug' => $r->slug(),
            'status' => $r->status()->value,
            'failure_reason' => $r->failureReason(),
        ]], $status);
    }
}
