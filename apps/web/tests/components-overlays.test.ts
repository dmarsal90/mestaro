import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h, nextTick } from 'vue'
import ConfirmDialog from '../src/components/ui/ConfirmDialog.vue'
import DataTable from '../src/components/ui/DataTable.vue'
import DatePicker from '../src/components/ui/DatePicker.vue'
import Dialog from '../src/components/ui/Dialog.vue'
import Dropdown from '../src/components/ui/Dropdown.vue'
import Tabs from '../src/components/ui/Tabs.vue'
import ToastViewport from '../src/components/ui/ToastViewport.vue'
import Tooltip from '../src/components/ui/Tooltip.vue'
import { useToasts } from '../src/composables/useToasts'
import { expectAccessible } from './a11y'

const mounted = (c: unknown, options: Record<string, unknown> = {}) => mount(c as never, { attachTo: document.body, ...options })
afterEach(() => { document.body.innerHTML = '' })

describe('Dialog and Drawer', () => {
  it('opens as a named dialog with a description and closes on Escape', async () => {
    const w = mounted(Dialog, { props: { open: true, title: 'Nueva zona', description: 'Agrupa mesas.' }, slots: { default: '<p>Formulario</p>' } })
    await flushPromises()
    const dialog = document.body.querySelector('[role=dialog]')!
    expect(dialog.getAttribute('aria-labelledby')).toBeTruthy()
    expect(document.getElementById(dialog.getAttribute('aria-labelledby')!)?.textContent).toBe('Nueva zona')
    expect(document.getElementById(dialog.getAttribute('aria-describedby')!)?.textContent).toBe('Agrupa mesas.')
    await expectAccessible(dialog)
    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()
    expect(w.emitted('update:open')?.[0]).toEqual([false])
  })
  it('has a close button with a name, and a drawer variant docked to a side', async () => {
    mounted(Dialog, { props: { open: true, title: 'Filtros', side: 'right' } })
    await flushPromises()
    const dialog = document.body.querySelector('[role=dialog]')!
    expect(dialog.querySelector('[aria-label=Cerrar]')).not.toBeNull()
    expect(dialog.className).toContain('right-0')
    await expectAccessible(dialog)
  })
  it('asks for explicit confirmation of a destructive action', async () => {
    const w = mounted(ConfirmDialog, { props: { open: true, title: '¿Suspender?', confirmLabel: 'Suspender' } })
    await flushPromises()
    const buttons = [...document.body.querySelectorAll('[role=dialog] button')] as HTMLButtonElement[]
    const confirm = buttons.find((b) => b.textContent?.trim() === 'Suspender')!
    expect(document.activeElement).not.toBe(confirm) // the destructive button is never the default focus
    confirm.click()
    expect(w.emitted('confirm')).toHaveLength(1)
    buttons.find((b) => b.textContent?.trim() === 'Cancelar')!.click()
    expect(w.emitted('update:open')?.[0]).toEqual([false])
  })
})

describe('Dropdown, Tabs, Tooltip', () => {
  it('Dropdown trigger announces a menu and opens from the keyboard', async () => {
    const onSelect = vi.fn()
    const w = mounted(Dropdown, { props: { items: [{ label: 'Editar', onSelect }, { label: 'Borrar', danger: true, onSelect }] }, slots: { default: () => h('button', { type: 'button' }, 'Acciones') } })
    const trigger = w.find('button')
    expect(trigger.attributes('aria-haspopup')).toBe('menu')
    await trigger.trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect([...document.body.querySelectorAll('[role=menuitem]')].map((i) => i.textContent)).toEqual(['Editar', 'Borrar'])
  })
  it('Tabs switch panels and expose tablist semantics', async () => {
    const w = mounted(Tabs, { props: { label: 'Menú', modelValue: 'a', tabs: [{ value: 'a', label: 'Platos' }, { value: 'b', label: 'Bebidas' }] }, slots: { a: 'Platos aquí', b: 'Bebidas aquí' } })
    expect(w.find('[role=tablist]').attributes('aria-label')).toBe('Menú')
    expect(w.findAll('[role=tab]')[0].attributes('aria-selected')).toBe('true')
    await w.findAll('[role=tab]')[1].trigger('mousedown', { button: 0 })
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['b'])
    await expectAccessible(w.element)
  })
  it('Tooltip keeps the trigger as the real control', async () => {
    const w = mounted(Tooltip, { props: { text: 'Copiar' }, slots: { default: () => h('button', { type: 'button', 'aria-label': 'Copiar enlace' }, '⧉') } })
    expect(w.find('button').attributes('aria-label')).toBe('Copiar enlace')
    await expectAccessible(w.element)
  })
})

describe('DataTable', () => {
  const columns = [{ key: 'label', label: 'Mesa', sortable: true }, { key: 'capacity', label: 'Plazas', sortable: true }] as const
  const rows = [{ label: 'M10', capacity: 4 }, { label: 'M2', capacity: 6 }, { label: 'M1', capacity: 2 }]
  const table = () => mounted(DataTable, { props: { caption: 'Mesas', columns, rows } })

  it('has a caption, column headers and one row per record', async () => {
    const w = table()
    expect(w.find('caption').text()).toBe('Mesas')
    expect(w.findAll('tbody tr')).toHaveLength(3)
    expect(w.findAll('td')[0].attributes('data-label')).toBe('Mesa') // the mobile list shows it
    await expectAccessible(w.element)
  })
  it('sorts naturally and tells assistive technology which way', async () => {
    const w = table()
    const first = () => w.findAll('tbody tr').map((r) => r.findAll('td')[0].text())
    expect(w.findAll('th')[0].attributes('aria-sort')).toBe('none')
    await w.findAll('th button')[0].trigger('click')
    expect(first()).toEqual(['M1', 'M2', 'M10']) // numeric-aware: M2 before M10
    expect(w.findAll('th')[0].attributes('aria-sort')).toBe('ascending')
    await w.findAll('th button')[0].trigger('click')
    expect(first()).toEqual(['M10', 'M2', 'M1'])
    expect(w.findAll('th')[0].attributes('aria-sort')).toBe('descending')
    expect(w.findAll('th')[1].attributes('aria-sort')).toBe('none')
  })
})

describe('DatePicker', () => {
  it('is a native date control with its value bound', async () => {
    const w = mounted(DatePicker, { props: { modelValue: '' } })
    await w.find('input').setValue('2026-11-16')
    expect(w.find('input').attributes('type')).toBe('date')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['2026-11-16'])
  })
})

describe('Toasts', () => {
  beforeEach(() => { vi.useFakeTimers(); useToasts().toasts.splice(0) })
  afterEach(() => vi.useRealTimers())

  it('announces good news politely and errors assertively', async () => {
    const { push } = useToasts()
    const w = mounted(ToastViewport)
    push('success', 'Guardado')
    push('danger', 'No se pudo guardar')
    await nextTick()
    expect(w.find('[role=status]').text()).toContain('Guardado')
    expect(w.find('[role=alert]').text()).toContain('No se pudo guardar')
    expect(w.find('[role=alert]').text()).toContain('Error:') // not only colour or icon
    vi.useRealTimers() // axe schedules with timers
    await expectAccessible(w.element)
  })
  it('lets success leave by itself but keeps an error until it is closed', async () => {
    const { push, toasts, dismiss } = useToasts()
    push('success', 'Guardado')
    const error = push('danger', 'Fallo')
    vi.advanceTimersByTime(7000)
    expect(toasts.map((t) => t.message)).toEqual(['Fallo'])
    dismiss(error)
    expect(toasts).toHaveLength(0)
  })
})
