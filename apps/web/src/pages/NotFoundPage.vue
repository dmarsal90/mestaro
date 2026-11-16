<script setup lang="ts">
import { RouterLink } from 'vue-router'
import EmptyState from '../components/ui/EmptyState.vue'
</script>

<template>
  <EmptyState title="Página no encontrada" description="El enlace no existe o ya no está disponible.">
    <template #action><RouterLink to="/" class="text-text underline underline-offset-4">Volver al inicio</RouterLink></template>
  </EmptyState>
</template>
