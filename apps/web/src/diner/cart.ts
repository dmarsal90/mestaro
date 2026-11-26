import { computed, ref, watch } from 'vue'
import type { MenuProduct } from './types'

export type Line = { product_id: string; variant_id: string | null; addons: Array<{ addon_id: string; quantity: number }>; quantity: number; notes: string }

export const MAX_LINES = 50

/** What one line would cost by the menu on screen. Only a hint: the server prices the order. */
export function provisionalPrice(product: MenuProduct, line: Line): number {
  const variant = product.variants.find((v) => v.id === line.variant_id)
  const extras = line.addons.reduce((sum, a) => sum + (product.addons.find((x) => x.id === a.addon_id)?.price ?? 0) * a.quantity, 0)
  return (product.price + (variant?.price_delta ?? 0) + extras) * line.quantity
}

// `crypto.randomUUID` needs https; this also works on a restaurant's plain local network.
const newKey = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('')

/**
 * The order being prepared, kept in the browser so a reload or a lost connection does not lose it. Its idempotency
 * key lasts while the lines do not change: sending the same cart again cannot order twice.
 */
export function useCart(storageKey: string) {
  const read = (): { lines: Line[]; key: string } => {
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) ?? '')
      if (Array.isArray(saved.lines) && typeof saved.key === 'string') return saved
    } catch { /* nothing usable stored */ }
    return { lines: [], key: newKey() }
  }
  const saved = read()
  const lines = ref(saved.lines)
  const key = ref(saved.key)

  function save() {
    try { localStorage.setItem(storageKey, JSON.stringify({ lines: lines.value, key: key.value })) } catch { /* the cart then lasts until reload */ }
  }
  watch(lines, () => { key.value = newKey(); save() }, { deep: true })

  const body = computed(() => ({
    items: lines.value.map((l) => ({
      product_id: l.product_id, variant_id: l.variant_id, quantity: l.quantity, addons: l.addons, ...(l.notes ? { notes: l.notes } : {}),
    })),
  }))

  return {
    lines, key, body,
    add: (line: Line) => { lines.value.push(line) },
    remove: (index: number) => { lines.value.splice(index, 1) },
    /** After sending: whatever was added meanwhile stays. */
    dropSent: (count: number) => { lines.value.splice(0, count) },
  }
}
