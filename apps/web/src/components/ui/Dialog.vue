<script setup lang="ts">
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui'

// Focus is trapped, Esc closes and focus returns to the trigger (Reka). Used as a centred dialog or, with `side`, as a drawer.
const open = defineModel<boolean>('open', { default: false })
defineProps<{ title: string; description?: string; side?: 'left' | 'right' }>()
</script>

<template>
  <DialogRoot v-model:open="open">
    <slot name="trigger" />
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-[var(--z-modal)] bg-bg-deep/70" />
      <DialogContent
        class="fixed z-[var(--z-modal)] flex flex-col gap-4 border border-border bg-surface p-6 text-text shadow-overlay"
        :class="side
          ? ['inset-y-0 w-[min(24rem,100vw)]', side === 'right' ? 'right-0 border-r-0' : 'left-0 border-l-0']
          : 'top-1/2 left-1/2 max-h-[90vh] w-[min(32rem,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 overflow-auto rounded-lg'"
      >
        <div class="flex items-start justify-between gap-4">
          <DialogTitle class="text-lg font-semibold">{{ title }}</DialogTitle>
          <DialogClose class="grid size-10 shrink-0 place-items-center rounded-md text-muted hover:text-text" aria-label="Cerrar"><X class="size-5" aria-hidden="true" /></DialogClose>
        </div>
        <DialogDescription v-if="description" class="text-muted">{{ description }}</DialogDescription>
        <div class="min-h-0 flex-1 overflow-auto"><slot /></div>
        <div v-if="$slots.footer" class="flex justify-end gap-2"><slot name="footer" /></div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
