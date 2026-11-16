<script setup lang="ts">
// The brand M from resources/brand/mestaro-symbol.svg. Colour is the --brand-mark role: Modena on dark, Carbon on light (never Modena as an icon on light).
defineProps<{ label?: string }>()
</script>

<template>
  <svg viewBox="0 0 1000 1000" class="size-8 text-brand" role="img" :aria-label="label ?? 'Mestaro'">
    <path d="M 90 170 L 330 260 L 500 390 L 670 260 L 910 170 L 910 720 L 770 835 L 770 405 L 500 600 L 230 405 L 230 835 L 90 720 Z" fill="currentColor" fill-rule="evenodd" />
  </svg>
</template>
