<script setup lang="ts">
defineProps<{
  title: string
  value: number | string
  icon: string
  color?: string
  subtitle?: string
}>()
</script>

<template>
  <v-card elevation="10" class="withbg stat-card h-100">
    <v-card-text class="d-flex align-center justify-space-between">
      <div>
        <div class="text-subtitle-2 text-medium-emphasis mb-1">{{ title }}</div>
        <div class="text-h3 font-weight-semibold mb-0">{{ value }}</div>
        <div v-if="subtitle" class="text-caption text-medium-emphasis mt-1">{{ subtitle }}</div>
      </div>
      <v-avatar :color="color ?? 'primary'" variant="tonal" size="56" rounded="lg">
        <v-icon :icon="icon" size="28" />
      </v-avatar>
    </v-card-text>
  </v-card>
</template>

<style scoped>
.stat-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.stat-card:hover {
  transform: translateY(-2px);
}
</style>
