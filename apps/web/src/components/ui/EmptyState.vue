<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <div class="flex flex-col items-center gap-2 p-8 text-center">
    <h2 class="text-lg font-semibold text-text">{{ title }}</h2>
    <p v-if="description" class="max-w-prose text-muted">{{ description }}</p>
    <div class="mt-2"><slot name="action" /></div>
  </div>
</template>
