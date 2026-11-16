import { reactive } from 'vue'

export type Toast = { id: number; tone: 'success' | 'info' | 'danger'; message: string }

const toasts = reactive<Toast[]>([])
let next = 1

/** Toasts never carry the only copy of an error: errors stay until closed, the rest leave after a while. */
export function useToasts() {
  function dismiss(id: number) {
    const i = toasts.findIndex((t) => t.id === id)
    if (i >= 0) toasts.splice(i, 1)
  }
  function push(tone: Toast['tone'], message: string, { timeout = 6000 }: { timeout?: number } = {}) {
    const id = next++
    toasts.push({ id, tone, message })
    if (tone !== 'danger' && timeout > 0) setTimeout(() => dismiss(id), timeout)
    return id
  }
  return { toasts, push, dismiss }
}
