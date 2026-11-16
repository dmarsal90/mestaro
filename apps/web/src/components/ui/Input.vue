<script setup lang="ts">
defineOptions({ inheritAttrs: false })
const model = defineModel<string>({ default: '' })
defineProps<{ invalid?: boolean }>()
</script>

<template>
  <input
    v-model="model"
    :aria-invalid="invalid || undefined"
    v-bind="$attrs"
    class="min-h-10 w-full rounded-md border bg-surface px-3 text-text placeholder:text-muted hover:border-text disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-danger"
    :class="invalid ? 'border-danger' : 'border-control'"
  />
</template>
