export type Category = { id: string; name: string; description: string | null; sort_order: number; is_active: boolean }
export type Variant = { id: string; name: string; price_delta: number; is_default: boolean; is_available: boolean }
export type Addon = { id: string; name: string; price: number; max_quantity: number; is_available: boolean }
export type Product = {
  id: string; category_id: string; name: string; description: string | null; image_url: string | null
  price: number; tax_bps: number | null; destination: string; allergens: string[]; ingredients: string[]
  available_from: string | null; available_until: string | null; is_available: boolean; is_sold_out: boolean; sort_order: number
  variants: Variant[]; addons: Addon[]
}

// The 14 allergens of EU Regulation 1169/2011, with the keys the API accepts.
export const allergens: Record<string, string> = {
  gluten: 'Gluten', crustaceans: 'Crustáceos', eggs: 'Huevos', fish: 'Pescado', peanuts: 'Cacahuetes', soy: 'Soja', milk: 'Lácteos',
  nuts: 'Frutos de cáscara', celery: 'Apio', mustard: 'Mostaza', sesame: 'Sésamo', sulphites: 'Sulfitos', lupin: 'Altramuces', molluscs: 'Moluscos',
}
