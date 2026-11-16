<script setup lang="ts">
import { computed, useId } from 'vue'

const props = defineProps<{ label: string; hint?: string; error?: string; required?: boolean }>()
const id = useId()
const hintId = `${id}-hint`
const errorId = `${id}-error`
// Handed to the control so label, hint and error are wired for assistive technology; the error is also text, never only colour.
const control = computed(() => ({
  id,
  'aria-invalid': props.error ? true : undefined,
  'aria-required': props.required || undefined,
  'aria-describedby': [props.hint ? hintId : null, props.error ? errorId : null].filter(Boolean).join(' ') || undefined,
}))
</script>

<template>
  <div class="flex flex-col gap-1">
    <label :for="id" class="text-sm font-medium text-text">
      {{ props.label }}<span v-if="props.required" aria-hidden="true"> *</span>
    </label>
    <slot v-bind="control" />
    <p v-if="props.hint" :id="hintId" class="text-sm text-muted">{{ props.hint }}</p>
    <p v-if="props.error" :id="errorId" class="text-sm text-danger" role="alert">{{ props.error }}</p>
  </div>
</template>
