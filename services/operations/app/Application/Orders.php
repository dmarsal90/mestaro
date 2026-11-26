<?php

namespace App\Application;

use App\Domain\InvalidSelection;
use App\Domain\OrderStatus;
use App\Domain\Pricing;
use App\Domain\ProductUnavailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** Orders in the restaurant's database. */
final readonly class Orders
{
    public function __construct(private CatalogProjection $projection, private Menu $menu) {}

    /**
     * Prices every line from the projection. Throws ProductUnavailable or InvalidSelection with the line's index as code.
     *
     * @param  list<array{product_id: string, variant_id?: ?string, quantity: int, notes?: ?string, addons?: list<array{addon_id: string, quantity: int}>}>  $items
     * @return list<array<string, mixed>>
     */
    public function price(array $items): array
    {
        $settings = $this->projection->settings();
        $time = $this->menu->localTime();
        $lines = [];
        foreach ($items as $i => $item) {
            $product = $this->projection->find('product', $item['product_id']);
            // A product's event does not carry its category's state (docs/events.md).
            if ($product !== null && ! ($this->projection->find('category', $product['category_id'] ?? '')['is_active'] ?? false)) {
                $product = null;
            }
            try {
                $line = Pricing::line($product, $item['variant_id'] ?? null, array_column($item['addons'] ?? [], 'quantity', 'addon_id'), $item['quantity'], $time);
            } catch (ProductUnavailable|InvalidSelection $e) {
                throw new ($e::class)($e->getMessage(), $i);
            }
            $bps = $product['tax_bps'] ?? $settings['default_tax_bps'];
            $lines[] = [...$line, 'quantity' => $item['quantity'], 'notes' => $item['notes'] ?? null, 'tax_bps' => $bps,
                'tax' => Pricing::tax($line['line_total'], $bps, (bool) $settings['prices_include_tax'])];
        }

        return $lines;
    }

    /**
     * Stores an order, accepted at once if the restaurant says so. Call inside a transaction.
     *
     * @param  list<array<string, mixed>>  $lines  as price() returns them
     */
    public function place(int $sessionId, int $participantId, ?string $notes, array $lines): stdClass
    {
        $settings = $this->projection->settings();
        $status = $settings['auto_accept_orders'] ? OrderStatus::Accepted : OrderStatus::Pending;
        $acceptedAt = $status === OrderStatus::Accepted ? now() : null;
        $gross = array_sum(array_column($lines, 'line_total'));
        $tax = array_sum(array_column($lines, 'tax'));
        $subtotal = $settings['prices_include_tax'] ? $gross - $tax : $gross;

        $id = DB::table('orders')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'table_session_id' => $sessionId, 'participant_id' => $participantId,
            'status' => $status->value, 'notes' => $notes, 'currency' => $settings['currency'],
            'subtotal' => $subtotal, 'tax_total' => $tax, 'total' => $subtotal + $tax,
            'accepted_at' => $acceptedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $line) {
            $addons = $line['addons'];
            unset($line['addons'], $line['tax']);
            $itemId = DB::table('order_items')->insertGetId([
                ...$line, 'public_id' => strtolower((string) Str::ulid()), 'order_id' => $id, 'status' => 'queued',
                'queued_at' => $acceptedAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('order_item_addons')->insert(array_map(fn (array $a) => [...$a, 'order_item_id' => $itemId], $addons));
        }
        $this->record($id, null, $status, participantId: $participantId);

        return DB::table('orders')->find($id);
    }

    /**
     * One conditional update, so two people acting at once cannot both succeed. Returns false if the order was not
     * in a state that allows it.
     */
    public function transition(stdClass $order, OrderStatus $to, string $userId, ?string $reason): bool
    {
        return DB::transaction(function () use ($order, $to, $userId, $reason) {
            $current = DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
            $from = OrderStatus::from($current->status);
            if (! in_array($from, $to->sources(), true)) {
                return false;
            }
            $changes = match ($to) {
                OrderStatus::Accepted => ['accepted_at' => now(), 'accepted_by' => $userId],
                OrderStatus::Rejected => ['rejected_at' => now(), 'rejected_by' => $userId, 'reject_reason' => $reason],
                OrderStatus::Cancelled => ['cancelled_at' => now(), 'cancelled_by' => $userId, 'cancel_reason' => $reason],
                OrderStatus::Pending => [],
            };
            DB::table('orders')->where('id', $order->id)
                ->update([...$changes, 'status' => $to->value, 'version' => $current->version + 1, 'updated_at' => now()]);

            $items = DB::table('order_items')->where('order_id', $order->id);
            if ($to === OrderStatus::Accepted) {
                $items->update(['queued_at' => now(), 'updated_at' => now()]);
            } elseif ($to === OrderStatus::Cancelled) {
                $items->whereIn('status', ['queued', 'preparing'])->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
            }
            $this->record($order->id, $from, $to, userId: $userId, reason: $reason);

            return true;
        });
    }

    /**
     * @param  Collection<int, stdClass>  $orders
     * @return list<array<string, mixed>>
     */
    public function present(Collection $orders): array
    {
        $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->orderBy('id')->get();
        $addons = DB::table('order_item_addons')->whereIn('order_item_id', $items->pluck('id'))->orderBy('id')->get()->groupBy('order_item_id');
        $items = $items->groupBy('order_id');
        $sessions = DB::table('table_sessions')->whereIn('id', $orders->pluck('table_session_id'))->get()->keyBy('id');
        $participants = DB::table('table_session_participants')->whereIn('id', $orders->pluck('participant_id'))->get()->keyBy('id');
        $tables = $this->projection->all('table');

        return $orders->map(fn (stdClass $o) => [
            'id' => $o->public_id, 'status' => $o->status, 'notes' => $o->notes, 'currency' => $o->currency,
            'subtotal' => (int) $o->subtotal, 'tax_total' => (int) $o->tax_total, 'discount_total' => (int) $o->discount_total, 'total' => (int) $o->total,
            'reason' => $o->cancel_reason ?? $o->reject_reason, 'version' => $o->version, 'created_at' => $o->created_at,
            'session_id' => $sessions[$o->table_session_id]->public_id,
            'table_label' => $tables[$sessions[$o->table_session_id]->table_id]['label'] ?? null,
            'participant_id' => $participants[$o->participant_id]->public_id ?? null,
            'participant_name' => $participants[$o->participant_id]->display_name ?? null,
            'items' => ($items[$o->id] ?? collect())->map(fn (stdClass $i) => [
                'id' => $i->public_id, 'product_id' => $i->product_id, 'name' => $i->name_snapshot, 'variant_name' => $i->variant_name_snapshot,
                'quantity' => $i->quantity, 'unit_price' => (int) $i->unit_price, 'line_total' => (int) $i->line_total,
                'destination' => $i->destination, 'status' => $i->status, 'notes' => $i->notes,
                'addons' => ($addons[$i->id] ?? collect())->map(fn (stdClass $a) => ['name' => $a->name_snapshot, 'unit_price' => (int) $a->unit_price, 'quantity' => $a->quantity])->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }

    private function record(int $orderId, ?OrderStatus $from, OrderStatus $to, ?string $userId = null, ?int $participantId = null, ?string $reason = null): void
    {
        DB::table('order_status_history')->insert([
            'order_id' => $orderId, 'from_status' => $from?->value, 'to_status' => $to->value,
            'actor_user_id' => $userId, 'actor_participant_id' => $participantId, 'reason' => $reason, 'created_at' => now(),
        ]);
    }
}
