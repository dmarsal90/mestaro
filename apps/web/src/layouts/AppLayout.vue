<script setup lang="ts">
import { ChevronDown, Monitor, Moon, Sun } from 'lucide-vue-next'
import { computed } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import Dropdown from '../components/ui/Dropdown.vue'
import Logo from '../components/ui/Logo.vue'
import ToastViewport from '../components/ui/ToastViewport.vue'
import { useTheme } from '../composables/useTheme'
import { routes } from '../router'
import { useSession } from '../stores/session'

const session = useSession()
const router = useRouter()
const { choice, set } = useTheme()

// Navigation lists the routes that declare a label and that this user may open.
const links = computed(() => (routes.find((r) => r.path === '/')?.children ?? [])
  .filter((r) => r.meta?.nav && (!r.meta.permission || session.can(r.meta.permission)))
  .map((r) => ({ to: { name: r.name as string }, label: r.meta!.nav as string })))

const themes = [
  { id: 'system', label: 'Tema: sistema', icon: Monitor },
  { id: 'light', label: 'Tema: claro', icon: Sun },
  { id: 'dark', label: 'Tema: oscuro', icon: Moon },
] as const
const menu = computed(() => [
  ...themes.map((t) => ({ label: choice.value === t.id ? `${t.label} ✓` : t.label, onSelect: () => set(t.id) })),
  { label: 'Cerrar sesión', danger: true, onSelect: async () => { await session.logout(); await router.push({ name: 'login' }) } },
])
</script>

<template>
  <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[var(--z-toast)] focus:rounded-md focus:bg-accent focus:px-4 focus:py-2 focus:text-on-accent">Saltar al contenido</a>
  <header class="sticky top-0 z-[var(--z-sticky)] border-b border-border bg-surface">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 px-4 py-2">
      <RouterLink to="/" class="flex items-center gap-2 rounded-md" aria-label="Mestaro, inicio"><Logo /><span class="font-semibold text-text max-sm:sr-only">Mestaro</span></RouterLink>
      <nav aria-label="Principal" class="flex flex-1 gap-1 max-sm:order-last max-sm:basis-full max-sm:flex-wrap">
        <RouterLink v-for="l in links" :key="l.label" :to="l.to" class="grid min-h-10 place-items-center rounded-md px-3 text-muted hover:text-text" active-class="text-text underline decoration-brand decoration-2 underline-offset-8" exact-active-class="text-text underline decoration-brand decoration-2 underline-offset-8">{{ l.label }}</RouterLink>
      </nav>
      <div class="max-sm:ml-auto"><Dropdown :items="menu">
        <button type="button" class="inline-flex min-h-10 items-center gap-1 rounded-md px-3 text-text hover:bg-border" :aria-label="`Cuenta de ${session.me?.name}`">
          <span class="max-sm:sr-only">{{ session.me?.name }}</span><ChevronDown class="size-4" aria-hidden="true" />
        </button>
      </Dropdown></div>
    </div>
  </header>
  <main id="main" class="mx-auto max-w-6xl p-4" tabindex="-1"><RouterView /></main>
  <ToastViewport />
</template>
