<?php

namespace App\Infrastructure\Tenancy;

use Illuminate\Support\Facades\DB;
use Mestaro\Kernel\Messaging\Outbox;

/** Per-restaurant configuration. A restaurant without a row reads the defaults; the first update creates it. */
final class RestaurantSettings
{
    public const DEFAULTS = [
        'currency' => 'EUR',
        'timezone' => 'Europe/Madrid',
        'locale' => 'es',
        'opening_hours' => null,
        'auto_accept_orders' => false,
        'require_waiter_approval_for_session' => true,
        'prices_include_tax' => true,
        'default_tax_bps' => 1000,
        'join_code_reuse_cooldown_minutes' => 15,
        'order_alert_threshold_seconds' => 300,
        'preferences' => null,
    ];

    private const JSON = ['opening_hours', 'preferences'];

    public function __construct(private readonly Outbox $outbox) {}

    /** @return array<string, mixed> */
    public function get(string $restaurantId): array
    {
        $row = DB::table('restaurant_settings')->where('restaurant_id', $restaurantId)->first();

        return $row === null ? self::DEFAULTS : $this->decode((array) $row);
    }

    /**
     * Stores the changes and announces the resulting settings to the other services, in one transaction.
     *
     * @param  array<string, mixed>  $changes  Already validated; only known keys are stored.
     * @return array<string, mixed>
     */
    public function update(string $restaurantId, array $changes): array
    {
        return DB::transaction(function () use ($restaurantId, $changes) {
            // Lock the row so two concurrent partial updates do not overwrite each other's fields.
            DB::table('restaurant_settings')->where('restaurant_id', $restaurantId)->lockForUpdate()->first();
            $merged = [...$this->get($restaurantId), ...array_intersect_key($changes, self::DEFAULTS)];
            $row = $merged;
            foreach (self::JSON as $key) {
                $row[$key] = $merged[$key] === null ? null : json_encode($merged[$key], JSON_THROW_ON_ERROR);
            }
            $now = now();
            DB::table('restaurant_settings')->upsert(
                [['restaurant_id' => $restaurantId, ...$row, 'created_at' => $now, 'updated_at' => $now]],
                ['restaurant_id'],
                [...array_keys(self::DEFAULTS), 'updated_at'],
            );

            $this->outbox->record('SettingsChanged', ['tenant_id' => $restaurantId, 'settings' => ['id' => $restaurantId, ...$merged]]);

            return $merged;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        $settings = array_intersect_key($row, self::DEFAULTS);
        foreach (self::JSON as $key) {
            $settings[$key] = $settings[$key] === null ? null : json_decode((string) $settings[$key], true, flags: JSON_THROW_ON_ERROR);
        }

        return $settings;
    }
}
