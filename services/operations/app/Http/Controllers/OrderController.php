<?php

namespace App\Http\Controllers;

use App\Application\Menu;
use App\Application\Orders;
use App\Domain\InvalidSelection;
use App\Domain\OrderStatus;
use App\Domain\ProductUnavailable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Mestaro\Kernel\Auth\AccessClaims;
use Mestaro\Kernel\Http\ApiError;
use stdClass;

/** Diners read the menu and order; the staff read the restaurant's orders and decide on them. */
final class OrderController
{
    public function __construct(private readonly Orders $orders) {}

    public function menu(Menu $menu): JsonResponse
    {
        return response()->json(['data' => $menu->forDiner()]);
    }

    /** Everything ordered at the diner's table, whoever ordered it. */
    public function mine(Request $request): JsonResponse
    {
        $rows = DB::table('orders')->where('table_session_id', $request->attributes->get('table_session')->id)->orderBy('id')->get();

        return response()->json(['data' => $this->orders->present($rows)]);
    }

    /** Repeating a request with its `Idempotency-Key` answers the same and orders nothing again. */
    public function store(Request $request): JsonResponse
    {
        $participant = $request->attributes->get('participant');
        $session = $request->attributes->get('table_session');
        $key = (string) $request->header('Idempotency-Key');
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key) !== 1) {
            return ApiError::response('IDEMPOTENCY_KEY_REQUIRED', 'Send an Idempotency-Key of 8 to 64 letters, digits, "-" or "_".', 400);
        }
        $hash = hash('sha256', $request->getContent());
        // Before anything that may have changed since the first attempt, such as the product selling out.
        if (($replay = $this->replay($participant->id, $key, $hash)) !== null) {
            return $replay;
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'string', 'max:26'],
            'items.*.variant_id' => ['nullable', 'string', 'max:26'],
            'items.*.quantity' => ['required', 'integer:strict', 'between:1,50'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.addons' => ['sometimes', 'array', 'list', 'max:20'],
            // `distinct` would compare across lines, where repeating an extra is normal.
            'items.*.addons.*.addon_id' => ['required', 'string', 'max:26'],
            'items.*.addons.*.quantity' => ['required', 'integer:strict', 'between:1,100'],
        ]);
        foreach ($data['items'] as $i => $item) {
            $ids = array_column($item['addons'] ?? [], 'addon_id');
            if (count($ids) !== count(array_unique($ids))) {
                return ApiError::response('VALIDATION_FAILED', 'Invalid input.', 422, ["items.$i.addons" => ['Each extra goes once, with its quantity.']]);
            }
        }

        try {
            return DB::transaction(function () use ($participant, $session, $key, $hash, $data) {
                // Locked so the waiter cannot close the session between this check and the order.
                $status = DB::table('table_sessions')->where('id', $session->id)->lockForUpdate()->value('status');
                if ($status === 'pending_approval') {
                    return ApiError::response('SESSION_PENDING_APPROVAL', 'Wait for the waiter to approve the table.', 409);
                }
                if (! in_array($status, ['open', 'bill_requested'], true)) {
                    return ApiError::response('SESSION_CLOSED', 'This session is over. Scan the table QR again.', 409);
                }
                // A simultaneous retry waited on that lock and now fails on the unique key.
                DB::table('idempotency_keys')->insert(['participant_id' => $participant->id, 'key' => $key, 'request_hash' => $hash, 'created_at' => now()]);

                $order = $this->orders->place($session->id, $participant->id, $data['notes'] ?? null, $this->orders->price($data['items']));
                $body = ['data' => $this->orders->present(collect([$order]))[0]];
                DB::table('idempotency_keys')->where('participant_id', $participant->id)->where('key', $key)
                    ->update(['response_body' => json_encode($body, JSON_THROW_ON_ERROR)]);

                return response()->json($body, 201);
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->replay($participant->id, $key, $hash) ?? throw $e;
        } catch (ProductUnavailable $e) {
            return ApiError::response('ORDER_PRODUCT_UNAVAILABLE', $e->getMessage(), 422, ["items.{$e->getCode()}" => [$e->getMessage()]]);
        } catch (InvalidSelection $e) {
            return ApiError::response('VALIDATION_FAILED', 'Invalid input.', 422, ["items.{$e->getCode()}" => [$e->getMessage()]]);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter.status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $page = DB::table('orders')->when($data['filter']['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 50))->withQueryString();

        return response()->json([
            'data' => $this->orders->present(collect($page->items())),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $order = $this->find($id);

        return $order === null ? $this->unknown() : response()->json(['data' => $this->orders->present(collect([$order]))[0]]);
    }

    public function decide(Request $request, string $id, string $to): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $order = $this->find($id);
        if ($order === null) {
            return $this->unknown();
        }
        /** @var AccessClaims $claims */
        $claims = $request->attributes->get('claims');

        return $this->orders->transition($order, OrderStatus::from($to), $claims->userId, $data['reason'] ?? null)
            ? $this->show($id)
            : ApiError::response('INVALID_STATE_TRANSITION', 'The order is no longer in a state that allows this.', 409);
    }

    /** @phpstan-impure another request may have stored the key meanwhile */
    private function replay(int $participantId, string $key, string $hash): ?JsonResponse
    {
        $stored = DB::table('idempotency_keys')->where('participant_id', $participantId)->where('key', $key)->first();
        if ($stored === null) {
            return null;
        }

        return $stored->request_hash === $hash
            ? response()->json(json_decode($stored->response_body, true), 201)
            : ApiError::response('IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was used for a different request.', 409);
    }

    private function find(string $id): ?stdClass
    {
        return DB::table('orders')->where('public_id', strtolower($id))->first();
    }

    private function unknown(): JsonResponse
    {
        return ApiError::response('NOT_FOUND', 'Order not found.', 404);
    }
}
