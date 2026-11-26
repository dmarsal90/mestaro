import { onMounted, onUnmounted, ref, shallowRef } from 'vue'
import { explain } from '../api/errors'

/**
 * Data that others change, asked for again every few seconds while the page is visible (there is no real time yet).
 * A failure keeps what is on screen; an answer that arrives after a newer one is ignored.
 */
export function usePolling<T>(fetcher: () => Promise<T>, onError?: (e: unknown) => void, ms = 10_000) {
  const data = shallowRef<T>()
  const error = ref('')
  let latest = 0
  async function load() {
    const call = ++latest
    try {
      const result = await fetcher()
      if (call !== latest) return
      data.value = result
      error.value = ''
    } catch (e) {
      if (call !== latest) return
      error.value = explain(e)
      onError?.(e)
    }
  }
  const tick = () => { if (!document.hidden) void load() }
  let timer: ReturnType<typeof setInterval>
  onMounted(() => {
    void load()
    timer = setInterval(tick, ms)
    document.addEventListener('visibilitychange', tick)
  })
  onUnmounted(() => {
    clearInterval(timer)
    document.removeEventListener('visibilitychange', tick)
    latest++
  })
  return { data, error, load }
}
