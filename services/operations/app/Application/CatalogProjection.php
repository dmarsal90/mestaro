<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;

/** Reads the restaurant's copy of catalog's data and platform's settings. Needs the tenant context. */
final class CatalogProjection
{
    /** Until platform announces them: the same values platform uses for a restaurant that never changed them. */
    private const DEFAULT_SETTINGS = [
        'require_waiter_approval_for_session' => true, 'join_code_reuse_cooldown_minutes' => 15, 'auto_accept_orders' => false,
        'currency' => 'EUR', 'timezone' => 'Europe/Madrid', 'prices_include_tax' => true, 'default_tax_bps' => 1000,
    ];

    /** @return array<string, mixed>|null */
    public function find(string $kind, string $id): ?array
    {
        $data = DB::table('catalog_projection')->where('kind', $kind)->where('id', $id)->value('data');

        return $data === null ? null : json_decode($data, true);
    }

    /** @return array<string, array<string, mixed>> every state of one kind, by id */
    public function all(string $kind): array
    {
        return DB::table('catalog_projection')->where('kind', $kind)->pluck('data', 'id')
            ->map(fn (string $data) => json_decode($data, true))->all();
    }

    /**
     * A table customers may use: it and its zone are known and active.
     *
     * @return array<string, mixed>|null
     */
    public function usableTable(string $id): ?array
    {
        $table = $this->find('table', $id);
        if (! ($table['is_active'] ?? false)) {
            return null;
        }

        return ($this->find('zone', $table['zone_id'])['is_active'] ?? false) ? $table : null;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $data = DB::table('catalog_projection')->where('kind', 'settings')->value('data');

        return [...self::DEFAULT_SETTINGS, ...($data === null ? [] : json_decode($data, true))];
    }
}
