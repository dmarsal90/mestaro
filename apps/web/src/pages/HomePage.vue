<script setup lang="ts">
import Badge from '../components/ui/Badge.vue'
import Card from '../components/ui/Card.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import { useSession } from '../stores/session'

const session = useSession()
</script>

<template>
  <Card>
    <div class="flex items-center gap-3">
      <h1 class="text-xl font-semibold">Hola, {{ session.me?.name }}</h1>
      <Badge tone="info">{{ session.me?.role }}</Badge>
    </div>
    <EmptyState title="Tu espacio está listo" description="Usa el menú para abrir las secciones que permite tu rol." />
  </Card>
</template>
