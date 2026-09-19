<script setup lang="ts">
import { ref } from 'vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'
import { apiErrorMessage } from '@/lib/apiError'

type CountPair = { created: number; updated: number }

type ImportSummary = {
  providers: CountPair
  users: CountPair
  clients: CountPair
  links_synced: number
  branding_updated: boolean
  warnings: string[]
}

const exporting = ref(false)
const importing = ref(false)
const file = ref<File[] | File | null>(null)
const error = ref('')
const message = ref('')
const summary = ref<ImportSummary | null>(null)

function pickFile(): File | null {
  const value = file.value
  if (Array.isArray(value)) return value[0] ?? null
  return value
}

function filenameFromDisposition(header: string | undefined, fallback: string): string {
  if (!header) return fallback
  const match = /filename\*?=(?:UTF-8''|")?([^\";]+)/i.exec(header)
  if (!match?.[1]) return fallback
  try {
    return decodeURIComponent(match[1].replace(/"/g, '').trim())
  } catch {
    return match[1].replace(/"/g, '').trim()
  }
}

async function downloadPackage() {
  exporting.value = true
  error.value = ''
  message.value = ''
  try {
    const res = await api.get('/admin/migration/export', { responseType: 'blob' })
    const blob = new Blob([res.data], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = filenameFromDisposition(
      res.headers['content-disposition'],
      `email-server-migration-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.json`,
    )
    anchor.click()
    URL.revokeObjectURL(url)
    message.value = 'Migration package downloaded. Store it securely — it contains credentials.'
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not export migration package.')
  } finally {
    exporting.value = false
  }
}

async function restorePackage() {
  const selected = pickFile()
  if (!selected) {
    error.value = 'Choose a migration JSON file to restore.'
    return
  }
  if (
    !confirm(
      'Restore this migration package? Matching users (by email), clients (by slug), and providers (by slug) will be created or updated. The file contains secrets.',
    )
  ) {
    return
  }

  importing.value = true
  error.value = ''
  message.value = ''
  summary.value = null
  try {
    const body = new FormData()
    body.append('file', selected)
    const res = await api.post('/admin/migration/import', body, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    summary.value = res.data.data as ImportSummary
    message.value = res.data.message || 'Migration imported.'
    file.value = null
  } catch (err) {
    error.value = apiErrorMessage(err, 'Could not import migration package.')
  } finally {
    importing.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader
      title="Backup / Restore"
      subtitle="Export users, clients, providers, and branding for migration to another server"
    />

    <v-alert type="warning" variant="tonal" class="mb-4" border="start">
      Migration packages include decrypted email-provider credentials and password hashes.
      Treat the download like a password dump and delete it when the migration is finished.
    </v-alert>

    <v-alert v-if="message" type="success" variant="tonal" class="mb-4" closable @click:close="message = ''">
      {{ message }}
    </v-alert>
    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable @click:close="error = ''">
      {{ error }}
    </v-alert>

    <v-row>
      <v-col cols="12" md="6">
        <ParentCard title="Export">
          <p class="text-medium-emphasis mb-4">
            Download a JSON package of accounts, integration clients, email providers, ownership links,
            and branding. Use this file on the destination server’s Backup / Restore page.
          </p>
          <v-btn
            color="primary"
            prepend-icon="mdi-download"
            :loading="exporting"
            @click="downloadPackage"
          >
            Download migration package
          </v-btn>
        </ParentCard>
      </v-col>

      <v-col cols="12" md="6">
        <ParentCard title="Restore">
          <p class="text-medium-emphasis mb-4">
            Upload a package from another server. Missing users are created; existing users and clients
            matched by email/slug are updated. Provider secrets are re-encrypted with this server’s key.
          </p>
          <v-file-input
            v-model="file"
            label="Migration JSON file"
            accept=".json,application/json"
            variant="outlined"
            density="comfortable"
            prepend-icon="mdi-file-upload-outline"
            show-size
            clearable
            class="mb-4"
          />
          <v-btn
            color="warning"
            prepend-icon="mdi-database-import-outline"
            :loading="importing"
            :disabled="!pickFile()"
            @click="restorePackage"
          >
            Restore package
          </v-btn>
        </ParentCard>
      </v-col>
    </v-row>

    <ParentCard v-if="summary" title="Last restore summary" class="mt-4">
      <v-row dense>
        <v-col cols="12" sm="4">
          <div class="text-caption text-medium-emphasis">Providers</div>
          <div>Created {{ summary.providers.created }}, updated {{ summary.providers.updated }}</div>
        </v-col>
        <v-col cols="12" sm="4">
          <div class="text-caption text-medium-emphasis">Users</div>
          <div>Created {{ summary.users.created }}, updated {{ summary.users.updated }}</div>
        </v-col>
        <v-col cols="12" sm="4">
          <div class="text-caption text-medium-emphasis">Clients</div>
          <div>Created {{ summary.clients.created }}, updated {{ summary.clients.updated }}</div>
        </v-col>
        <v-col cols="12" sm="4">
          <div class="text-caption text-medium-emphasis">Links synced</div>
          <div>{{ summary.links_synced }}</div>
        </v-col>
        <v-col cols="12" sm="4">
          <div class="text-caption text-medium-emphasis">Branding</div>
          <div>{{ summary.branding_updated ? 'Updated' : 'Unchanged' }}</div>
        </v-col>
      </v-row>
      <div v-if="summary.warnings?.length" class="mt-4">
        <div class="text-subtitle-2 mb-2">Warnings</div>
        <ul class="text-medium-emphasis">
          <li v-for="(warning, index) in summary.warnings" :key="index">{{ warning }}</li>
        </ul>
      </div>
    </ParentCard>
  </div>
</template>
