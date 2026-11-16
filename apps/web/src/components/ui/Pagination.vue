<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { PaginationEllipsis, PaginationList, PaginationListItem, PaginationNext, PaginationPrev, PaginationRoot } from 'reka-ui'

const page = defineModel<number>({ default: 1 })
defineProps<{ total: number; pageSize: number }>()
const item = 'grid min-h-10 min-w-10 place-items-center rounded-md text-text hover:bg-border disabled:opacity-50 data-[selected]:bg-accent data-[selected]:text-on-accent'
</script>

<template>
  <PaginationRoot v-model:page="page" :total="total" :items-per-page="pageSize" :sibling-count="1" show-edges aria-label="Paginación">
    <PaginationList v-slot="{ items }" class="flex items-center gap-1">
      <PaginationPrev :class="item" aria-label="Página anterior"><ChevronLeft class="size-4" aria-hidden="true" /></PaginationPrev>
      <template v-for="(p, i) in items" :key="i">
        <PaginationListItem v-if="p.type === 'page'" :value="p.value" :class="item" :aria-label="`Página ${p.value}`">{{ p.value }}</PaginationListItem>
        <PaginationEllipsis v-else :index="i" class="px-1 text-muted">…</PaginationEllipsis>
      </template>
      <PaginationNext :class="item" aria-label="Página siguiente"><ChevronRight class="size-4" aria-hidden="true" /></PaginationNext>
    </PaginationList>
  </PaginationRoot>
</template>
