<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'
import { apiErrorMessage } from '@/lib/apiError'
import { formatDateTime12h } from '@/lib/formatDate'

type AuditLogRow = {
  id: number
  user_id: number | null
  user_name: string | null
  user_email: string | null
  action: string
  event_type: string
  http_method: string | null
  request_uri: string | null
  target_table: string | null
  target_id: string | null
  old_values: unknown
  new_values: unknown
  ip_address: string | null
  user_agent: string | null
  created_at: string | null
}

type Filters = {
  search: string
  name: string
  email: string
  http_method: string
  event_type: string
  date_from: string
  date_to: string
  per_page: number
}

const HTTP_METHOD_OPTIONS = ['', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE']

function defaultFilters(): Filters {
  return {
    search: '',
    name: '',
    email: '',
    http_method: '',
    event_type: '',
    date_from: '',
    date_to: '',
    per_page: 50,
  }
}

const loading = ref(false)
const error = ref('')
const rows = ref<AuditLogRow[]>([])
const filters = ref<Filters>(defaultFilters())
const draftFilters = ref<Filters>(defaultFilters())
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const perPage = ref(50)
const detailsOpen = ref(false)
const selectedLog = ref<AuditLogRow | null>(null)

const rowsOnPage = computed(() => rows.value.length)
const firstRowNumber = computed(() => (page.value - 1) * perPage.value + 1)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/admin/audit-logs', {
      params: {
        search: filters.value.search || undefined,
        name: filters.value.name || undefined,
        email: filters.value.email || undefined,
        http_method: filters.value.http_method || undefined,
        event_type: filters.value.event_type || undefined,
        date_from: filters.value.date_from || undefined,
        date_to: filters.value.date_to || undefined,
        page: page.value,
        per_page: filters.value.per_page,
      },
    })
    rows.value = res.data.data
    page.value = res.data.meta.current_page
    lastPage.value = res.data.meta.last_page
    total.value = res.data.meta.total
    perPage.value = res.data.meta.per_page || filters.value.per_page
  } catch (err) {
    rows.value = []
    total.value = 0
    error.value = apiErrorMessage(err, 'Could not load audit logs.')
  } finally {
    loading.value = false
  }
}

function userDisplay(row: AuditLogRow): string {
  return row.user_name || (row.user_id != null ? `User #${row.user_id}` : '—')
}

function targetDisplay(row: AuditLogRow): string {
  if (!row.target_table) return '—'
  return row.target_id != null && row.target_id !== '' ? `${row.target_table}#${row.target_id}` : row.target_table
}

function methodColor(method: string | null | undefined): string {
  switch ((method || '').toUpperCase()) {
    case 'GET':
      return 'info'
    case 'POST':
      return 'success'
    case 'PUT':
    case 'PATCH':
      return 'warning'
    case 'DELETE':
      return 'error'
    default:
      return 'default'
  }
}

function eventColor(eventType: string | null | undefined): string {
  const value = (eventType || '').toLowerCase()
  if (value.includes('delete')) return 'error'
  if (value.includes('create') || value.includes('insert')) return 'success'
  if (value.includes('update') || value.includes('edit') || value.includes('password')) return 'warning'
  if (value.includes('auth') || value.includes('access')) return 'info'
  return 'default'
}

function openDetails(row: AuditLogRow) {
  selectedLog.value = row
  detailsOpen.value = true
}

function parsedJson(value: unknown): string {
  if (value == null || value === '') return 'No snapshot recorded.'
  if (typeof value === 'string') {
    try {
      return JSON.stringify(JSON.parse(value), null, 2)
    } catch {
      return value
    }
  }
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

async function applyFilters() {
  filters.value = { ...draftFilters.value }
  page.value = 1
  await load()
}

async function resetFilters() {
  draftFilters.value = defaultFilters()
  filters.value = defaultFilters()
  page.value = 1
  await load()
}

async function goToPage(nextPage: number) {
  if (nextPage < 1 || nextPage > lastPage.value || nextPage === page.value) return
  page.value = nextPage
  await load()
}

onMounted(() => void load())
</script>

<template>
  <div>
    <PageHeader title="Audit logs" subtitle="Admin panel activity, mutations, and authentication events" />

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>

    <ParentCard title="Filters" class="mb-4">
      <v-row>
        <v-col cols="12" md="4">
          <v-text-field
            v-model="draftFilters.search"
            label="Search"
            density="compact"
            clearable
            hide-details
            placeholder="Action, URI, table, user..."
          />
        </v-col>
        <v-col cols="12" md="4">
          <v-text-field v-model="draftFilters.name" label="Name" density="compact" clearable hide-details />
        </v-col>
        <v-col cols="12" md="4">
          <v-text-field v-model="draftFilters.email" label="Email" density="compact" clearable hide-details />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-select
            v-model="draftFilters.http_method"
            :items="HTTP_METHOD_OPTIONS"
            label="Method"
            density="compact"
            hide-details
          />
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <v-text-field v-model="draftFilters.event_type" label="Event" density="compact" clearable hide-details />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-text-field v-model="draftFilters.date_from" label="Date from" type="date" density="compact" hide-details />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-text-field v-model="draftFilters.date_to" label="Date to" type="date" density="compact" hide-details />
        </v-col>
        <v-col cols="12" class="d-flex ga-2">
          <v-btn color="primary" :loading="loading" @click="applyFilters">Apply</v-btn>
          <v-btn variant="text" :disabled="loading" @click="resetFilters">Reset</v-btn>
        </v-col>
      </v-row>
    </ParentCard>

    <ParentCard title="Activity">
      <div class="text-caption text-medium-emphasis mb-3">
        Showing {{ rowsOnPage ? firstRowNumber : 0 }}–{{ firstRowNumber + rowsOnPage - (rowsOnPage ? 1 : 0) }}
        of {{ total }}
      </div>

      <v-data-table
        :headers="[
          { title: 'When', key: 'created_at', sortable: false },
          { title: 'User', key: 'user', sortable: false },
          { title: 'Method', key: 'http_method', sortable: false },
          { title: 'Event', key: 'event_type', sortable: false },
          { title: 'Action', key: 'action', sortable: false },
          { title: 'Target', key: 'target', sortable: false },
          { title: '', key: 'actions', sortable: false, align: 'end' },
        ]"
        :items="rows"
        :loading="loading"
        item-value="id"
        hide-default-footer
        class="elevation-0"
      >
        <template #item.created_at="{ item }">
          {{ item.created_at ? formatDateTime12h(item.created_at) : '—' }}
        </template>
        <template #item.user="{ item }">
          <div>{{ userDisplay(item) }}</div>
          <div class="text-caption text-medium-emphasis">{{ item.user_email || item.ip_address || '' }}</div>
        </template>
        <template #item.http_method="{ item }">
          <v-chip v-if="item.http_method" size="small" :color="methodColor(item.http_method)" variant="tonal">
            {{ item.http_method }}
          </v-chip>
          <span v-else>—</span>
        </template>
        <template #item.event_type="{ item }">
          <v-chip size="small" :color="eventColor(item.event_type)" variant="tonal">
            {{ item.event_type }}
          </v-chip>
        </template>
        <template #item.target="{ item }">
          {{ targetDisplay(item) }}
        </template>
        <template #item.actions="{ item }">
          <v-btn size="small" variant="text" @click="openDetails(item)">Details</v-btn>
        </template>
      </v-data-table>

      <div class="d-flex justify-end align-center ga-2 mt-4">
        <v-btn size="small" variant="text" :disabled="page <= 1 || loading" @click="goToPage(page - 1)">
          Previous
        </v-btn>
        <span class="text-caption">Page {{ page }} / {{ lastPage || 1 }}</span>
        <v-btn size="small" variant="text" :disabled="page >= lastPage || loading" @click="goToPage(page + 1)">
          Next
        </v-btn>
      </div>
    </ParentCard>

    <v-dialog v-model="detailsOpen" max-width="720">
      <v-card v-if="selectedLog">
        <v-card-title>Audit log #{{ selectedLog.id }}</v-card-title>
        <v-card-text>
          <p class="mb-2"><strong>Action:</strong> {{ selectedLog.action }}</p>
          <p class="mb-2"><strong>URI:</strong> {{ selectedLog.request_uri || '—' }}</p>
          <p class="mb-2"><strong>IP:</strong> {{ selectedLog.ip_address || '—' }}</p>
          <p class="mb-2"><strong>User agent:</strong> {{ selectedLog.user_agent || '—' }}</p>
          <p class="mb-1"><strong>Old values</strong></p>
          <pre class="audit-json mb-4">{{ parsedJson(selectedLog.old_values) }}</pre>
          <p class="mb-1"><strong>New values</strong></p>
          <pre class="audit-json">{{ parsedJson(selectedLog.new_values) }}</pre>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="detailsOpen = false">Close</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<style scoped>
.audit-json {
  margin: 0;
  padding: 0.75rem;
  border-radius: 6px;
  background: rgba(0, 0, 0, 0.04);
  font-size: 0.75rem;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 240px;
  overflow: auto;
}
</style>
