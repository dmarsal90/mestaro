<script setup lang="ts">
import { Check, ChevronDown } from 'lucide-vue-next'
import { computed } from 'vue'
import { SelectContent, SelectItem, SelectItemIndicator, SelectItemText, SelectPortal, SelectRoot, SelectTrigger, SelectValue, SelectViewport } from 'reka-ui'

defineOptions({ inheritAttrs: false })
const model = defineModel<string>()
const props = defineProps<{ options: Array<{ value: string; label: string }>; placeholder?: string; invalid?: boolean }>()
// Shown by us, not looked up in the closed list, so the chosen option is visible before the list ever opens.
const chosen = computed(() => props.options.find((o) => o.value === model.value)?.label)
</script>

<template>
  <SelectRoot v-model="model">
    <SelectTrigger
      v-bind="$attrs"
      class="inline-flex min-h-10 w-full items-center justify-between gap-2 rounded-md border bg-surface px-3 text-text hover:border-text data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50"
      :class="invalid ? 'border-danger' : 'border-control'"
    >
      <SelectValue :placeholder="placeholder" class="data-[placeholder]:text-muted">{{ chosen }}</SelectValue>
      <ChevronDown class="size-4" aria-hidden="true" />
    </SelectTrigger>
    <SelectPortal>
      <SelectContent position="popper" :side-offset="4" class="z-[var(--z-overlay)] min-w-[var(--reka-select-trigger-width)] rounded-md border border-border bg-surface shadow-overlay">
        <SelectViewport class="p-1">
          <SelectItem
            v-for="o in options"
            :key="o.value"
            :value="o.value"
            class="relative flex min-h-9 cursor-pointer items-center rounded-sm py-1 pr-3 pl-8 text-text outline-none data-[highlighted]:bg-border data-[disabled]:opacity-50"
          >
            <SelectItemIndicator class="absolute left-2"><Check class="size-4" aria-hidden="true" /></SelectItemIndicator>
            <SelectItemText>{{ o.label }}</SelectItemText>
          </SelectItem>
        </SelectViewport>
      </SelectContent>
    </SelectPortal>
  </SelectRoot>
</template>
