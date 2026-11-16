import { readFileSync } from 'node:fs'
import { resolve as resolvePath } from 'node:path'
import { describe, expect, it } from 'vitest'

const css = readFileSync(resolvePath(__dirname, '../src/styles/tokens.css'), 'utf8')

/** Declarations of the block that starts with `selector` (first match). */
function block(selector: string): Record<string, string> {
  const start = css.indexOf(selector)
  if (start < 0) throw new Error(`Missing block ${selector}`)
  const body = css.slice(css.indexOf('{', start) + 1, css.indexOf('\n}', start))
  return Object.fromEntries([...body.matchAll(/--([a-z0-9-]+):\s*([^;]+);/g)].map((m) => [m[1], m[2].trim()]))
}

const palette = block(':root {')
const modes = { dark: { ...palette }, light: { ...palette, ...block(":root[data-theme='light']") } }
// The system-preference block must define the same roles as the explicit light theme.
const systemLight = { ...palette, ...block(":root:not([data-theme='dark'])") }

/** A role resolved to a hex colour through var() references. */
function resolve(mode: Record<string, string>, name: string): string {
  let value = mode[name]
  while (value?.startsWith('var(')) value = mode[value.slice(6, -1)]
  if (!/^#[0-9a-f]{6}$/i.test(value ?? '')) throw new Error(`--${name} does not resolve to a hex colour (${value})`)
  return value
}

const luminance = (hex: string) => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
const contrast = (a: string, b: string) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

// [foreground role, background role, minimum]: 4.5 for text (WCAG 2.2 AA 1.4.3), 3 for UI components and focus (1.4.11)
const pairs: Record<keyof typeof modes, Array<[string, string, number, string]>> = {
  dark: [
    ['text', 'bg', 4.5, 'body on page'],
    ['text', 'surface', 4.5, 'body on card'],
    ['text-muted', 'surface', 4.5, 'secondary text on card'],
    ['text-muted', 'bg', 4.5, 'secondary text on page'],
    ['accent', 'bg', 4.5, 'accent text on page'],
    ['on-accent', 'accent', 4.5, 'button label'],
    ['on-accent', 'accent-hover', 4.5, 'button label on hover'],
    ['status-success', 'surface', 4.5, 'success on card'],
    ['status-warning', 'surface', 4.5, 'warning on card'],
    ['status-danger', 'surface', 4.5, 'danger on card'],
    ['status-info', 'surface', 4.5, 'info on card'],
    ['control-border', 'surface', 3, 'control border on card'],
    ['brand-mark', 'bg', 3, 'logo on page'],
    ['brand-mark', 'surface', 3, 'logo on card'],
    ['focus-ring', 'surface', 3, 'focus ring on card'],
    ['focus-ring', 'bg', 3, 'focus ring on page'],
  ],
  light: [
    ['text', 'bg', 4.5, 'body on page'],
    ['text', 'surface', 4.5, 'body on card'],
    ['text-muted', 'surface', 4.5, 'secondary text on card'],
    ['text-muted', 'bg', 4.5, 'secondary text on page'],
    ['on-accent', 'accent', 4.5, 'button label'],
    ['on-accent', 'accent-hover', 4.5, 'button label on hover'],
    ['status-success', 'surface', 4.5, 'success on card'],
    ['status-warning', 'surface', 4.5, 'warning on card'],
    ['status-danger', 'surface', 4.5, 'danger on card'],
    ['status-info', 'surface', 4.5, 'info on card'],
    ['control-border', 'surface', 3, 'control border on card'],
    ['brand-mark', 'bg', 3, 'logo on page'],
    ['brand-mark', 'surface', 3, 'logo on card'],
    ['focus-ring', 'surface', 3, 'focus ring on card'],
    ['focus-ring', 'bg', 3, 'focus ring on page'],
  ],
}

describe.each(Object.keys(pairs) as Array<keyof typeof pairs>)('%s mode', (mode) => {
  it.each(pairs[mode])('%s on %s reaches %s:1 (%s)', (fg, bg, min) => {
    expect(contrast(resolve(modes[mode], fg), resolve(modes[mode], bg))).toBeGreaterThanOrEqual(min)
  })
})

it('keeps the numbers the design brief promises for Titanium Deep', () => {
  const p = (name: string) => resolve(modes.light, name)
  expect(contrast(p('titanium-deep'), p('white'))).toBeCloseTo(7.44, 1)
  expect(contrast(p('titanium-deep'), p('titanium-light'))).toBeCloseTo(5.45, 1)
  expect(contrast(p('carbon'), p('white'))).toBeCloseTo(15.79, 1)
  expect(contrast(p('carbon'), p('titanium-light'))).toBeCloseTo(11.57, 1)
})

it('never uses Modena as text or icon colour on light surfaces', () => {
  expect(contrast(resolve(modes.light, 'modena'), resolve(modes.light, 'surface'))).toBeLessThan(3) // so it must stay a fill
  expect(modes.light['text']).not.toBe('var(--modena)')
})

it('gives the system-preference light mode the same roles as the explicit light theme', () => {
  const explicit = block(":root[data-theme='light']")
  for (const role of Object.keys(explicit).filter((k) => !['accent', 'accent-hover', 'on-accent', 'control-border'].includes(k))) {
    expect(systemLight[role], `--${role}`).toBe(explicit[role])
  }
})

it('has no hex colours outside the palette block', () => {
  const afterPalette = css.slice(css.indexOf('/* Roles, dark'))
  expect(afterPalette.match(/#[0-9a-f]{3,8}\b/gi) ?? []).toEqual([])
})
