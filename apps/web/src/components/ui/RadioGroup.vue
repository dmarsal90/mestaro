<script setup lang="ts">
import { RadioGroupIndicator, RadioGroupItem, RadioGroupRoot } from 'reka-ui'
import { useId } from 'vue'

const model = defineModel<string>()
defineProps<{ legend: string; options: Array<{ value: string; label: string }> }>()
const id = useId()
</script>

<template>
  <RadioGroupRoot v-model="model" :aria-labelledby="`${id}-legend`" class="flex flex-col gap-1">
    <span :id="`${id}-legend`" class="text-sm font-medium text-text">{{ legend }}</span>
    <div v-for="o in options" :key="o.value" class="flex min-h-10 items-center gap-2">
      <RadioGroupItem :id="`${id}-${o.value}`" :value="o.value" class="grid size-5 place-items-center rounded-full border border-control bg-surface hover:border-text data-[state=checked]:border-accent">
        <RadioGroupIndicator class="size-2.5 rounded-full bg-accent" />
      </RadioGroupItem>
      <label :for="`${id}-${o.value}`" class="text-text">{{ o.label }}</label>
    </div>
  </RadioGroupRoot>
</template>
