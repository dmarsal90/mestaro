<?php

namespace App\Http\Controllers;

use App\Application\Tenancy\RegisterRestaurant;
use App\Application\Tenancy\RestaurantNotFound;
use App\Application\Tenancy\RetryProvisioning;
use App\Application\Tenancy\SlugAlreadyTaken;
use App\Application\Tenancy\SuspendRestaurant;
use App\Domain\Tenancy\Restaurant;
use App\Domain\Tenancy\RestaurantRepository;
use App\Http\ApiError;
use App\Http\Requests\RegisterRestaurantRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RestaurantAdminController
{
    public function store(RegisterRestaurantRequest $request, RegisterRestaurant $register, RestaurantRepository $restaurants): JsonResponse
    {
        $id = (string) Str::ulid();
        try {
            // Aggregate and outbox row commit together or not at all.
            DB::transaction(fn () => $register($id, $request->string('name')->toString(), $request->string('slug')->toString()));
        } catch (SlugAlreadyTaken) {
            return ApiError::response('SLUG_TAKEN', 'That slug is already in use.', 409);
        }

        return $this->resource($restaurants->find($id) ?? throw new RestaurantNotFound($id), 202);
    }

    public function show(string $id, RestaurantRepository $restaurants): JsonResponse
    {
        $restaurant = $restaurants->find($id);

        return $restaurant === null ? $this->notFound() : $this->resource($restaurant);
    }

    public function suspend(string $id, SuspendRestaurant $suspend, RestaurantRepository $restaurants): JsonResponse
    {
        return $this->transition($id, $restaurants, fn () => $suspend($id));
    }

    public function retryProvisioning(string $id, RetryProvisioning $retry, RestaurantRepository $restaurants): JsonResponse
    {
        return $this->transition($id, $restaurants, fn () => DB::transaction(fn () => $retry($id)), 202);
    }

    private function transition(string $id, RestaurantRepository $restaurants, callable $action, int $status = 200): JsonResponse
    {
        try {
            $action();
        } catch (RestaurantNotFound) {
            return $this->notFound();
        } catch (DomainException $e) {
            return ApiError::response('INVALID_STATE_TRANSITION', $e->getMessage(), 409);
        }

        return $this->resource($restaurants->find($id) ?? throw new RestaurantNotFound($id), $status);
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
