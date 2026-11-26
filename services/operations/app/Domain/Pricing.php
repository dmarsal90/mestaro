<?php

namespace App\Domain;

/**
 * What an order line costs, from the catalog's data and never from what the diner sends. Money is integer minor
 * units. Products arrive as `ProductChanged` announces them (docs/events.md).
 */
final class Pricing
{
    /** Whether `H:i` falls in the product's hours; they may cross midnight and either end may be open. */
    public static function inHours(?string $from, ?string $until, string $time): bool
    {
        if ($from !== null && $until !== null && $from > $until) {
            return $time >= $from || $time < $until;
        }

        return ($from === null || $time >= $from) && ($until === null || $time < $until);
    }

    /** @param array<string, mixed>|null $product */
    public static function orderable(?array $product, string $time): bool
    {
        return $product !== null && ! ($product['deleted'] ?? false) && $product['is_available'] && ! $product['is_sold_out']
            && self::inHours($product['available_from'], $product['available_until'], $time);
    }

    /**
     * @param  array<string, mixed>|null  $product
     * @param  array<string, int>  $addons  quantity per add-on id, for each unit of the product
     * @return array{product_id: string, name_snapshot: string, variant_id: ?string, variant_name_snapshot: ?string, unit_price: int, line_total: int, destination: string, addons: list<array{addon_id: string, name_snapshot: string, unit_price: int, quantity: int}>}
     */
    public static function line(?array $product, ?string $variantId, array $addons, int $quantity, string $time): array
    {
        if (! self::orderable($product, $time)) {
            throw new ProductUnavailable('This product is not available now.');
        }
        $variant = self::variant($product['variants'], $variantId);
        $unit = $product['price'] + ($variant['price_delta'] ?? 0);

        $chosen = [];
        $known = array_column($product['addons'], null, 'id');
        foreach ($addons as $id => $count) {
            $addon = $known[$id] ?? throw new ProductUnavailable('An extra is no longer offered.');
            if (! $addon['is_available']) {
                throw new ProductUnavailable("{$addon['name']} is not available now.");
            }
            if ($count > $addon['max_quantity']) {
                throw new InvalidSelection("At most {$addon['max_quantity']} of {$addon['name']}.");
            }
            $chosen[] = ['addon_id' => (string) $id, 'name_snapshot' => $addon['name'], 'unit_price' => $addon['price'], 'quantity' => $count];
        }
        $extras = array_sum(array_map(fn (array $a) => $a['unit_price'] * $a['quantity'], $chosen));

        return [
            'product_id' => $product['id'], 'name_snapshot' => $product['name'],
            'variant_id' => $variant['id'] ?? null, 'variant_name_snapshot' => $variant['name'] ?? null,
            'unit_price' => $unit, 'line_total' => ($unit + $extras) * $quantity,
            'destination' => $product['destination'], 'addons' => $chosen,
        ];
    }

    /** Tax of one line, rounded half up: contained in the amount, or to be added on top of it. */
    public static function tax(int $amount, int $bps, bool $included): int
    {
        $divisor = $included ? 10000 + $bps : 10000;

        return intdiv(2 * $amount * $bps + $divisor, 2 * $divisor);
    }

    /**
     * The chosen variant, or the default one when none was chosen.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, mixed>|null
     */
    private static function variant(array $variants, ?string $id): ?array
    {
        if ($variants === []) {
            return $id === null ? null : throw new InvalidSelection('This product has no variants.');
        }
        foreach ($variants as $variant) {
            if ($id === null ? $variant['is_default'] : $variant['id'] === $id) {
                return $variant['is_available'] ? $variant : throw new ProductUnavailable("{$variant['name']} is not available now.");
            }
        }

        throw $id === null ? new InvalidSelection('Choose a variant.') : new ProductUnavailable('That variant is no longer offered.');
    }
}
