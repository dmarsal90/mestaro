<script setup lang="ts">
// Status is always text (and optionally an icon from the slot), never only colour.
defineProps<{ tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info' }>()
const tones = { neutral: 'text-muted', success: 'text-success', warning: 'text-warning', danger: 'text-danger', info: 'text-info' }
</script>

<template>
  <span class="inline-flex items-center gap-1 rounded-full border border-current px-2 py-0.5 text-xs font-medium" :class="tones[tone ?? 'neutral']"><slot /></span>
</template>
