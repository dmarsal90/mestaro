<script setup lang="ts" generic="Row extends Record<string, unknown>">
import { ArrowDown, ArrowUp } from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
  caption: string
  columns: Array<{ key: keyof Row & string; label: string; sortable?: boolean }>
  rows: Row[]
  density?: 'comfortable' | 'compact'
}>()
const sortKey = ref<string | null>(null)
const direction = ref<'ascending' | 'descending'>('ascending')

const sorted = computed(() => {
  const key = sortKey.value
  if (key === null) return props.rows
  const sign = direction.value === 'ascending' ? 1 : -1
  return [...props.rows].sort((a, b) => sign * String(a[key] ?? '').localeCompare(String(b[key] ?? ''), undefined, { numeric: true }))
})
function sortBy(key: string) {
  direction.value = sortKey.value === key && direction.value === 'ascending' ? 'descending' : 'ascending'
  sortKey.value = key
}
const ariaSort = (key: string) => (sortKey.value === key ? direction.value : 'none')
</script>

<template>
  <!-- On narrow screens each row becomes a list item: the header is hidden and every cell carries its own label. -->
  <div class="overflow-auto rounded-lg border border-border">
    <table class="w-full border-collapse text-left">
      <caption class="sr-only">{{ caption }}</caption>
      <thead class="sticky top-0 bg-surface max-sm:sr-only">
        <tr>
          <th v-for="c in columns" :key="c.key" scope="col" :aria-sort="c.sortable ? ariaSort(c.key) : undefined" class="border-b border-border px-4 py-3 text-sm font-semibold text-muted">
            <button v-if="c.sortable" type="button" class="inline-flex min-h-8 items-center gap-1 text-muted hover:text-text" @click="sortBy(c.key)">
              {{ c.label }}
              <ArrowUp v-if="sortKey === c.key && direction === 'ascending'" class="size-4" aria-hidden="true" />
              <ArrowDown v-else-if="sortKey === c.key" class="size-4" aria-hidden="true" />
            </button>
            <template v-else>{{ c.label }}</template>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, i) in sorted" :key="i" class="border-b border-border last:border-b-0 hover:bg-border/40 max-sm:block max-sm:p-3">
          <td v-for="c in columns" :key="c.key" :data-label="c.label" class="px-4 text-text max-sm:block max-sm:px-0 max-sm:before:mr-2 max-sm:before:text-muted max-sm:before:content-[attr(data-label)]" :class="density === 'compact' ? 'py-1.5' : 'py-3'">
            <slot :name="`cell-${c.key}`" :row="row" :value="row[c.key]">{{ row[c.key] }}</slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
