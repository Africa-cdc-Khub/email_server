<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { sidebarItems } from '@/config/sidebar'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const items = computed(() =>
  sidebarItems.filter((item) => !item.adminOnly || auth.user?.is_admin),
)
</script>

<template>
  <v-list nav density="comfortable" class="px-3 py-2">
    <template v-for="(item, index) in items" :key="`${item.title ?? item.header}-${index}`">
      <v-list-subheader v-if="item.header" class="text-uppercase font-weight-bold">
        {{ item.header }}
      </v-list-subheader>
      <v-list-item
        v-else-if="item.to"
        :to="item.to"
        :prepend-icon="item.icon"
        :title="item.title"
        rounded="lg"
        color="primary"
        :active="typeof item.to === 'object' && item.to.name === route.name"
      />
    </template>
  </v-list>
</template>
