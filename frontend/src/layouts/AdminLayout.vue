<script setup lang="ts">
import { ref } from 'vue'
import { RouterView } from 'vue-router'
import { useDisplay } from 'vuetify'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import AppTopbar from '@/components/layout/AppTopbar.vue'
import { useBrandingStore } from '@/stores/branding'

const branding = useBrandingStore()
const { mdAndDown } = useDisplay()
const drawer = ref(!mdAndDown.value)
</script>

<template>
  <v-layout class="admin-layout">
    <AppTopbar @toggle-drawer="drawer = !drawer" />

    <v-navigation-drawer
      v-model="drawer"
      width="270"
      class="left-sidebar"
      color="surface"
      elevation="0"
    >
      <div class="pa-4 pb-2">
        <div class="text-subtitle-2 text-medium-emphasis">Administration</div>
        <div class="text-h6 font-weight-bold">{{ branding.branding.app_name }}</div>
      </div>
      <AppSidebar />
    </v-navigation-drawer>

    <v-main class="bg-background">
      <v-container fluid class="page-wrapper py-6">
        <div class="max-width">
          <RouterView />
        </div>
      </v-container>
    </v-main>
  </v-layout>
</template>
