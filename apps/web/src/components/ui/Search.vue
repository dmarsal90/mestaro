<script setup lang="ts">
import { Search, X } from 'lucide-vue-next'
import { useId } from 'vue'

const model = defineModel<string>({ default: '' })
withDefaults(defineProps<{ label?: string }>(), { label: 'Buscar' })
const id = useId()
</script>

<template>
  <form role="search" class="relative" @submit.prevent>
    <label :for="id" class="sr-only">{{ label }}</label>
    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
    <input :id="id" v-model="model" type="search" :placeholder="label" class="min-h-10 w-full rounded-md border border-control bg-surface pr-10 pl-9 text-text placeholder:text-muted hover:border-text" />
    <button v-if="model" type="button" class="absolute top-1/2 right-1 grid size-8 -translate-y-1/2 place-items-center rounded-sm text-muted hover:text-text" aria-label="Borrar búsqueda" @click="model = ''">
      <X class="size-4" aria-hidden="true" />
    </button>
  </form>
</template>
