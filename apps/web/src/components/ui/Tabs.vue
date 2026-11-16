<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui'

const model = defineModel<string>()
defineProps<{ tabs: Array<{ value: string; label: string }>; label: string }>()
</script>

<template>
  <TabsRoot v-model="model">
    <TabsList :aria-label="label" class="flex gap-1 border-b border-border">
      <TabsTrigger
        v-for="t in tabs"
        :key="t.value"
        :value="t.value"
        class="min-h-10 border-b-2 border-transparent px-4 text-muted hover:text-text data-[state=active]:border-accent data-[state=active]:text-text"
      >{{ t.label }}</TabsTrigger>
    </TabsList>
    <TabsContent v-for="t in tabs" :key="t.value" :value="t.value" class="pt-4"><slot :name="t.value" /></TabsContent>
  </TabsRoot>
</template>
