<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'
import { apiErrorMessage } from '@/lib/apiError'
import { formatDateTime12h } from '@/lib/formatDate'

type AuditLogRow = {
  id: number
  actor_type: string
  actor_label: string
  user_id: number | null
  user_name: string | null
  user_email: string | null
  external_integration_id: number | null
  external_integration: { id: number; name: string; slug: string } | null
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
  is_suspicious: boolean
  suspicious_reasons: string | null
  created_at: string | null
}

type Filters = {
  search: string
  name: string
  email: string
  ip_address: string
  http_method: string | null
  event_type: string | null
  target_table: string | null
  actor_type: string | null
  suspicious: string | null
  date_from: string
  date_to: string
  per_page: number
}

function defaultFilters(): Filters {
  return {
    search: '',
    name: '',
    email: '',
    ip_address: '',
    http_method: null,
    event_type: null,
    target_table: null,
    actor_type: null,
    suspicious: null,
    date_from: '',
    date_to: '',
    per_page: 50,
  }
}

const loading = ref(false)
const error = ref('')
const rows = ref<AuditLogRow[]>([])
const filters = ref<Filters>(defaultFilters())
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const perPage = ref(50)
const detailsOpen = ref(false)
const selectedLog = ref<AuditLogRow | null>(null)

const eventTypes = ref<string[]>([])
const targetTables = ref<string[]>([])
const httpMethods = ref<string[]>(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])
const actorTypeOptions = ref([
  { title: 'System user', value: 'system_user' },
  { title: 'Client', value: 'client' },
  { title: 'System', value: 'system' },
])

const rowsOnPage = computed(() => rows.value.length)
const firstRowNumber = computed(() => (page.value - 1) * perPage.value + 1)

const activeFilterCount = computed(() => {
  const f = filters.value
  return [
    f.search,
    f.name,
    f.email,
    f.ip_address,
    f.http_method,
    f.event_type,
    f.target_table,
    f.actor_type,
    f.suspicious,
    f.date_from,
    f.date_to,
  ].filter((v) => v != null && String(v).trim() !== '').length
})

const suspiciousFilterItems = [
  { title: 'Suspicious only', value: '1' },
  { title: 'Not suspicious', value: '0' },
]

const ACTOR_LABELS: Record<string, string> = {
  system_user: 'System user',
  client: 'Client',
  system: 'System',
}

async function loadFilterOptions() {
  try {
    const res = await api.get('/admin/audit-logs/filter-options')
    eventTypes.value = res.data.data.event_types ?? []
    targetTables.value = res.data.data.target_tables ?? []
    if (Array.isArray(res.data.data.http_methods) && res.data.data.http_methods.length) {
      httpMethods.value = res.data.data.http_methods
    }
    if (Array.isArray(res.data.data.actor_types) && res.data.data.actor_types.length) {
      actorTypeOptions.value = res.data.data.actor_types.map((value: string) => ({
        title: ACTOR_LABELS[value] ?? value,
        value,
      }))
    }
  } catch {
    // Keep built-in options
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/admin/audit-logs', {
      params: {
        search: filters.value.search.trim() || undefined,
        name: filters.value.name.trim() || undefined,
        email: filters.value.email.trim() || undefined,
        ip_address: filters.value.ip_address.trim() || undefined,
        http_method: filters.value.http_method || undefined,
        event_type: filters.value.event_type || undefined,
        event_type_exact: filters.value.event_type ? 1 : undefined,
        target_table: filters.value.target_table || undefined,
        actor_type: filters.value.actor_type || undefined,
        suspicious: filters.value.suspicious || undefined,
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
  if (row.actor_type === 'client') {
    return row.external_integration?.name || row.user_name || row.user_email || 'Client'
  }
  return row.user_name || (row.user_id != null ? `User #${row.user_id}` : '—')
}

function actorColor(actorType: string | null | undefined): string {
  switch (actorType) {
    case 'client':
      return 'secondary'
    case 'system':
      return 'default'
    default:
      return 'primary'
  }
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
  page.value = 1
  await load()
}

async function clearFilters() {
  filters.value = defaultFilters()
  page.value = 1
  await load()
}

async function goToPage(nextPage: number) {
  if (nextPage < 1 || nextPage > lastPage.value || nextPage === page.value) return
  page.value = nextPage
  await load()
}

onMounted(async () => {
  await loadFilterOptions()
  await load()
})
</script>

<template>
  <div>
    <PageHeader title="Audit logs" subtitle="Admin panel activity, mutations, and authentication events" />

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable @click:close="error = ''">
      {{ error }}
    </v-alert>

    <ParentCard title="Filters">
      <v-row dense>
        <v-col cols="12" md="4">
          <v-text-field
            v-model="filters.search"
            label="Search action / URI / user / IP"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
            prepend-inner-icon="mdi-magnify"
            @keyup.enter="applyFilters"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-select
            v-model="filters.http_method"
            :items="httpMethods"
            label="Method"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-select
            v-model="filters.actor_type"
            :items="actorTypeOptions"
            item-title="title"
            item-value="value"
            label="Category"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-select
            v-model="filters.event_type"
            :items="eventTypes"
            label="Event type"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-select
            v-model="filters.target_table"
            :items="targetTables"
            label="Target table"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-select
            v-model="filters.suspicious"
            :items="suspiciousFilterItems"
            item-title="title"
            item-value="value"
            label="Suspicious"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
          />
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <v-text-field
            v-model="filters.name"
            label="User name"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
            @keyup.enter="applyFilters"
          />
        </v-col>
        <v-col cols="12" sm="6" md="3">
          <v-text-field
            v-model="filters.email"
            label="User email"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
            @keyup.enter="applyFilters"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-text-field
            v-model="filters.ip_address"
            label="IP address"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
            @keyup.enter="applyFilters"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-text-field
            v-model="filters.date_from"
            label="Date from"
            type="date"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-text-field
            v-model="filters.date_to"
            label="Date to"
            type="date"
            clearable
            variant="outlined"
            hide-details
            density="comfortable"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-select
            v-model="filters.per_page"
            :items="[25, 50, 100]"
            label="Rows"
            variant="outlined"
            hide-details
            density="comfortable"
          />
        </v-col>
        <v-col cols="12" md="4" class="d-flex flex-wrap ga-2 align-center">
          <v-btn color="primary" variant="tonal" :loading="loading" @click="applyFilters">Apply</v-btn>
          <v-btn variant="text" :disabled="loading || activeFilterCount === 0" @click="clearFilters">
            Clear{{ activeFilterCount ? ` (${activeFilterCount})` : '' }}
          </v-btn>
        </v-col>
      </v-row>
    </ParentCard>

    <div class="text-caption text-medium-emphasis mb-3">
      Showing {{ rowsOnPage ? firstRowNumber : 0 }}–{{ firstRowNumber + rowsOnPage - (rowsOnPage ? 1 : 0) }}
      of {{ total }}
    </div>

    <v-data-table
      :headers="[
        { title: 'When', key: 'created_at', sortable: false, minWidth: 180 },
        { title: 'Actor', key: 'user', sortable: false },
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
      :row-props="(row: { item: AuditLogRow }) => (row.item.is_suspicious ? { class: 'audit-row--suspicious' } : {})"
    >
      <template #item.created_at="{ item }">
        <div class="text-no-wrap">{{ item.created_at ? formatDateTime12h(item.created_at) : '—' }}</div>
        <div class="d-flex flex-wrap ga-1 mt-1">
          <v-chip size="x-small" :color="actorColor(item.actor_type)" variant="tonal">
            {{ item.actor_label || ACTOR_LABELS[item.actor_type] || item.actor_type }}
          </v-chip>
          <v-tooltip v-if="item.is_suspicious" location="top">
            <template #activator="{ props }">
              <v-chip
                v-bind="props"
                size="x-small"
                color="error"
                variant="flat"
                prepend-icon="mdi-alert"
              >
                Suspicious
              </v-chip>
            </template>
            <span>{{ item.suspicious_reasons || 'Flagged by security heuristics' }}</span>
          </v-tooltip>
        </div>
      </template>
      <template #item.user="{ item }">
        <div>{{ userDisplay(item) }}</div>
        <div class="text-caption text-medium-emphasis">
          {{
            item.actor_type === 'client'
              ? item.external_integration?.slug || item.user_email || ''
              : item.user_email || ''
          }}
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ item.ip_address || '—' }}
        </div>
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

    <v-dialog v-model="detailsOpen" max-width="720">
      <v-card v-if="selectedLog">
        <v-card-title>Audit log #{{ selectedLog.id }}</v-card-title>
        <v-card-text>
          <p class="mb-2"><strong>Action:</strong> {{ selectedLog.action }}</p>
          <p class="mb-2">
            <strong>Category:</strong>
            {{ selectedLog.actor_label || ACTOR_LABELS[selectedLog.actor_type] || selectedLog.actor_type }}
          </p>
          <p v-if="selectedLog.is_suspicious" class="mb-2">
            <v-chip size="small" color="error" variant="flat" class="mr-2">Suspicious</v-chip>
            {{ selectedLog.suspicious_reasons || 'Flagged by security heuristics' }}
          </p>
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
:deep(.audit-row--suspicious) {
  background: rgba(176, 0, 32, 0.06);
}
</style>
