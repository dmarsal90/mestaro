<script setup lang="ts">
import Button from './Button.vue'
import Dialog from './Dialog.vue'

// Destructive actions ask for explicit confirmation; the destructive button is never the default focus.
const open = defineModel<boolean>('open', { default: false })
withDefaults(defineProps<{ title: string; description?: string; confirmLabel?: string; cancelLabel?: string; loading?: boolean }>(), { confirmLabel: 'Confirmar', cancelLabel: 'Cancelar' })
defineEmits<{ confirm: [] }>()
</script>

<template>
  <Dialog v-model:open="open" :title="title" :description="description">
    <template #footer>
      <Button variant="secondary" @click="open = false">{{ cancelLabel }}</Button>
      <Button variant="danger" :loading="loading" @click="$emit('confirm')">{{ confirmLabel }}</Button>
    </template>
  </Dialog>
</template>
