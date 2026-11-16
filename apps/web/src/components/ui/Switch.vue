<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from 'reka-ui'
import { useId } from 'vue'

const model = defineModel<boolean>({ default: false })
defineProps<{ label: string; disabled?: boolean }>()
const id = useId()
</script>

<template>
  <div class="flex min-h-10 items-center gap-3">
    <SwitchRoot :id="id" v-model="model" :disabled="disabled" class="relative h-6 w-11 rounded-full border border-control bg-surface transition-colors duration-[var(--duration-base)] data-[state=checked]:border-accent data-[state=checked]:bg-accent data-[disabled]:opacity-50">
      <SwitchThumb class="block size-4 translate-x-1 rounded-full bg-control transition-transform duration-[var(--duration-base)] ease-out motion-reduce:transition-none data-[state=checked]:translate-x-6 data-[state=checked]:bg-on-accent" />
    </SwitchRoot>
    <label :for="id" class="text-text">{{ label }}</label>
  </div>
</template>
