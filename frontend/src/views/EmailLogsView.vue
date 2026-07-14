<script setup lang="ts">
import { onMounted, ref } from 'vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import { api } from '@/lib/api'

type EmailLog = {
  id: number
  to: string
  subject: string
  status: string
  driver: string | null
  error_message: string | null
  sending_system: string
  source: string
  created_at: string
}

const items = ref<EmailLog[]>([])
const loading = ref(true)
const page = ref(1)
const total = ref(0)

async function load() {
  loading.value = true
  try {
    const res = await api.get('/admin/email-logs', { params: { page: page.value } })
    items.value = res.data.data
    total.value = res.data.total
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      title="Email logs"
      subtitle="Delivery history with sending system and source"
    />

    <v-data-table-server
      :loading="loading"
      :items="items"
      :items-length="total"
      :headers="[
        { title: 'To', key: 'to' },
        { title: 'Subject', key: 'subject' },
        { title: 'Sending system', key: 'sending_system' },
        { title: 'Source', key: 'source' },
        { title: 'Status', key: 'status' },
        { title: 'Driver', key: 'driver' },
        { title: 'Error', key: 'error_message' },
        { title: 'When', key: 'created_at' },
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
      <template #item.status="{ item }">
        <v-chip size="small" :color="item.status === 'sent' ? 'success' : item.status === 'failed' ? 'error' : 'warning'">
          {{ item.status }}
        </v-chip>
      </template>
      <template #item.error_message="{ item }">
        <span class="text-caption text-medium-emphasis">{{ item.error_message || '—' }}</span>
      </template>
    </v-data-table-server>
  </div>
</template>
