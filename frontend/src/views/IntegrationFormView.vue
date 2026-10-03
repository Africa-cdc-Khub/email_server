<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormField from '@/components/forms/FormField.vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'
import { generateClientSecret } from '@/lib/secrets'
import { useAuthStore } from '@/stores/auth'

type ProviderOption = { id: number; name: string; driver: string }
type MailboxOption = { id: number; email: string; is_active: boolean }

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isAdmin = computed(() => auth.isAdmin)
const isEdit = computed(() => route.name === 'integration-edit')
const id = computed(() => route.params.id as string | undefined)

const providers = ref<ProviderOption[]>([])
const mailboxes = ref<MailboxOption[]>([])
const hydrating = ref(false)
const loading = ref(false)
const saving = ref(false)
const created = ref(false)
const rotatedSecret = ref<string | null>(null)
const showSecret = ref(false)
/** On create: auto-generate. On edit: false until user chooses to rotate. */
const autoGenerateSecret = ref(true)
/** Edit mode: keep current credentials unless the operator opts to rotate. */
const preserveClientSecret = ref(true)
const clientSecretHint = ref('')
const copyMessage = ref('')

const form = ref({
  name: '',
  client_id: '',
  client_secret: '',
  email_provider_id: null as number | null,
  provider_mailbox_id: null as number | null,
  allowed_ips: '' as string,
  description: '',
  is_active: true,
})

const secretModeItems = [
  { value: true, title: 'Keep existing client secret' },
  { value: false, title: 'Rotate / set a new client secret' },
]

function generateSecret() {
  form.value.client_secret = generateClientSecret()
  showSecret.value = true
}

async function copySecret(secret: string) {
  await navigator.clipboard.writeText(secret)
  copyMessage.value = 'Copied to clipboard.'
  window.setTimeout(() => {
    copyMessage.value = ''
  }, 2000)
}

async function loadProviders() {
  const res = await api.get('/admin/email-providers')
  providers.value = res.data.data
}

async function loadMailboxes(providerId: number | null) {
  mailboxes.value = []
  if (!providerId) return
  const res = await api.get(`/admin/email-providers/${providerId}`)
  mailboxes.value = (res.data.data.mailboxes ?? []).filter(
    (m: MailboxOption) => m.id != null,
  )
}

async function loadIntegration() {
  if (!isEdit.value || !id.value) return
  const res = await api.get(`/admin/external-integrations/${id.value}`)
  const i = res.data.data
  hydrating.value = true
  try {
    form.value = {
      name: i.name,
      client_id: i.client_id ?? i.slug,
      client_secret: '',
      email_provider_id: i.email_provider_id,
      provider_mailbox_id: i.provider_mailbox_id ?? null,
      allowed_ips: (i.allowed_ips ?? []).join('\n'),
      description: i.description ?? '',
      is_active: i.is_active,
    }
    clientSecretHint.value = i.client_secret_hint ?? ''
    preserveClientSecret.value = true
    autoGenerateSecret.value = false
    await loadMailboxes(form.value.email_provider_id)
  } finally {
    hydrating.value = false
  }
}

async function save() {
  saving.value = true
  rotatedSecret.value = null
  const payload: Record<string, unknown> = {
    name: form.value.name,
    slug: form.value.client_id,
    client_id: form.value.client_id,
    email_provider_id: form.value.email_provider_id,
    provider_mailbox_id: form.value.provider_mailbox_id,
    allowed_ips: form.value.allowed_ips
      .split('\n')
      .map((s) => s.trim())
      .filter(Boolean),
    description: form.value.description,
  }

  if (isAdmin.value) {
    payload.is_active = form.value.is_active
  }

  try {
    if (isEdit.value && id.value) {
      // Default: do not send generate_secret / client_secret → server keeps the hash.
      if (!preserveClientSecret.value) {
        if (autoGenerateSecret.value) {
          payload.generate_secret = true
        } else if (form.value.client_secret) {
          payload.client_secret = form.value.client_secret
        } else {
          payload.generate_secret = true
        }
      }

      const res = await api.put(`/admin/external-integrations/${id.value}`, payload)
      if (res.data.client_secret) {
        rotatedSecret.value = res.data.client_secret
        form.value.client_secret = res.data.client_secret
        showSecret.value = true
        return
      }

      await router.push({ name: 'integrations' })
    } else {
      if (autoGenerateSecret.value) {
        payload.generate_secret = true
      } else if (form.value.client_secret) {
        payload.client_secret = form.value.client_secret
      } else {
        generateSecret()
        payload.client_secret = form.value.client_secret
      }

      const res = await api.post('/admin/external-integrations', payload)
      form.value.client_secret = res.data.client_secret ?? form.value.client_secret
      created.value = true
      showSecret.value = true
    }
  } finally {
    saving.value = false
  }
}

watch(preserveClientSecret, (keep) => {
  if (!isEdit.value) return
  if (keep) {
    form.value.client_secret = ''
    autoGenerateSecret.value = false
    return
  }
  autoGenerateSecret.value = true
  form.value.client_secret = ''
})

watch(autoGenerateSecret, (auto) => {
  if (isEdit.value && preserveClientSecret.value) return
  if (auto) {
    form.value.client_secret = ''
    return
  }

  if (!form.value.client_secret) {
    generateSecret()
  }
})

watch(
  () => form.value.email_provider_id,
  async (providerId, prev) => {
    await loadMailboxes(providerId)
    if (hydrating.value) return
    if (prev !== undefined && providerId !== prev) {
      const stillValid = mailboxes.value.some((m) => m.id === form.value.provider_mailbox_id)
      if (!stillValid) {
        form.value.provider_mailbox_id = null
      }
    }
  },
)

onMounted(async () => {
  if (!isEdit.value && !isAdmin.value && !auth.user?.two_factor_totp_enabled) {
    await router.replace({ name: 'security' })
    return
  }

  loading.value = true
  await loadProviders()
  await loadIntegration()
  if (!isEdit.value) {
    autoGenerateSecret.value = true
    preserveClientSecret.value = false
  }
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader :title="isEdit ? 'Edit integration' : 'New integration'" />

    <v-alert v-if="created" type="success" variant="tonal" class="mb-4">
      Integration created. Share these credentials with the connecting system:
      <div class="mt-2"><strong>client_id:</strong> <code>{{ form.client_id }}</code></div>
      <div class="d-flex align-center ga-2 flex-wrap">
        <div><strong>client_secret:</strong> <code>{{ form.client_secret }}</code></div>
        <v-btn size="small" variant="tonal" @click="copySecret(form.client_secret)">Copy secret</v-btn>
      </div>
      <div v-if="copyMessage" class="text-caption mt-1">{{ copyMessage }}</div>
      <div class="text-caption mt-2">
        Systems call <code>POST /api/v1/integrations/auth/token</code> with these values to obtain a JWT.
      </div>
      <v-btn size="small" class="mt-3" :to="{ name: 'integrations' }">Back to list</v-btn>
    </v-alert>

    <v-alert v-else-if="rotatedSecret" type="success" variant="tonal" class="mb-4">
      Client secret rotated. Share the new secret with the connecting system:
      <div class="d-flex align-center ga-2 flex-wrap mt-2">
        <code>{{ rotatedSecret }}</code>
        <v-btn size="small" variant="tonal" @click="copySecret(rotatedSecret)">Copy secret</v-btn>
      </div>
      <div v-if="copyMessage" class="text-caption mt-1">{{ copyMessage }}</div>
      <v-btn size="small" class="mt-3" :to="{ name: 'integrations' }">Back to list</v-btn>
    </v-alert>

    <ParentCard v-else :title="isEdit ? 'Integration settings' : 'Integration credentials'">
      <v-form @submit.prevent="save">
        <v-row>
          <v-col cols="12" md="6" class="form-stack">
            <FormField label="Name" required>
              <v-text-field v-model="form.name" variant="outlined" hide-details />
            </FormField>
            <FormField label="Client ID" required>
              <v-text-field
                v-model="form.client_id"
                :disabled="isEdit"
                variant="outlined"
                hide-details
              />
            </FormField>
            <FormField v-if="isEdit" label="Client credentials">
              <v-radio-group
                v-model="preserveClientSecret"
                hide-details
                class="mt-0"
              >
                <v-radio
                  v-for="item in secretModeItems"
                  :key="String(item.value)"
                  :label="item.title"
                  :value="item.value"
                  color="primary"
                />
              </v-radio-group>
              <div v-if="preserveClientSecret" class="text-caption text-medium-emphasis mt-1">
                Existing secret is kept
                <template v-if="clientSecretHint">
                  (hint: <code>{{ clientSecretHint }}</code>)
                </template>
                so connected apps keep working when you change provider, mailbox, or other settings.
              </div>
            </FormField>

            <FormField
              v-if="!isEdit || !preserveClientSecret"
              :label="isEdit ? 'New client secret' : 'Client secret'"
              :required="!isEdit && !autoGenerateSecret"
            >
              <v-switch
                v-model="autoGenerateSecret"
                label="Automatically generate secret"
                color="primary"
                hide-details
                class="mb-2"
              />
              <v-text-field
                v-model="form.client_secret"
                :type="showSecret ? 'text' : 'password'"
                :disabled="autoGenerateSecret"
                :placeholder="autoGenerateSecret ? 'A secure secret will be generated on save' : 'Enter or generate a secret'"
                variant="outlined"
                hide-details
                :append-inner-icon="showSecret ? 'mdi-eye-off' : 'mdi-eye'"
                @click:append-inner="showSecret = !showSecret"
              >
                <template #append>
                  <v-btn
                    icon
                    variant="text"
                    size="small"
                    title="Generate new secret"
                    :disabled="autoGenerateSecret"
                    @click="generateSecret"
                  >
                    <v-icon>mdi-auto-fix</v-icon>
                  </v-btn>
                </template>
              </v-text-field>
              <div class="text-caption text-medium-emphasis mt-1">
                Minimum 16 characters. Rotating the secret will disconnect apps until they use the new value.
              </div>
            </FormField>
          </v-col>
          <v-col cols="12" md="6" class="form-stack">
            <FormField label="Email provider">
              <v-select
                v-model="form.email_provider_id"
                :items="providers"
                item-title="name"
                item-value="id"
                variant="outlined"
                hide-details
                clearable
              />
            </FormField>
            <FormField label="Preferred from mailbox">
              <v-select
                v-model="form.provider_mailbox_id"
                :items="mailboxes"
                item-title="email"
                item-value="id"
                variant="outlined"
                hide-details
                clearable
                :disabled="!form.email_provider_id"
                :placeholder="form.email_provider_id ? 'Any mailbox (weighted)' : 'Select a provider first'"
              />
              <div class="text-caption text-medium-emphasis mt-1">
                This client uses the selected mailbox when quota remains. Other clients can still use it at a reduced share.
              </div>
            </FormField>
            <FormField label="Allowed IPs (one per line)">
              <v-textarea v-model="form.allowed_ips" rows="3" variant="outlined" hide-details />
            </FormField>
            <FormField label="Description">
              <v-textarea v-model="form.description" rows="2" variant="outlined" hide-details />
            </FormField>
            <v-switch
              v-if="isAdmin"
              v-model="form.is_active"
              label="Active"
              color="primary"
              hide-details
            />
            <v-alert v-else type="info" variant="tonal" density="compact" class="mt-2">
              New clients are created inactive. An administrator must activate this client before it can authenticate.
            </v-alert>
          </v-col>
        </v-row>
        <div class="d-flex ga-2 mt-6">
          <v-btn color="primary" type="submit" :loading="saving">Save</v-btn>
          <v-btn variant="text" :to="{ name: 'integrations' }">Cancel</v-btn>
        </div>
      </v-form>
    </ParentCard>
  </div>
</template>
