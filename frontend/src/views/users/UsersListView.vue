<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'
import { apiErrorMessage } from '@/lib/apiError'

type IntegrationSummary = { id: number; name: string; slug: string }

type UserRow = {
  id: number
  name: string
  email: string
  phone?: string | null
  organisation?: string | null
  is_admin: boolean
  is_active: boolean
  approval_status?: string
  totp_required?: boolean
  two_factor_totp_enabled?: boolean
  must_setup_totp?: boolean
  external_integrations: IntegrationSummary[]
}

const items = ref<UserRow[]>([])
const loading = ref(true)
const actingId = ref<number | null>(null)
const message = ref('')
const error = ref('')
const statusFilter = ref<string | null>('pending')
const router = useRouter()

const statusOptions = [
  { value: null, title: 'All' },
  { value: 'pending', title: 'Pending' },
  { value: 'approved', title: 'Approved' },
  { value: 'rejected', title: 'Rejected' },
]

const pendingCount = computed(
  () => items.value.filter((u) => u.approval_status === 'pending').length,
)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/admin/users', {
      params: {
        approval_status: statusFilter.value || undefined,
      },
    })
    items.value = res.data.data
  } catch (err) {
    items.value = []
    error.value = apiErrorMessage(err, 'Could not load users.')
  } finally {
    loading.value = false
  }
}

async function approve(item: UserRow) {
  actingId.value = item.id
  message.value = ''
  error.value = ''
  try {
    const res = await api.post(`/admin/users/${item.id}/approve`)
    message.value = res.data.message || 'Account approved.'
    await load()
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not approve account.')
  } finally {
    actingId.value = null
  }
}

async function reject(item: UserRow) {
  const reason = window.prompt('Optional rejection reason:', '') ?? undefined
  if (reason === undefined) return
  actingId.value = item.id
  message.value = ''
  error.value = ''
  try {
    const res = await api.post(`/admin/users/${item.id}/reject`, {
      reason: reason || null,
    })
    message.value = res.data.message || 'Account rejected.'
    await load()
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not reject account.')
  } finally {
    actingId.value = null
  }
}

async function remove(item: UserRow) {
  if (!confirm(`Delete user "${item.name}"?`)) return
  await api.delete(`/admin/users/${item.id}`)
  await load()
}

watch(statusFilter, () => {
  load()
})

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="User management" subtitle="Approve registrations and manage panel accounts">
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" :to="{ name: 'user-new' }">Add user</v-btn>
      </template>
    </PageHeader>

    <v-alert v-if="message" type="success" variant="tonal" class="mb-4" closable @click:close="message = ''">
      {{ message }}
    </v-alert>
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable @click:close="error = ''">
      {{ error }}
    </v-alert>

    <ParentCard title="Users">
      <div class="d-flex flex-wrap ga-3 align-center mb-4">
        <v-select
          v-model="statusFilter"
          :items="statusOptions"
          item-title="title"
          item-value="value"
          label="Approval status"
          variant="outlined"
          density="comfortable"
          hide-details
          clearable
          style="max-width: 220px"
        />
        <v-chip v-if="statusFilter === 'pending' || pendingCount" size="small" color="warning" variant="tonal">
          Pending in view: {{ items.filter((u) => u.approval_status === 'pending').length }}
        </v-chip>
      </div>

      <v-data-table
        :loading="loading"
        :items="items"
        :headers="[
          { title: 'Name', key: 'name' },
          { title: 'Email', key: 'email' },
          { title: 'Organisation', key: 'organisation' },
          { title: 'Phone', key: 'phone' },
          { title: 'Approval', key: 'approval_status' },
          { title: 'Role', key: 'is_admin' },
          { title: 'Authenticator', key: 'authenticator' },
          { title: 'App access', key: 'external_integrations' },
          { title: 'Active', key: 'is_active' },
          { title: 'Actions', key: 'actions', sortable: false, width: 220 },
        ]"
      >
        <template #item.organisation="{ item }">
          {{ item.organisation || '—' }}
        </template>
        <template #item.phone="{ item }">
          {{ item.phone || '—' }}
        </template>
        <template #item.approval_status="{ item }">
          <v-chip
            size="small"
            variant="tonal"
            class="text-capitalize"
            :color="
              item.approval_status === 'approved'
                ? 'success'
                : item.approval_status === 'pending'
                  ? 'warning'
                  : item.approval_status === 'rejected'
                    ? 'error'
                    : 'default'
            "
          >
            {{ item.approval_status || 'approved' }}
          </v-chip>
        </template>
        <template #item.is_admin="{ item }">
          <v-chip :color="item.is_admin ? 'primary' : 'default'" size="small" variant="tonal">
            {{ item.is_admin ? 'Admin' : 'User' }}
          </v-chip>
        </template>
        <template #item.authenticator="{ item }">
          <v-chip
            v-if="item.must_setup_totp"
            size="small"
            color="warning"
            variant="tonal"
          >
            Setup required
          </v-chip>
          <v-chip
            v-else-if="item.two_factor_totp_enabled"
            size="small"
            color="success"
            variant="tonal"
          >
            {{ item.totp_required ? 'Required' : 'Enabled' }}
          </v-chip>
          <span v-else class="text-medium-emphasis">Optional</span>
        </template>
        <template #item.external_integrations="{ item }">
          <template v-if="item.is_admin">
            <v-chip size="small" color="primary" variant="tonal">All apps</v-chip>
          </template>
          <template v-else-if="!item.external_integrations?.length">
            <span class="text-medium-emphasis">None</span>
          </template>
          <div v-else class="d-flex flex-wrap ga-1">
            <v-chip
              v-for="app in item.external_integrations"
              :key="app.id"
              size="small"
              variant="tonal"
            >
              {{ app.name }}
            </v-chip>
          </div>
        </template>
        <template #item.is_active="{ item }">
          <v-icon :color="item.is_active ? 'success' : 'error'">
            {{ item.is_active ? 'mdi-check-circle' : 'mdi-close-circle' }}
          </v-icon>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex ga-1 flex-wrap">
            <v-btn
              v-if="item.approval_status === 'pending'"
              size="small"
              color="success"
              variant="tonal"
              :loading="actingId === item.id"
              @click="approve(item)"
            >
              Approve
            </v-btn>
            <v-btn
              v-if="item.approval_status === 'pending'"
              size="small"
              color="error"
              variant="tonal"
              :loading="actingId === item.id"
              @click="reject(item)"
            >
              Reject
            </v-btn>
            <v-btn
              size="small"
              variant="text"
              icon="mdi-pencil"
              @click="router.push({ name: 'user-edit', params: { id: item.id } })"
            />
            <v-btn size="small" variant="text" icon="mdi-delete" color="error" @click="remove(item)" />
          </div>
        </template>
      </v-data-table>
    </ParentCard>
  </div>
</template>
