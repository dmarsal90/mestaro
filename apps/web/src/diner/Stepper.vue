<script setup lang="ts">
import { Minus, Plus } from 'lucide-vue-next'
import Button from '../components/ui/Button.vue'

const model = defineModel<number>({ required: true })
withDefaults(defineProps<{ label: string; min?: number; max: number }>(), { min: 0 })
</script>

<template>
  <div role="group" :aria-label="label" class="flex items-center gap-2">
    <Button size="lg" :aria-label="`Quitar uno: ${label}`" :disabled="model <= min" @click="model--"><Minus class="size-4" aria-hidden="true" /></Button>
    <output class="min-w-6 text-center" aria-live="polite">{{ model }}</output>
    <Button size="lg" :aria-label="`Añadir uno: ${label}`" :disabled="model >= max" @click="model++"><Plus class="size-4" aria-hidden="true" /></Button>
  </div>
</template>
