<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next'

const props = withDefaults(defineProps<{
  variant?: 'primary' | 'secondary' | 'tertiary' | 'danger'
  size?: 'sm' | 'md' | 'lg' // lg = 44 px touch target (kitchen, POS, QR menu)
  loading?: boolean
  type?: 'button' | 'submit' | 'reset'
}>(), { variant: 'secondary', size: 'md', type: 'button' })

const variants = {
  // One primary per view: Modena fill, Carbon label (9.46:1).
  primary: 'bg-accent text-on-accent hover:bg-accent-hover active:brightness-95',
  secondary: 'border border-control bg-transparent text-text hover:bg-border active:brightness-95',
  tertiary: 'bg-transparent text-text underline-offset-4 hover:underline',
  danger: 'bg-danger text-bg hover:brightness-110 active:brightness-95',
}
const sizes = { sm: 'min-h-8 px-3 text-sm', md: 'min-h-10 px-4', lg: 'min-h-[var(--touch-target)] min-w-[var(--touch-target)] px-5 text-base' }
</script>

<template>
  <button
    :type="props.type"
    :disabled="props.loading || undefined"
    :aria-busy="props.loading || undefined"
    class="inline-flex items-center justify-center gap-2 rounded-md font-medium transition-colors duration-[var(--duration-fast)] ease-out disabled:cursor-not-allowed disabled:opacity-50"
    :class="[variants[props.variant], sizes[props.size]]"
  >
    <Loader2 v-if="props.loading" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
    <slot />
  </button>
</template>
