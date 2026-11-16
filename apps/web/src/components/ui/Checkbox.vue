<script setup lang="ts">
import { Check } from 'lucide-vue-next'
import { CheckboxIndicator, CheckboxRoot } from 'reka-ui'
import { useId } from 'vue'

const model = defineModel<boolean>({ default: false })
defineProps<{ label: string; disabled?: boolean }>()
const id = useId()
</script>

<template>
  <div class="flex min-h-10 items-center gap-2">
    <CheckboxRoot :id="id" v-model="model" :disabled="disabled" class="grid size-5 place-items-center rounded-sm border border-control bg-surface hover:border-text data-[state=checked]:border-accent data-[state=checked]:bg-accent data-[disabled]:opacity-50">
      <CheckboxIndicator><Check class="size-4 text-on-accent" aria-hidden="true" /></CheckboxIndicator>
    </CheckboxRoot>
    <label :for="id" class="text-text">{{ label }}</label>
  </div>
</template>
