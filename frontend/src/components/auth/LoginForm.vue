<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import FormField from '@/components/forms/FormField.vue'
import { useAuthStore } from '@/stores/auth'
import { useBrandingStore } from '@/stores/branding'

const auth = useAuthStore()
const branding = useBrandingStore()
const router = useRouter()
const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')

async function submit() {
  loading.value = true
  error.value = ''
  try {
    const result = await auth.login(email.value, password.value)
    await router.push({ name: result.requires2fa ? 'verify-2fa' : 'dashboard' })
  } catch (e: unknown) {
    const axiosErr = e as {
      response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } }
    }
    const apiMessage = axiosErr.response?.data?.message
    const fieldError = axiosErr.response?.data?.errors?.email?.[0]
    if (axiosErr.response?.status === 503 && apiMessage) {
      error.value = apiMessage
    } else {
      error.value = fieldError ?? apiMessage ?? 'Invalid credentials or account inactive.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <v-card rounded="md" elevation="10" class="login-card withbg mx-auto" max-width="480">
    <div class="login-card__accent" />
    <v-card-item class="pa-sm-8 pa-6">
      <div class="text-h5 font-weight-bold mb-1 login-card__title">
        Sign in
      </div>
      <div class="text-body-2 login-card__tagline mb-6">
        {{ branding.branding.tagline || 'Manage email providers, integrations, and users' }}
      </div>

      <v-alert v-if="error" type="error" variant="tonal" class="mb-4" density="compact">
        {{ error }}
      </v-alert>

      <v-form class="auth-form" @submit.prevent="submit">
        <v-row class="d-flex mb-1">
          <v-col cols="12">
            <FormField label="Email" required>
              <v-text-field
                v-model="email"
                type="email"
                autocomplete="username"
                variant="outlined"
                hide-details
                color="primary"
              />
            </FormField>
          </v-col>
          <v-col cols="12">
            <FormField label="Password" required>
              <v-text-field
                v-model="password"
                type="password"
                autocomplete="current-password"
                variant="outlined"
                hide-details
                color="primary"
              />
            </FormField>
          </v-col>
          <v-col cols="12" class="d-flex justify-end pt-0">
            <v-btn variant="text" size="small" class="login-card__link px-0" :to="{ name: 'forgot-password' }">
              Forgot password?
            </v-btn>
          </v-col>
          <v-col cols="12" class="pt-2">
            <v-btn block color="primary" size="large" type="submit" :loading="loading">
              Sign in
            </v-btn>
          </v-col>
        </v-row>
      </v-form>
    </v-card-item>
  </v-card>
</template>

<style scoped>
.login-card {
  position: relative;
  overflow: hidden;
}

.login-card__accent {
  height: 4px;
  background: linear-gradient(
    90deg,
    var(--brand-primary, #0d7a3a) 0%,
    var(--brand-secondary, #c9a227) 100%
  );
}

.login-card__title,
.login-card__link {
  color: var(--brand-primary, #0d7a3a);
}

.login-card__tagline {
  color: rgb(var(--v-theme-text-secondary));
}
</style>
