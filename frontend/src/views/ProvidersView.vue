<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/lib/api'

type Provider = {
  id: number
  name: string
  driver: string
  driver_label: string
  is_default: boolean
  is_active: boolean
  from_address: string | null
}

const items = ref<Provider[]>([])
const loading = ref(true)
const router = useRouter()

async function load() {
  loading.value = true
  try {
    const res = await api.get('/admin/email-providers')
    items.value = res.data.data
  } finally {
    loading.value = false
  }
}

async function setDefault(item: Provider) {
  await api.post(`/admin/email-providers/${item.id}/set-default`)
  await load()
}

async function remove(item: Provider) {
  if (!confirm(`Delete provider "${item.name}"?`)) return
  await api.delete(`/admin/email-providers/${item.id}`)
  await load()
}

onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="page-title mb-0">Email providers</h1>
      <v-btn color="primary" prepend-icon="mdi-plus" :to="{ name: 'provider-new' }">Add provider</v-btn>
    </div>

    <v-data-table :loading="loading" :items="items" :headers="[
      { title: 'Name', key: 'name' },
      { title: 'Driver', key: 'driver_label' },
      { title: 'From', key: 'from_address' },
      { title: 'Default', key: 'is_default' },
      { title: 'Active', key: 'is_active' },
      { title: 'Actions', key: 'actions', sortable: false },
    ]">
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
    </v-data-table>
  </div>
</template>
