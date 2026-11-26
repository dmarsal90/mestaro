<?php

namespace App\Application;

use App\Domain\Pricing;

/** The menu as a diner sees it, from the restaurant's projection. Needs the tenant context. */
final readonly class Menu
{
    public function __construct(private CatalogProjection $projection) {}

    /** The time of day products' hours are measured against. */
    public function localTime(): string
    {
        return now($this->projection->settings()['timezone'])->format('H:i');
    }

    /**
     * Withdrawn products are left out; sold-out or out-of-hours ones stay, marked as not orderable.
     *
     * @return array<string, mixed>
     */
    public function forDiner(): array
    {
        $settings = $this->projection->settings();
        $time = $this->localTime();
        $order = fn (array $a, array $b) => [$a['sort_order'], $a['name']] <=> [$b['sort_order'], $b['name']];

        $categories = array_filter($this->projection->all('category'), fn (array $c) => $c['is_active']);
        $products = array_filter($this->projection->all('product'), fn (array $p) => ! ($p['deleted'] ?? false) && $p['is_available']);
        uasort($categories, $order);
        uasort($products, $order);

        $menu = [];
        foreach ($categories as $category) {
            $items = [];
            foreach ($products as $p) {
                if ($p['category_id'] === $category['id']) {
                    $items[] = [
                        ...array_intersect_key($p, array_flip(['id', 'name', 'description', 'image_url', 'price', 'allergens', 'ingredients', 'variants', 'addons'])),
                        'orderable' => Pricing::orderable($p, $time),
                    ];
                }
            }
            if ($items !== []) {
                $menu[] = ['id' => $category['id'], 'name' => $category['name'], 'description' => $category['description'] ?? null, 'products' => $items];
            }
        }

        return ['currency' => $settings['currency'], 'prices_include_tax' => $settings['prices_include_tax'], 'categories' => $menu];
    }
}
