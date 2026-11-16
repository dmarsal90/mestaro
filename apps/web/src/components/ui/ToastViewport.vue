<script setup lang="ts">
import { AlertCircle, CheckCircle2, Info, X } from 'lucide-vue-next'
import { useToasts } from '../../composables/useToasts'

const { toasts, dismiss } = useToasts()
const tones = { success: { icon: CheckCircle2, text: 'text-success', label: 'Hecho' }, info: { icon: Info, text: 'text-info', label: 'Información' }, danger: { icon: AlertCircle, text: 'text-danger', label: 'Error' } }
</script>

<template>
  <!-- Two live regions, always present, so announcements are reliable: polite for good news, assertive for errors. -->
  <div class="pointer-events-none fixed right-4 bottom-4 z-[var(--z-toast)] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2">
    <div v-for="(group, role) in { status: toasts.filter((t) => t.tone !== 'danger'), alert: toasts.filter((t) => t.tone === 'danger') }" :key="role" :role="role" class="flex flex-col gap-2">
      <div v-for="t in group" :key="t.id" class="pointer-events-auto flex items-start gap-3 rounded-md border border-border bg-surface p-3 text-text shadow-overlay">
        <component :is="tones[t.tone].icon" class="mt-0.5 size-5 shrink-0" :class="tones[t.tone].text" aria-hidden="true" />
        <p class="flex-1"><span class="sr-only">{{ tones[t.tone].label }}: </span>{{ t.message }}</p>
        <button type="button" class="grid size-8 shrink-0 place-items-center rounded-sm text-muted hover:text-text" aria-label="Cerrar aviso" @click="dismiss(t.id)"><X class="size-4" aria-hidden="true" /></button>
      </div>
    </div>
  </div>
</template>
