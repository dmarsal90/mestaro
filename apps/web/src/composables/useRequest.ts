import { onMounted, ref, shallowRef } from 'vue'
import { ApiError } from '../api/client'
import { explain } from '../api/errors'
import { useToasts } from './useToasts'

/** Data of a page: loads on mount; a reload keeps what is on screen and reports a failure without discarding it. */
export function useLoad<T>(fetcher: () => Promise<T>) {
  const data = shallowRef<T>()
  const error = ref('')
  async function load() {
    error.value = ''
    try {
      data.value = await fetcher()
    } catch (e) {
      error.value = explain(e)
      if (data.value !== undefined) useToasts().push('danger', error.value)
    }
  }
  onMounted(load)
  return { data, error, load }
}

/** One write at a time (a double click must not create two records), with its message and the fields the server rejected. */
export function useAction() {
  const busy = ref(false)
  const error = ref('')
  const invalid = ref<Record<string, string>>({})
  function reset() {
    error.value = ''
    invalid.value = {}
  }
  async function run(action: () => Promise<unknown>, success?: string): Promise<boolean> {
    if (busy.value) return false
    busy.value = true
    reset()
    try {
      await action()
      if (success) useToasts().push('success', success)
      return true
    } catch (e) {
      error.value = explain(e)
      if (e instanceof ApiError && e.details) invalid.value = Object.fromEntries(Object.keys(e.details).map((k) => [k, 'Valor no válido.']))
      return false
    } finally {
      busy.value = false
    }
  }
  return { busy, error, invalid, run, reset }
}
