import { ref } from 'vue'

export type ThemeChoice = 'system' | 'light' | 'dark'
const KEY = 'mestaro-theme'
const read = (): ThemeChoice => { try { const v = localStorage.getItem(KEY); return v === 'light' || v === 'dark' ? v : 'system' } catch { return 'system' } }

const choice = ref<ThemeChoice>(read())

/** "system" removes data-theme so tokens.css follows prefers-color-scheme; the inline script in index.html applies the saved one before first paint. */
function apply(c: ThemeChoice) {
  if (c === 'system') delete document.documentElement.dataset.theme
  else document.documentElement.dataset.theme = c
}

export function useTheme() {
  function set(c: ThemeChoice) {
    choice.value = c
    apply(c)
    try { c === 'system' ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, c) } catch { /* the choice then lasts until reload */ }
  }
  return { choice, set }
}
