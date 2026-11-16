<script setup lang="ts">
// shortcut: the browser's own date control (accessible, localised, native picker on phones) instead of a custom calendar;
// build one with Reka's DatePicker only if a design needs ranges or custom presets.
defineOptions({ inheritAttrs: false })
const model = defineModel<string>({ default: '' })
defineProps<{ invalid?: boolean }>()
</script>

<template>
  <input
    v-model="model"
    type="date"
    v-bind="$attrs"
    class="min-h-10 w-full rounded-md border bg-surface px-3 text-text hover:border-text disabled:cursor-not-allowed disabled:opacity-50"
    :class="invalid ? 'border-danger' : 'border-control'"
  />
</template>
