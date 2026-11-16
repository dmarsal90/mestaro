<script setup lang="ts">
import { TooltipContent, TooltipPortal, TooltipProvider, TooltipRoot, TooltipTrigger } from 'reka-ui'

// Supplementary only: anything needed to use the control must be in its own label, not here.
defineProps<{ text: string }>()
</script>

<template>
  <TooltipProvider :delay-duration="300">
    <TooltipRoot>
      <TooltipTrigger as-child><slot /></TooltipTrigger>
      <TooltipPortal>
        <TooltipContent :side-offset="6" class="z-[var(--z-toast)] max-w-xs rounded-md border border-border bg-surface px-3 py-1.5 text-sm text-text shadow-overlay">{{ text }}</TooltipContent>
      </TooltipPortal>
    </TooltipRoot>
  </TooltipProvider>
</template>
