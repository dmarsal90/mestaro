<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next'

withDefaults(defineProps<{ label?: string }>(), { label: 'Cargando…' })
</script>

<template>
  <div role="status" class="flex items-center justify-center gap-2 p-8 text-muted">
    <Loader2 class="size-5 animate-spin motion-reduce:animate-none" aria-hidden="true" />
    <span>{{ label }}</span>
  </div>
</template>
