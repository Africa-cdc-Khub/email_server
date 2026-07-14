<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'

type UserRow = {
  id: number
  name: string
  email: string
  is_admin: boolean
  is_active: boolean
}

const items = ref<UserRow[]>([])
const loading = ref(true)
const router = useRouter()

async function load() {
  loading.value = true
  try {
    const res = await api.get('/admin/users')
    items.value = res.data.data
  } finally {
    loading.value = false
  }
}

async function remove(item: UserRow) {
  if (!confirm(`Delete user "${item.name}"?`)) return
  await api.delete(`/admin/users/${item.id}`)
  await load()
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="User management" subtitle="Admin accounts for the email server panel">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" :to="{ name: 'user-new' }">Add user</v-btn>
      </template>
    </PageHeader>

    <ParentCard title="Users">
      <v-data-table
        :loading="loading"
        :items="items"
        :headers="[
          { title: 'Name', key: 'name' },
          { title: 'Email', key: 'email' },
          { title: 'Admin', key: 'is_admin' },
          { title: 'Active', key: 'is_active' },
          { title: 'Actions', key: 'actions', sortable: false },
        ]"
      >
        <template #item.is_admin="{ item }">
          <v-chip :color="item.is_admin ? 'primary' : 'default'" size="small" variant="tonal">
            {{ item.is_admin ? 'Admin' : 'User' }}
          </v-chip>
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
            @click="router.push({ name: 'user-edit', params: { id: item.id } })"
          />
          <v-btn size="small" variant="text" color="error" icon="mdi-delete" @click="remove(item)" />
        </template>
      </v-data-table>
    </ParentCard>
  </div>
</template>
