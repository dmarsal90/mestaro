<script setup lang="ts">
import { DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui'

defineProps<{ items: Array<{ label: string; danger?: boolean; disabled?: boolean; onSelect: () => void }> }>()
</script>

<template>
  <DropdownMenuRoot>
    <DropdownMenuTrigger as-child><slot /></DropdownMenuTrigger>
    <DropdownMenuPortal>
      <DropdownMenuContent :side-offset="4" align="end" class="z-[var(--z-overlay)] min-w-44 rounded-md border border-border bg-surface p-1 shadow-overlay">
        <DropdownMenuItem
          v-for="item in items"
          :key="item.label"
          :disabled="item.disabled"
          class="flex min-h-9 cursor-pointer items-center rounded-sm px-3 outline-none data-[highlighted]:bg-border data-[disabled]:opacity-50"
          :class="item.danger ? 'text-danger' : 'text-text'"
          @select="item.onSelect"
        >{{ item.label }}</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>
