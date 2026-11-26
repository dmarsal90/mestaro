import type { Product } from '../components/menu/types'

export type MenuProduct = Pick<Product, 'id' | 'name' | 'description' | 'image_url' | 'price' | 'allergens' | 'ingredients' | 'variants' | 'addons'> & { orderable: boolean }
export type Menu = {
  currency: string; prices_include_tax: boolean
  categories: Array<{ id: string; name: string; description: string | null; products: MenuProduct[] }>
}
export type DinerSession = { id: string; status: 'pending_approval' | 'open' | 'bill_requested'; table_label: string | null; join_code: string | null }
