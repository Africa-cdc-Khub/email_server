<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

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
const auth = useAuthStore()
const isAdmin = computed(() => auth.isAdmin)

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
      :title="isAdmin ? 'External integrations' : 'My clients'"
      :subtitle="
        isAdmin
          ? 'Connecting systems authenticate with client_id + client_secret to obtain a JWT'
          : 'Register clients for your organisation. New clients stay inactive until an administrator activates them.'
      "
    >
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" :to="{ name: 'integration-new' }">
          {{ isAdmin ? 'Add integration' : 'Add client' }}
        </v-btn>
      </template>
    </PageHeader>

    <v-alert v-if="!isAdmin" type="info" variant="tonal" class="mb-4">
      You only see clients linked to your account. Inactive clients cannot obtain a JWT until an admin activates them.
    </v-alert>

    <ParentCard :title="isAdmin ? 'Integrations' : 'Clients'">
      <v-data-table
        :loading="loading"
        :items="items"
        :headers="[
          { title: 'Name', key: 'name' },
          { title: 'Client ID', key: 'client_id' },
          { title: 'Secret hint', key: 'client_secret_hint' },
          { title: 'Provider', key: 'email_provider' },
          { title: 'Status', key: 'is_active' },
          { title: 'Last used', key: 'last_used_at' },
          { title: 'Actions', key: 'actions', sortable: false },
        ]"
      >
        <template #item.email_provider="{ item }">
          {{ item.email_provider?.name ?? 'Default' }}
        </template>
        <template #item.is_active="{ item }">
          <v-chip size="small" variant="tonal" :color="item.is_active ? 'success' : 'warning'">
            {{ item.is_active ? 'Active' : 'Inactive' }}
          </v-chip>
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
