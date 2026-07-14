<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'

type Integration = {
  id: number
  name: string
  slug: string
  client_id: string
  client_secret_hint: string
  email_provider: { id: number; name: string; driver: string } | null
  is_active: boolean
  last_used_at: string | null
}

const items = ref<Integration[]>([])
const loading = ref(true)
const router = useRouter()

async function load() {
  loading.value = true
  try {
    const res = await api.get('/admin/external-integrations')
    items.value = res.data.data
  } finally {
    loading.value = false
  }
}

async function remove(item: Integration) {
  if (!confirm(`Delete integration "${item.name}"?`)) return
  await api.delete(`/admin/external-integrations/${item.id}`)
  await load()
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      title="External integrations"
      subtitle="Connecting systems authenticate with client_id + client_secret to obtain a JWT"
    >
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" :to="{ name: 'integration-new' }">
          Add integration
        </v-btn>
      </template>
    </PageHeader>

    <ParentCard title="Integrations">
      <v-data-table
        :loading="loading"
        :items="items"
        :headers="[
          { title: 'Name', key: 'name' },
          { title: 'Client ID', key: 'client_id' },
          { title: 'Secret hint', key: 'client_secret_hint' },
          { title: 'Provider', key: 'email_provider' },
          { title: 'Active', key: 'is_active' },
          { title: 'Last used', key: 'last_used_at' },
          { title: 'Actions', key: 'actions', sortable: false },
        ]"
      >
        <template #item.email_provider="{ item }">
          {{ item.email_provider?.name ?? 'Default' }}
        </template>
        <template #item.is_active="{ item }">
          <v-icon :color="item.is_active ? 'success' : 'error'">
            {{ item.is_active ? 'mdi-check-circle' : 'mdi-close-circle' }}
          </v-icon>
        </template>
        <template #item.actions="{ item }">
          <v-btn
            size="small"
            variant="text"
            icon="mdi-pencil"
            @click="router.push({ name: 'integration-edit', params: { id: item.id } })"
          />
          <v-btn size="small" variant="text" color="error" icon="mdi-delete" @click="remove(item)" />
        </template>
      </v-data-table>
    </ParentCard>
  </div>
</template>
