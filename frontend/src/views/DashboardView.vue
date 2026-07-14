<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DefaultProviderCard from '@/components/dashboard/DefaultProviderCard.vue'
import EmailActivityOverview from '@/components/dashboard/EmailActivityOverview.vue'
import StatCard from '@/components/dashboard/StatCard.vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useBrandingStore } from '@/stores/branding'

type ActivityDay = {
  date: string
  label: string
  sent: number
  failed: number
}

type DashboardData = {
  stats: Record<string, number>
  email_activity: ActivityDay[]
  default_provider: { id: number; name: string; driver: string } | null
  recent_logs: Array<{
    id: number
    to: string
    subject: string
    status: string
    driver: string | null
    sending_system: string
    source: string
    created_at: string
  }>
}

const data = ref<DashboardData | null>(null)
const loading = ref(true)
const auth = useAuthStore()
const branding = useBrandingStore()

const statCards = computed(() => {
  const stats = data.value?.stats ?? {}

  return [
    {
      key: 'emails_sent_today',
      title: 'Sent today',
      value: stats.emails_sent_today ?? 0,
      icon: 'mdi-email-check-outline',
      color: 'success',
      subtitle: 'Successfully delivered',
    },
    {
      key: 'emails_failed_today',
      title: 'Failed today',
      value: stats.emails_failed_today ?? 0,
      icon: 'mdi-email-alert-outline',
      color: 'error',
      subtitle: 'Delivery errors',
    },
    {
      key: 'providers',
      title: 'Providers',
      value: stats.providers ?? 0,
      icon: 'mdi-server-network',
      color: 'primary',
      subtitle: `${stats.active_providers ?? 0} active`,
    },
    {
      key: 'integrations',
      title: 'Integrations',
      value: stats.integrations ?? 0,
      icon: 'mdi-connection',
      color: 'secondary',
      subtitle: 'External systems',
    },
  ]
})

function statusColor(status: string) {
  if (status === 'sent') return 'success'
  if (status === 'failed') return 'error'

  return 'warning'
}

onMounted(async () => {
  try {
    const res = await api.get('/admin/dashboard')
    data.value = res.data
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <PageHeader
      :title="`Welcome back, ${auth.user?.name ?? 'Admin'}`"
      :subtitle="`Overview of ${branding.branding.app_name} mail activity and configuration`"
    />

    <v-row v-if="loading">
      <v-col v-for="n in 4" :key="n" cols="12" sm="6" lg="3">
        <v-skeleton-loader type="card" />
      </v-col>
      <v-col cols="12" lg="8">
        <v-skeleton-loader type="image" height="320" />
      </v-col>
      <v-col cols="12" lg="4">
        <v-skeleton-loader type="card" height="420" />
      </v-col>
    </v-row>

    <template v-else-if="data">
      <v-row>
        <v-col v-for="card in statCards" :key="card.key" cols="12" sm="6" lg="3">
          <StatCard
            :title="card.title"
            :value="card.value"
            :icon="card.icon"
            :color="card.color"
            :subtitle="card.subtitle"
          />
        </v-col>
      </v-row>

      <v-row class="mt-1">
        <v-col cols="12" lg="8">
          <EmailActivityOverview :activity="data.email_activity ?? []" />
        </v-col>
        <v-col cols="12" lg="4">
          <DefaultProviderCard
            :provider="data.default_provider"
            :sent-today="data.stats.emails_sent_today ?? 0"
            :failed-today="data.stats.emails_failed_today ?? 0"
            :integrations="data.stats.integrations ?? 0"
          />
        </v-col>
      </v-row>

      <ParentCard title="Recent email activity">
        <template #action>
          <v-btn variant="text" color="primary" :to="{ name: 'logs' }">View all logs</v-btn>
        </template>

        <v-data-table
          :items="data.recent_logs"
          :headers="[
            { title: 'To', key: 'to' },
            { title: 'Subject', key: 'subject' },
            { title: 'Sending system', key: 'sending_system' },
            { title: 'Source', key: 'source' },
            { title: 'Status', key: 'status' },
            { title: 'When', key: 'created_at' },
          ]"
          item-value="id"
          class="dashboard-table"
          hover
        >
          <template #item.status="{ item }">
            <v-chip size="small" :color="statusColor(item.status)" variant="tonal" class="text-capitalize">
              {{ item.status }}
            </v-chip>
          </template>
          <template #item.subject="{ item }">
            <span class="text-truncate d-inline-block dashboard-table__subject">{{ item.subject }}</span>
          </template>
          <template #no-data>
            <div class="text-center py-8 text-medium-emphasis">
              <v-icon size="40" class="mb-2">mdi-email-outline</v-icon>
              <div>No email activity yet.</div>
              <v-btn color="primary" variant="tonal" class="mt-3" :to="{ name: 'send-mail' }">
                Send your first email
              </v-btn>
            </div>
          </template>
        </v-data-table>
      </ParentCard>
    </template>
  </div>
</template>

<style scoped>
.dashboard-table__subject {
  max-width: 260px;
}
</style>
