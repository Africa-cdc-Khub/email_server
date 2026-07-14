<script setup lang="ts">
import { useRouter } from 'vue-router'
import AppLogo from '@/components/layout/AppLogo.vue'
import { toggleThemePreference } from '@/lib/themePreference'
import { useAuthStore } from '@/stores/auth'
import { useBrandingStore } from '@/stores/branding'

const emit = defineEmits<{ toggleDrawer: [] }>()

const auth = useAuthStore()
const branding = useBrandingStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <v-app-bar elevation="0" height="70" color="primary" class="px-4 px-sm-6">
    <v-app-bar-nav-icon class="d-lg-none" theme="dark" @click="emit('toggleDrawer')" />
    <AppLogo class="d-none d-sm-flex mr-4" :inverse="branding.branding.admin_logo_inverse" />
    <v-spacer />
    <v-btn icon variant="text" theme="dark" @click="toggleThemePreference()">
      <v-icon>mdi-theme-light-dark</v-icon>
    </v-btn>
    <v-btn
      variant="text"
      theme="dark"
      prepend-icon="mdi-book-open-page-variant"
      href="/api/documentation"
      target="_blank"
      rel="noopener"
      class="d-none d-md-inline-flex"
    >
      API docs
    </v-btn>
    <v-menu>
      <template #activator="{ props }">
        <v-btn v-bind="props" variant="tonal" color="surface" class="ml-2">
          <v-icon start>mdi-account-circle</v-icon>
          {{ auth.user?.name }}
        </v-btn>
      </template>
      <v-list density="compact" min-width="200">
        <v-list-item :subtitle="auth.user?.email" :title="auth.user?.name" />
        <v-divider />
        <v-list-item prepend-icon="mdi-shield-key-outline" title="Security" :to="{ name: 'security' }" />
        <v-list-item prepend-icon="mdi-logout" title="Logout" @click="logout" />
      </v-list>
    </v-menu>
  </v-app-bar>
</template>
