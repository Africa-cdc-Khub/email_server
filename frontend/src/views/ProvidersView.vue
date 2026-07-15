<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/lib/api'
import { apiErrorMessage } from '@/lib/apiError'

type Provider = {
  id: number
  name: string
  driver: string
  driver_label: string
  is_default: boolean
  is_active: boolean
  from_address: string | null
  config_corrupt?: boolean
}

const items = ref<Provider[]>([])
const loading = ref(true)
const error = ref('')
const router = useRouter()

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/admin/email-providers')
    items.value = res.data.data ?? []
  } catch (err) {
    items.value = []
    error.value = apiErrorMessage(err, 'Could not load providers.')
  } finally {
    loading.value = false
  }
}

async function setDefault(item: Provider) {
  error.value = ''
  try {
    await api.post(`/admin/email-providers/${item.id}/set-default`)
    await load()
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not set default provider.')
  }
}

async function remove(item: Provider) {
  if (!confirm(`Delete provider "${item.name}"?`)) return
  error.value = ''
  try {
    await api.delete(`/admin/email-providers/${item.id}`)
    await load()
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not delete provider.')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="page-title mb-0">Email providers</h1>
      <v-btn color="primary" prepend-icon="mdi-plus" :to="{ name: 'provider-new' }">Add provider</v-btn>
    </div>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>
    <v-alert
      v-if="items.some((i) => i.config_corrupt)"
      type="warning"
      variant="tonal"
      class="mb-4"
    >
      Some providers have credentials encrypted with an old APP_KEY. Edit them and re-save the connection settings
      (client secret / SMTP password).
    </v-alert>

    <v-data-table
      :loading="loading"
      :items="items"
      :headers="[
        { title: 'Name', key: 'name' },
        { title: 'Driver', key: 'driver_label' },
        { title: 'From', key: 'from_address' },
        { title: 'Default', key: 'is_default' },
        { title: 'Active', key: 'is_active' },
        { title: 'Actions', key: 'actions', sortable: false },
      ]"
    >
      <template #item.name="{ item }">
        {{ item.name }}
        <v-chip v-if="item.config_corrupt" class="ml-2" color="warning" size="x-small">Re-save secrets</v-chip>
      </template>
      <template #item.is_default="{ item }">
        <v-chip v-if="item.is_default" color="primary" size="small">Default</v-chip>
      </template>
      <template #item.is_active="{ item }">
        <v-icon :color="item.is_active ? 'success' : 'error'">
          {{ item.is_active ? 'mdi-check-circle' : 'mdi-close-circle' }}
        </v-icon>
      </template>
      <template #item.actions="{ item }">
        <v-btn size="small" variant="text" icon="mdi-pencil" @click="router.push({ name: 'provider-edit', params: { id: item.id } })" />
        <v-btn v-if="!item.is_default" size="small" variant="text" icon="mdi-star" @click="setDefault(item)" />
        <v-btn v-if="!item.is_default" size="small" variant="text" color="error" icon="mdi-delete" @click="remove(item)" />
      </template>
      <template #no-data>
        <div class="text-medium-emphasis pa-6">
          No providers yet. Click <strong>Add provider</strong> to create one.
        </div>
      </template>
    </v-data-table>
  </div>
</template>
