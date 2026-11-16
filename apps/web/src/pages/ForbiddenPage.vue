<script setup lang="ts">
import { RouterLink } from 'vue-router'
import ErrorState from '../components/ui/ErrorState.vue'
</script>

<template>
  <ErrorState title="No tienes acceso a esta sección" cause="Tu rol no incluye este permiso. Pídeselo a quien administre el restaurante.">
    <template #action><RouterLink to="/" class="text-text underline underline-offset-4">Volver al inicio</RouterLink></template>
  </ErrorState>
</template>
