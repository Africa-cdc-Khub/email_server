<script setup lang="ts">
import { onMounted, ref } from 'vue'
import FormField from '@/components/forms/FormField.vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'

type ProviderOption = { id: number; name: string; driver: string; is_default?: boolean }

const providers = ref<ProviderOption[]>([])
const loading = ref(false)
const sending = ref(false)
const message = ref('')
const error = ref('')
const lastLogId = ref<number | null>(null)

const form = ref({
  to: '',
  subject: '',
  body: '',
  is_html: true,
  provider_id: null as number | null,
  cc: '',
  bcc: '',
})

function parseAddresses(value: string): string[] {
  return value
    .split(/[,\n]/)
    .map((entry) => entry.trim())
    .filter(Boolean)
}

async function loadProviders() {
  const res = await api.get('/admin/email-providers')
  providers.value = res.data.data
  const defaultProvider = providers.value.find((provider) => provider.is_default)
  form.value.provider_id = defaultProvider?.id ?? providers.value[0]?.id ?? null
}

async function sendMail() {
  sending.value = true
  message.value = ''
  error.value = ''
  lastLogId.value = null

  try {
    const payload: Record<string, unknown> = {
      to: form.value.to,
      subject: form.value.subject,
      body: form.value.body,
      is_html: form.value.is_html,
      provider_id: form.value.provider_id,
    }

    const cc = parseAddresses(form.value.cc)
    const bcc = parseAddresses(form.value.bcc)
    if (cc.length) payload.cc = cc
    if (bcc.length) payload.bcc = bcc

    const res = await api.post('/admin/send-mail', payload)
    message.value = res.data.message ?? 'Email queued for delivery.'
    lastLogId.value = res.data.log_id ?? null
    form.value.subject = ''
    form.value.body = ''
    form.value.cc = ''
    form.value.bcc = ''
  } catch {
    error.value = 'Failed to queue email. Check the form and try again.'
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  loading.value = true
  await loadProviders()
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader
      title="Send email"
      subtitle="Compose and queue an email through the configured provider"
    />

    <v-alert v-if="message" type="success" variant="tonal" class="mb-4">
      {{ message }}
      <div v-if="lastLogId" class="text-caption mt-1">
        Log ID: {{ lastLogId }} —
        <router-link :to="{ name: 'logs' }">View email logs</router-link>
      </div>
    </v-alert>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>

    <ParentCard title="Compose email">
      <v-form @submit.prevent="sendMail">
        <v-row>
          <v-col cols="12" md="6" class="form-stack">
            <FormField label="To" required>
              <v-text-field
                v-model="form.to"
                type="email"
                variant="outlined"
                hide-details
                :disabled="loading"
              />
            </FormField>
            <FormField label="Subject" required>
              <v-text-field
                v-model="form.subject"
                variant="outlined"
                hide-details
                :disabled="loading"
              />
            </FormField>
            <FormField label="Email provider">
              <v-select
                v-model="form.provider_id"
                :items="providers"
                item-title="name"
                item-value="id"
                variant="outlined"
                hide-details
                :loading="loading"
                clearable
              />
            </FormField>
            <v-switch
              v-model="form.is_html"
              label="Send as HTML"
              color="primary"
              hide-details
              :disabled="loading"
            />
          </v-col>
          <v-col cols="12" md="6" class="form-stack">
            <FormField label="CC (comma-separated)">
              <v-text-field
                v-model="form.cc"
                variant="outlined"
                hide-details
                :disabled="loading"
              />
            </FormField>
            <FormField label="BCC (comma-separated)">
              <v-text-field
                v-model="form.bcc"
                variant="outlined"
                hide-details
                :disabled="loading"
              />
            </FormField>
            <FormField label="Body" required>
              <v-textarea
                v-model="form.body"
                rows="10"
                variant="outlined"
                hide-details
                :disabled="loading"
              />
            </FormField>
          </v-col>
        </v-row>
        <div class="d-flex ga-2 mt-6">
          <v-btn color="primary" type="submit" :loading="sending" :disabled="loading">
            Send email
          </v-btn>
        </div>
      </v-form>
    </ParentCard>
  </div>
</template>
