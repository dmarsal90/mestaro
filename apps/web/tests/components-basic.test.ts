import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import Badge from '../src/components/ui/Badge.vue'
import Button from '../src/components/ui/Button.vue'
import Card from '../src/components/ui/Card.vue'
import Checkbox from '../src/components/ui/Checkbox.vue'
import EmptyState from '../src/components/ui/EmptyState.vue'
import ErrorState from '../src/components/ui/ErrorState.vue'
import FormField from '../src/components/ui/FormField.vue'
import Input from '../src/components/ui/Input.vue'
import LoadingState from '../src/components/ui/LoadingState.vue'
import Pagination from '../src/components/ui/Pagination.vue'
import RadioGroup from '../src/components/ui/RadioGroup.vue'
import Search from '../src/components/ui/Search.vue'
import Skeleton from '../src/components/ui/Skeleton.vue'
import Switch from '../src/components/ui/Switch.vue'
import { expectAccessible } from './a11y'

const mounted = (c: unknown, options: Record<string, unknown> = {}) => mount(c as never, { attachTo: document.body, ...options })

describe('Button', () => {
  it('is a real button, not a submit by default, and shows its variants', async () => {
    const w = mounted(Button, { slots: { default: 'Guardar' }, props: { variant: 'primary' } })
    expect(w.attributes('type')).toBe('button')
    expect(w.classes()).toContain('bg-accent')
    await expectAccessible(w.element)
  })
  it('blocks a second click while loading and says it is busy', async () => {
    const w = mounted(Button, { slots: { default: 'Guardar' }, props: { loading: true } })
    expect(w.attributes('disabled')).toBeDefined()
    expect(w.attributes('aria-busy')).toBe('true')
    await w.trigger('click')
    expect(w.emitted('click')).toBeUndefined()
  })
  it('offers a 44 px touch size', () => {
    expect(mounted(Button, { props: { size: 'lg' } }).classes().join(' ')).toContain('--touch-target')
  })
})

describe('FormField + Input', () => {
  const field = (props: Record<string, unknown>) =>
    mounted(FormField, { props: { label: 'Email', ...props }, slots: { default: (f: Record<string, unknown>) => h(Input, { ...f, modelValue: '' }) } })

  it('links label, hint and error to the control', async () => {
    const w = field({ hint: 'Tu correo de trabajo', error: 'Obligatorio', required: true })
    const input = w.find('input')
    expect(w.find('label').attributes('for')).toBe(input.attributes('id'))
    for (const id of input.attributes('aria-describedby')!.split(' ')) expect(w.find(`#${CSS.escape(id)}`).exists()).toBe(true)
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(w.find('[role=alert]').text()).toBe('Obligatorio')
    await expectAccessible(w.element)
  })
  it('has no invalid state without an error', () => {
    expect(field({}).find('input').attributes('aria-invalid')).toBeUndefined()
  })
})

describe('selection controls', () => {
  it('Checkbox toggles and is labelled', async () => {
    const w = mounted(Checkbox, { props: { label: 'Acepto', modelValue: false } })
    await w.find('button').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([true])
    await expectAccessible(w.element)
  })
  it('Switch toggles and is labelled', async () => {
    const w = mounted(Switch, { props: { label: 'Disponible', modelValue: false } })
    await w.find('button').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([true])
    await expectAccessible(w.element)
  })
  it('RadioGroup selects one option and is labelled', async () => {
    const w = mounted(RadioGroup, { props: { legend: 'Destino', modelValue: 'kitchen', options: [{ value: 'kitchen', label: 'Cocina' }, { value: 'bar', label: 'Barra' }] } })
    await w.findAll('button')[1].trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['bar'])
    await expectAccessible(w.element)
  })
})

describe('presentation', () => {
  it('Badge, Card, Skeleton and states are accessible', async () => {
    for (const w of [
      mounted(Badge, { props: { tone: 'danger' }, slots: { default: 'Fallido' } }),
      mounted(Card, { slots: { default: '<p>Contenido</p>' } }),
      mounted(Skeleton),
      mounted(LoadingState),
      mounted(EmptyState, { props: { title: 'Sin mesas', description: 'Crea la primera.' }, slots: { action: '<button>Crear</button>' } }),
      mounted(ErrorState, { props: { title: 'No se pudo cargar', cause: 'Sin conexión' }, slots: { action: '<button>Reintentar</button>' } }),
    ]) {
      await expectAccessible(w.element)
    }
  })
  it('Skeleton is hidden from assistive technology and still under reduced motion', () => {
    const w = mounted(Skeleton)
    expect(w.attributes('aria-hidden')).toBe('true')
    expect(w.classes()).toContain('motion-reduce:animate-none')
  })
  it('LoadingState announces itself as a status; ErrorState as an alert', () => {
    expect(mounted(LoadingState).attributes('role')).toBe('status')
    expect(mounted(ErrorState, { props: { title: 'x' } }).attributes('role')).toBe('alert')
  })
})

describe('Search', () => {
  it('is a labelled search landmark that clears itself', async () => {
    const w = mounted(Search, { props: { modelValue: 'pizza' } })
    expect(w.attributes('role')).toBe('search')
    await w.find('button').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([''])
    await expectAccessible(w.element)
  })
})

describe('Pagination', () => {
  it('moves between pages and labels its controls', async () => {
    const w = mounted(Pagination, { props: { total: 100, pageSize: 10, modelValue: 1 } })
    await w.find('[aria-label="Página siguiente"]').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual([2])
    await expectAccessible(w.element)
  })
})

describe('Select', () => {
  it('renders a labelled combobox showing the chosen option', async () => {
    const Select = (await import('../src/components/ui/Select.vue')).default
    const w = mounted(FormField, {
      props: { label: 'Zona' },
      slots: { default: (f: Record<string, unknown>) => h(Select, { ...f, modelValue: 'bar', options: [{ value: 'kitchen', label: 'Cocina' }, { value: 'bar', label: 'Barra' }] }) },
    })
    const trigger = w.find('[role=combobox]')
    expect(trigger.attributes('id')).toBe(w.find('label').attributes('for'))
    expect(trigger.text()).toContain('Barra')
    await expectAccessible(w.element)
  })
})
