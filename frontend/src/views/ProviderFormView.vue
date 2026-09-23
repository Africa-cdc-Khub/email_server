<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormField from '@/components/forms/FormField.vue'
import CaptchaWidget from '@/components/forms/CaptchaWidget.vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'
import { apiErrorMessage } from '@/lib/apiError'

type DriverField = {
  key: string
  label: string
  type: string
  required?: boolean
  default?: string | number
  options?: Array<{ value: string; label: string }>
}

type Driver = { value: string; label: string; fields: DriverField[] }

type MailboxRow = {
  id?: number | null
  email: string
  is_active: boolean
  daily_quota: number
  sent_24h?: number
  remaining_24h?: number
}

const SECRET_KEYS = new Set(['client_secret', 'password', 'secret'])

const route = useRoute()
const router = useRouter()
const isEdit = computed(() => route.name === 'provider-edit')
const id = computed(() => route.params.id as string | undefined)

const drivers = ref<Driver[]>([])
const loading = ref(false)
const saving = ref(false)
const testTo = ref('')
const testMailboxId = ref<number | null>(null)
const testing = ref(false)
const message = ref('')
const error = ref('')
const captchaKey = ref<string | null>(null)
const captchaAnswer = ref('')
const captchaResetKey = ref(0)
const storedSecrets = ref<Record<string, boolean>>({})
const configCorrupt = ref(false)
const form = ref({
  name: '',
  driver: 'exchange',
  from_name: 'Email Server',
  is_active: true,
  is_default: false,
  priority: 100,
  description: '',
  config: {} as Record<string, string | number>,
  mailboxes: [] as MailboxRow[],
})

const activeDriver = computed(() => drivers.value.find((d) => d.value === form.value.driver))

const activeMailboxes = computed(() =>
  form.value.mailboxes.filter((m) => m.is_active && m.email.trim() !== ''),
)

const testMailboxItems = computed(() =>
  activeMailboxes.value
    .filter((m) => m.id != null)
    .map((m) => ({
      title: `${m.email} (${m.remaining_24h ?? m.daily_quota} left / ${m.daily_quota})`,
      value: m.id as number,
    })),
)

function isSecretField(field: DriverField): boolean {
  return field.type === 'password' || SECRET_KEYS.has(field.key)
}

function secretHint(field: DriverField): string {
  if (isEdit.value && storedSecrets.value[field.key]) {
    return 'Stored securely — leave blank to keep the current value'
  }
  return ''
}

function addMailbox() {
  form.value.mailboxes.push({
    id: null,
    email: '',
    is_active: true,
    daily_quota: 10000,
  })
}

function removeMailbox(index: number) {
  form.value.mailboxes.splice(index, 1)
  if (form.value.mailboxes.length === 0) {
    addMailbox()
  }
}

async function loadDrivers() {
  const res = await api.get('/admin/email-providers/drivers')
  drivers.value = res.data.data
  if (!isEdit.value && activeDriver.value) {
    for (const field of activeDriver.value.fields) {
      if (field.default !== undefined && form.value.config[field.key] === undefined) {
        form.value.config[field.key] = field.default
      }
    }
  }
}

async function loadProvider() {
  if (!isEdit.value || !id.value) {
    if (form.value.mailboxes.length === 0) addMailbox()
    return
  }
  const res = await api.get(`/admin/email-providers/${id.value}`)
  const p = res.data.data
  storedSecrets.value = { ...(p.config_secrets ?? {}) }
  configCorrupt.value = Boolean(p.config_corrupt)

  const config: Record<string, string | number> = { ...(p.config ?? {}) }
  for (const key of SECRET_KEYS) {
    delete config[key]
  }

  const mailboxes: MailboxRow[] = (p.mailboxes ?? []).map((m: MailboxRow) => ({
    id: m.id,
    email: m.email,
    is_active: m.is_active !== false,
    daily_quota: m.daily_quota ?? 10000,
    sent_24h: m.sent_24h ?? 0,
    remaining_24h: m.remaining_24h ?? m.daily_quota ?? 10000,
  }))

  form.value = {
    name: p.name,
    driver: p.driver,
    from_name: p.from_name ?? '',
    is_active: p.is_active,
    is_default: p.is_default,
    priority: p.priority,
    description: p.description ?? '',
    config,
    mailboxes: mailboxes.length ? mailboxes : [{ id: null, email: p.from_address ?? '', is_active: true, daily_quota: 10000 }],
  }

  testMailboxId.value = testMailboxItems.value[0]?.value ?? null
}

function buildConfigPayload(): Record<string, string | number> {
  const payload: Record<string, string | number> = {}
  for (const [key, value] of Object.entries(form.value.config)) {
    if (SECRET_KEYS.has(key)) {
      if (typeof value === 'string' && value.trim() !== '') {
        payload[key] = value
      }
      continue
    }
    if (value !== null && value !== undefined && value !== '') {
      payload[key] = value
    }
  }
  return payload
}

async function save() {
  saving.value = true
  message.value = ''
  error.value = ''
  try {
    const mailboxes = form.value.mailboxes
      .filter((m) => m.email.trim() !== '')
      .map((m) => ({
        id: m.id ?? undefined,
        email: m.email.trim(),
        is_active: m.is_active,
        daily_quota: Number(m.daily_quota) || 10000,
      }))

    if (mailboxes.length === 0) {
      error.value = 'Add at least one from mailbox address.'
      return
    }

    const payload = {
      name: form.value.name,
      driver: form.value.driver,
      from_name: form.value.from_name,
      from_address: mailboxes.find((m) => m.is_active)?.email ?? mailboxes[0].email,
      is_active: form.value.is_active,
      is_default: form.value.is_default,
      priority: form.value.priority,
      description: form.value.description,
      config: buildConfigPayload(),
      mailboxes,
    }
    if (isEdit.value && id.value) {
      const res = await api.put(`/admin/email-providers/${id.value}`, payload)
      storedSecrets.value = { ...(res.data.data?.config_secrets ?? storedSecrets.value) }
      configCorrupt.value = Boolean(res.data.data?.config_corrupt)
      for (const key of SECRET_KEYS) {
        if (form.value.config[key] !== undefined) {
          form.value.config[key] = ''
        }
      }
      const updated = (res.data.data?.mailboxes ?? []) as MailboxRow[]
      form.value.mailboxes = updated.map((m) => ({
        id: m.id,
        email: m.email,
        is_active: m.is_active !== false,
        daily_quota: m.daily_quota ?? 10000,
        sent_24h: m.sent_24h ?? 0,
        remaining_24h: m.remaining_24h ?? m.daily_quota ?? 10000,
      }))
      testMailboxId.value = testMailboxItems.value[0]?.value ?? null
      message.value = 'Provider updated.'
    } else {
      await api.post('/admin/email-providers', payload)
      message.value = 'Provider created.'
      await router.push({ name: 'providers' })
    }
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not save provider.')
  } finally {
    saving.value = false
  }
}

async function sendTest() {
  if (!isEdit.value || !id.value || !testTo.value) return
  if (!testMailboxId.value) {
    error.value = 'Choose a from mailbox for the test email.'
    return
  }
  testing.value = true
  message.value = ''
  error.value = ''
  try {
    const payload: Record<string, unknown> = {
      to: testTo.value,
      from_mailbox_id: testMailboxId.value,
    }
    if (captchaKey.value) {
      payload.captcha_key = captchaKey.value
      payload.captcha = captchaAnswer.value
    }
    await api.post(`/admin/email-providers/${id.value}/test`, payload)
    message.value = `Test email sent to ${testTo.value}`
    captchaResetKey.value += 1
    await loadProvider()
  } catch (err) {
    error.value = apiErrorMessage(err, 'Test email failed.')
    captchaResetKey.value += 1
  } finally {
    testing.value = false
  }
}

onMounted(async () => {
  loading.value = true
  await loadDrivers()
  await loadProvider()
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader :title="isEdit ? 'Edit provider' : 'New provider'" />
    <v-alert v-if="message" type="success" variant="tonal" class="mb-4">{{ message }}</v-alert>
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>

    <ParentCard :title="isEdit ? 'Provider settings' : 'New provider'">
      <v-row>
        <v-col cols="12" md="6" class="form-stack">
          <FormField label="Name" required>
            <v-text-field v-model="form.name" variant="outlined" hide-details />
          </FormField>
          <FormField label="Driver" required>
            <v-select
              v-model="form.driver"
              :items="drivers"
              item-title="label"
              item-value="value"
              :disabled="isEdit"
              variant="outlined"
              hide-details
            />
          </FormField>
          <FormField label="From name">
            <v-text-field v-model="form.from_name" variant="outlined" hide-details />
          </FormField>
          <FormField label="Description">
            <v-textarea v-model="form.description" rows="3" variant="outlined" hide-details />
          </FormField>
          <v-switch v-model="form.is_active" label="Active" color="primary" class="mb-2" hide-details />
          <v-switch v-model="form.is_default" label="Set as default" color="primary" hide-details />
        </v-col>

        <v-col cols="12" md="6" class="form-stack">
          <div class="text-subtitle-1 font-weight-bold mb-4">Connection settings</div>
          <v-alert v-if="isEdit && form.driver === 'smtp'" type="info" variant="tonal" density="compact" class="mb-4">
            For cPanel / mail.africacdc.net use port <strong>465</strong> + <strong>SSL</strong>. Username must be the
            full mailbox email (same as a From mailbox), not the hostname.
          </v-alert>
          <v-alert v-if="isEdit && configCorrupt" type="warning" variant="tonal" density="compact" class="mb-4">
            Stored credentials cannot be decrypted (the server encryption key may have changed). Re-enter the password
            or secret fields below, then save.
          </v-alert>
          <v-alert v-else-if="isEdit" type="info" variant="tonal" density="compact" class="mb-4">
            Credentials are encrypted on the server and are never shown in this form. Leave secret fields blank to keep
            existing values.
          </v-alert>
          <template v-if="activeDriver">
            <template v-for="field in activeDriver.fields" :key="field.key">
              <FormField
                :label="field.label"
                :required="field.required && !(isEdit && isSecretField(field) && storedSecrets[field.key])"
              >
                <v-select
                  v-if="field.type === 'select'"
                  v-model="form.config[field.key]"
                  :items="field.options ?? []"
                  item-title="label"
                  item-value="value"
                  variant="outlined"
                  hide-details
                />
                <v-text-field
                  v-else
                  v-model="form.config[field.key]"
                  :type="isSecretField(field) ? 'password' : field.type === 'number' ? 'number' : 'text'"
                  :placeholder="secretHint(field)"
                  :autocomplete="isSecretField(field) ? 'new-password' : 'off'"
                  variant="outlined"
                  :hint="secretHint(field)"
                  :persistent-hint="Boolean(secretHint(field))"
                  :hide-details="!secretHint(field)"
                />
              </FormField>
            </template>
          </template>
        </v-col>
      </v-row>

      <v-divider class="my-6" />
      <div class="d-flex align-center justify-space-between mb-3">
        <div>
          <div class="text-subtitle-1 font-weight-bold">From mailboxes</div>
          <div class="text-medium-emphasis text-body-2">
            Sends rotate to the mailbox with the most remaining 24h quota (default 10,000).
          </div>
        </div>
        <v-btn variant="tonal" color="primary" prepend-icon="mdi-plus" @click="addMailbox">Add mailbox</v-btn>
      </div>

      <div v-for="(box, index) in form.mailboxes" :key="box.id ?? `new-${index}`" class="mailbox-row mb-4">
        <v-row dense align="center">
          <v-col cols="12" md="5">
            <v-text-field
              v-model="box.email"
              label="From email"
              type="email"
              variant="outlined"
              density="comfortable"
              hide-details
            />
          </v-col>
          <v-col cols="6" md="2">
            <v-text-field
              v-model.number="box.daily_quota"
              label="Daily quota"
              type="number"
              min="1"
              variant="outlined"
              density="comfortable"
              hide-details
            />
          </v-col>
          <v-col cols="6" md="3">
            <div class="text-caption text-medium-emphasis">24h usage</div>
            <div class="text-body-2">
              <template v-if="box.id != null">
                {{ box.remaining_24h ?? '—' }} left / {{ box.daily_quota }}
                <span class="text-medium-emphasis">({{ box.sent_24h ?? 0 }} sent)</span>
              </template>
              <template v-else>—</template>
            </div>
          </v-col>
          <v-col cols="8" md="1">
            <v-switch v-model="box.is_active" label="On" color="primary" hide-details density="compact" />
          </v-col>
          <v-col cols="4" md="1" class="d-flex justify-end">
            <v-btn
              icon="mdi-delete-outline"
              variant="text"
              color="error"
              :disabled="form.mailboxes.length <= 1"
              @click="removeMailbox(index)"
            />
          </v-col>
        </v-row>
      </div>

      <div class="d-flex ga-2 mt-6">
        <v-btn color="primary" :loading="saving" @click="save">Save</v-btn>
        <v-btn variant="text" :to="{ name: 'providers' }">Cancel</v-btn>
      </div>

      <template v-if="isEdit">
        <v-divider class="my-6" />
        <v-row align="center">
          <v-col cols="12" md="4">
            <FormField label="Test recipient">
              <v-text-field v-model="testTo" type="email" variant="outlined" hide-details />
            </FormField>
          </v-col>
          <v-col cols="12" md="4">
            <FormField label="From mailbox" required>
              <v-select
                v-model="testMailboxId"
                :items="testMailboxItems"
                item-title="title"
                item-value="value"
                variant="outlined"
                hide-details
              />
            </FormField>
          </v-col>
          <v-col cols="12" md="4" class="d-flex flex-column ga-3 pb-4">
            <CaptchaWidget
              :reset-key="captchaResetKey"
              @update:key="captchaKey = $event"
              @update:answer="captchaAnswer = $event"
            />
            <v-btn color="secondary" :loading="testing" class="align-self-start" @click="sendTest">
              Send test email
            </v-btn>
          </v-col>
        </v-row>
      </template>
    </ParentCard>
  </div>
</template>

<style scoped>
.mailbox-row {
  padding: 0.75rem 1rem;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 8px;
}
</style>
