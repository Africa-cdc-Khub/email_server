<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import { api } from '@/lib/api'
import { apiErrorMessage } from '@/lib/apiError'
import { formatDateTime12h } from '@/lib/formatDate'
import { useAuthStore } from '@/stores/auth'

type EmailLog = {
  id: number
  to: string
  subject: string
  status: string
  driver: string | null
  error_message: string | null
  sending_system: string
  source: string
  sender_ip: string | null
  can_retry?: boolean
  external_integration_id: number | null
  created_at: string
}

type FilterOption = { value: string; label: string }
type ClientOption = { id: number; name: string }

const items = ref<EmailLog[]>([])
const loading = ref(true)
const page = ref(1)
const total = ref(0)
const error = ref('')
const message = ref('')
const retryingId = ref<number | null>(null)
const retryingAll = ref(false)

const statusFilter = ref<string | null>(null)
const clientFilter = ref<string | null>(null)
const search = ref('')
const auth = useAuthStore()
const canResend = computed(() => auth.isAdmin)

const statuses = ref<FilterOption[]>([
  { value: 'pending', label: 'Pending' },
  { value: 'sent', label: 'Sent' },
  { value: 'failed', label: 'Failed' },
])
const clients = ref<ClientOption[]>([])

const clientItems = computed(() => [
  { value: 'none', title: 'Admin / internal' },
  ...clients.value.map((c) => ({ value: String(c.id), title: c.name })),
])

async function loadFilters() {
  try {
    const res = await api.get('/admin/email-logs/filter-options')
    statuses.value = res.data.data.statuses ?? statuses.value
    clients.value = res.data.data.clients ?? []
  } catch {
    // Keep built-in status options
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/admin/email-logs', {
      params: {
        page: page.value,
        status: statusFilter.value || undefined,
        external_integration_id: clientFilter.value || undefined,
        q: search.value.trim() || undefined,
      },
    })
    items.value = res.data.data
    total.value = res.data.total
  } catch (err) {
    items.value = []
    total.value = 0
    error.value = apiErrorMessage(err, 'Could not load email logs.')
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  load()
}

function clearFilters() {
  statusFilter.value = null
  clientFilter.value = null
  search.value = ''
  page.value = 1
  load()
}

async function retry(item: EmailLog) {
  if (!item.can_retry) return
  retryingId.value = item.id
  message.value = ''
  error.value = ''
  try {
    const res = await api.post(`/admin/email-logs/${item.id}/retry`)
    message.value = res.data.message || 'Email queued for resend.'
    await load()
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not resend email.')
  } finally {
    retryingId.value = null
  }
}

async function retryAllFailed() {
  const scope = clientFilter.value
    ? 'failed emails for the selected client'
    : 'all failed emails'
  if (!confirm(`Resend ${scope} that still have a stored body?`)) return

  retryingAll.value = true
  message.value = ''
  error.value = ''
  try {
    const res = await api.post('/admin/email-logs/retry-failed', null, {
      params: {
        external_integration_id: clientFilter.value || undefined,
      },
    })
    message.value = res.data.message || 'Failed emails queued for resend.'
    statusFilter.value = 'pending'
    page.value = 1
    await load()
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not resend failed emails.')
  } finally {
    retryingAll.value = false
  }
}

watch([statusFilter, clientFilter], () => {
  page.value = 1
  load()
})

onMounted(async () => {
  await loadFilters()
  await load()
})
</script>

<template>
  <div>
    <PageHeader
      title="Email logs"
      subtitle="Delivery history — filter by client or status, resend failed emails"
    >
      <template #actions>
        <v-btn
          v-if="canResend"
          color="error"
          variant="tonal"
          prepend-icon="mdi-email-sync-outline"
          :loading="retryingAll"
          @click="retryAllFailed"
        >
          Resend all failed
        </v-btn>
      </template>
    </PageHeader>

    <v-alert v-if="message" type="success" variant="tonal" class="mb-4" closable @click:close="message = ''">
      {{ message }}
    </v-alert>
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable @click:close="error = ''">
      {{ error }}
    </v-alert>

    <v-row class="mb-4" dense>
      <v-col cols="12" md="3">
        <v-select
          v-model="statusFilter"
          :items="statuses"
          item-title="label"
          item-value="value"
          label="Status"
          clearable
          variant="outlined"
          hide-details
          density="comfortable"
        />
      </v-col>
      <v-col cols="12" md="3">
        <v-select
          v-model="clientFilter"
          :items="clientItems"
          item-title="title"
          item-value="value"
          label="Client"
          clearable
          variant="outlined"
          hide-details
          density="comfortable"
        />
      </v-col>
      <v-col cols="12" md="4">
        <v-text-field
          v-model="search"
          label="Search to / subject / IP"
          clearable
          variant="outlined"
          hide-details
          density="comfortable"
          prepend-inner-icon="mdi-magnify"
          @keyup.enter="applyFilters"
        />
      </v-col>
      <v-col cols="12" md="2" class="d-flex ga-2 align-center">
        <v-btn color="primary" variant="tonal" @click="applyFilters">Apply</v-btn>
        <v-btn variant="text" @click="clearFilters">Clear</v-btn>
      </v-col>
    </v-row>

    <v-data-table-server
      :loading="loading"
      :items="items"
      :items-length="total"
      :headers="[
        { title: 'To', key: 'to' },
        { title: 'Subject', key: 'subject' },
        { title: 'Client', key: 'source' },
        { title: 'IP address', key: 'sender_ip' },
        { title: 'Sending system', key: 'sending_system' },
        { title: 'Status', key: 'status' },
        { title: 'Driver', key: 'driver' },
        { title: 'Error', key: 'error_message' },
        { title: 'When', key: 'created_at' },
        { title: 'Actions', key: 'actions', sortable: false, width: 120 },
      ]"
      @update:page="(p: number) => { page = p; load() }"
    >
      <template #item.sending_system="{ item }">
        <span class="font-weight-medium">{{ item.sending_system }}</span>
      </template>
      <template #item.source="{ item }">
        <v-chip size="small" variant="tonal" color="primary">
          {{ item.source }}
        </v-chip>
      </template>
      <template #item.sender_ip="{ item }">
        <span class="text-no-wrap text-caption">{{ item.sender_ip || '—' }}</span>
      </template>
      <template #item.status="{ item }">
        <v-chip
          size="small"
          class="text-capitalize"
          :color="item.status === 'sent' ? 'success' : item.status === 'failed' ? 'error' : 'warning'"
        >
          {{ item.status }}
        </v-chip>
      </template>
      <template #item.error_message="{ item }">
        <span class="text-caption text-medium-emphasis">{{ item.error_message || '—' }}</span>
      </template>
      <template #item.created_at="{ item }">
        <span class="text-no-wrap">{{ formatDateTime12h(item.created_at) }}</span>
      </template>
      <template #item.actions="{ item }">
        <v-btn
          v-if="canResend && item.can_retry"
          size="small"
          color="primary"
          variant="tonal"
          prepend-icon="mdi-email-sync"
          :loading="retryingId === item.id"
          @click="retry(item)"
        >
          Resend
        </v-btn>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #no-data>
        <div class="text-medium-emphasis pa-6 text-center">
          No email logs match the current filters.
        </div>
      </template>
    </v-data-table-server>
  </div>
</template>
