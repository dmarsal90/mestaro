<script setup lang="ts">
import Button from './ui/Button.vue'
import ErrorState from './ui/ErrorState.vue'
import LoadingState from './ui/LoadingState.vue'

/** Loading and failure of a page's first load; the content shows once there is data. */
defineProps<{ ready: boolean; error: string }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <slot v-if="ready" />
  <ErrorState v-else-if="error" title="No se pudo cargar" :cause="error">
    <template #action><Button @click="$emit('retry')">Reintentar</Button></template>
  </ErrorState>
  <LoadingState v-else />
</template>
