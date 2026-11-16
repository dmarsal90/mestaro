<script setup lang="ts">
// Text, probable cause and one action (UI_UX_BRIEF §7).
defineProps<{ title: string; cause?: string }>()
</script>

<template>
  <div role="alert" class="flex flex-col items-center gap-2 p-8 text-center">
    <h2 class="text-lg font-semibold text-danger">{{ title }}</h2>
    <p v-if="cause" class="max-w-prose text-muted">{{ cause }}</p>
    <div class="mt-2"><slot name="action" /></div>
  </div>
</template>
