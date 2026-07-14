<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormField from '@/components/forms/FormField.vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'

type DriverField = {
  key: string
  label: string
  type: string
  required?: boolean
  default?: string | number
  options?: Array<{ value: string; label: string }>
}

type Driver = { value: string; label: string; fields: DriverField[] }

const route = useRoute()
const router = useRouter()
const isEdit = computed(() => route.name === 'provider-edit')
const id = computed(() => route.params.id as string | undefined)

const drivers = ref<Driver[]>([])
const loading = ref(false)
const saving = ref(false)
const testTo = ref('')
const testing = ref(false)
const message = ref('')

const form = ref({
  name: '',
  driver: 'exchange',
  from_address: '',
  from_name: 'Email Server',
  is_active: true,
  is_default: false,
  priority: 100,
  description: '',
  config: {} as Record<string, string | number>,
})

const activeDriver = computed(() => drivers.value.find((d) => d.value === form.value.driver))

async function loadDrivers() {
  const res = await api.get('/admin/email-providers/drivers')
  drivers.value = res.data.data
}

async function loadProvider() {
  if (!isEdit.value || !id.value) return
  const res = await api.get(`/admin/email-providers/${id.value}`)
  const p = res.data.data
  form.value = {
    name: p.name,
    driver: p.driver,
    from_address: p.from_address ?? '',
    from_name: p.from_name ?? '',
    is_active: p.is_active,
    is_default: p.is_default,
    priority: p.priority,
    description: p.description ?? '',
    config: { ...(p.config ?? {}) },
  }
}

async function save() {
  saving.value = true
  message.value = ''
  try {
    const payload = { ...form.value }
    if (isEdit.value && id.value) {
      await api.put(`/admin/email-providers/${id.value}`, payload)
      message.value = 'Provider updated.'
    } else {
      await api.post('/admin/email-providers', payload)
      message.value = 'Provider created.'
      await router.push({ name: 'providers' })
    }
  } finally {
    saving.value = false
  }
}

async function sendTest() {
  if (!isEdit.value || !id.value || !testTo.value) return
  testing.value = true
  try {
    await api.post(`/admin/email-providers/${id.value}/test`, { to: testTo.value })
    message.value = `Test email sent to ${testTo.value}`
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
          <FormField label="From address">
            <v-text-field v-model="form.from_address" variant="outlined" hide-details />
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
          <template v-if="activeDriver">
            <template v-for="field in activeDriver.fields" :key="field.key">
              <FormField :label="field.label" :required="field.required">
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
                  :type="field.type === 'password' ? 'password' : field.type === 'number' ? 'number' : 'text'"
                  variant="outlined"
                  hide-details
                />
              </FormField>
            </template>
          </template>
        </v-col>
      </v-row>

      <div class="d-flex ga-2 mt-6">
        <v-btn color="primary" :loading="saving" @click="save">Save</v-btn>
        <v-btn variant="text" :to="{ name: 'providers' }">Cancel</v-btn>
      </div>

      <template v-if="isEdit">
        <v-divider class="my-6" />
        <v-row align="center">
          <v-col cols="12" md="6">
            <FormField label="Test recipient">
              <v-text-field v-model="testTo" type="email" variant="outlined" hide-details />
            </FormField>
          </v-col>
          <v-col cols="12" md="6" class="d-flex align-end pb-4">
            <v-btn color="secondary" :loading="testing" @click="sendTest">Send test email</v-btn>
          </v-col>
        </v-row>
      </template>
    </ParentCard>
  </div>
</template>
