import axe from 'axe-core'
import { expect } from 'vitest'

/** Fails on any axe violation. Colour contrast is off: jsdom has no layout, tokens.test.ts covers the colour pairs. */
export async function expectAccessible(element: Element) {
  const result = await axe.run(element, { rules: { 'color-contrast': { enabled: false }, region: { enabled: false } } })
  expect(result.violations.map((v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.html).join(' | ')})`)).toEqual([])
}
