<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import FormField from '@/components/forms/FormField.vue'
import HtmlEditor from '@/components/forms/HtmlEditor.vue'
import CaptchaWidget from '@/components/forms/CaptchaWidget.vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import { api } from '@/lib/api'
import { apiErrorMessage } from '@/lib/apiError'

type ProviderOption = { id: number; name: string; driver: string; is_default?: boolean }
type AttachmentPayload = { filename: string; content: string; content_type: string }

const MAX_ATTACHMENTS = 10
const MAX_BYTES_PER_FILE = 5 * 1024 * 1024
const MAX_RECIPIENTS = 6000
const EMAIL_RE = /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g

const providers = ref<ProviderOption[]>([])
const loading = ref(false)
const sending = ref(false)
const message = ref('')
const error = ref('')
const lastLogId = ref<number | null>(null)
const queuedCount = ref<number | null>(null)
const scheduledSendAt = ref<string | null>(null)
const captchaKey = ref<string | null>(null)
const captchaAnswer = ref('')
const captchaResetKey = ref(0)
const attachmentFiles = ref<File[]>([])
const attachmentInputKey = ref(0)
const attachmentInput = ref<HTMLInputElement | null>(null)
const csvInput = ref<HTMLInputElement | null>(null)
const csvInputKey = ref(0)
const editorResetKey = ref(0)

const form = ref({
  to: '',
  subject: '',
  body: '',
  is_html: true,
  provider_id: null as number | null,
  cc: '',
  bcc: '',
  schedule_enabled: false,
  send_at: '',
})

function bodyIsEmpty(value: string): boolean {
  const plain = value
    .replace(/<br\s*\/?>/gi, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/<[^>]+>/g, '')
    .trim()
  return plain.length === 0
}

const parsedTo = computed(() => extractEmails(form.value.to))
const parsedCc = computed(() => extractEmails(form.value.cc))
const parsedBcc = computed(() => extractEmails(form.value.bcc))

const recipientCapacityLabel = computed(() => {
  const n = parsedTo.value.length
  return `${n.toLocaleString()} / ${MAX_RECIPIENTS.toLocaleString()} recipients`
})

const recipientProgress = computed(() =>
  Math.min(100, (parsedTo.value.length / MAX_RECIPIENTS) * 100),
)

const minScheduleLocal = computed(() => toLocalInputValue(new Date(Date.now() + 60_000)))

const attachmentLabels = computed(() =>
  attachmentFiles.value.map((file) => `${file.name} (${formatBytes(file.size)})`),
)

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function toLocalInputValue(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function extractEmails(value: string): string[] {
  const matches = value.match(EMAIL_RE) ?? []
  const seen = new Set<string>()
  const emails: string[] = []
  for (const raw of matches) {
    const email = raw.trim().toLowerCase()
    if (!email || seen.has(email)) continue
    seen.add(email)
    emails.push(email)
  }
  return emails
}

function mergeRecipients(extra: string[]) {
  const merged = extractEmails([...parsedTo.value, ...extra].join('\n'))
  if (merged.length > MAX_RECIPIENTS) {
    error.value = `You can send to at most ${MAX_RECIPIENTS.toLocaleString()} recipients in one schedule.`
    form.value.to = merged.slice(0, MAX_RECIPIENTS).join('\n')
    return
  }
  form.value.to = merged.join('\n')
}

function onAttachmentPick(event: Event) {
  const input = event.target as HTMLInputElement
  const picked = Array.from(input.files ?? [])
  error.value = ''

  if (picked.length === 0) return

  const next = [...attachmentFiles.value]
  for (const file of picked) {
    if (next.length >= MAX_ATTACHMENTS) {
      error.value = `You can attach at most ${MAX_ATTACHMENTS} files.`
      break
    }
    if (file.size > MAX_BYTES_PER_FILE) {
      error.value = `${file.name} is larger than 5 MB.`
      continue
    }
    if (next.some((existing) => existing.name === file.name && existing.size === file.size)) {
      continue
    }
    next.push(file)
  }

  attachmentFiles.value = next
  attachmentInputKey.value += 1
}

function removeAttachment(index: number) {
  attachmentFiles.value = attachmentFiles.value.filter((_, i) => i !== index)
}

function removeRecipient(email: string) {
  const remaining = parsedTo.value.filter((entry) => entry !== email)
  form.value.to = remaining.join('\n')
}

async function onCsvPick(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  csvInputKey.value += 1
  if (!file) return

  error.value = ''
  try {
    const text = await file.text()
    const found = extractEmails(text)
    if (found.length === 0) {
      error.value = 'No email addresses found in that file.'
      return
    }
    mergeRecipients(found)
    message.value = `Loaded ${found.length.toLocaleString()} address${found.length === 1 ? '' : 'es'} from ${file.name}.`
  } catch {
    error.value = `Could not read ${file.name}.`
  }
}

function readFileAsAttachment(file: File): Promise<AttachmentPayload> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      const result = String(reader.result ?? '')
      const content = result.includes(',') ? result.slice(result.indexOf(',') + 1) : result
      resolve({
        filename: file.name,
        content,
        content_type: file.type || 'application/octet-stream',
      })
    }
    reader.onerror = () => reject(new Error(`Could not read ${file.name}`))
    reader.readAsDataURL(file)
  })
}

async function loadProviders() {
  const res = await api.get('/admin/email-providers')
  providers.value = res.data.data
  const defaultProvider = providers.value.find((provider) => provider.is_default)
  form.value.provider_id = defaultProvider?.id ?? providers.value[0]?.id ?? null
}

async function sendMail() {
  sending.value = true
  message.value = ''
  error.value = ''
  lastLogId.value = null
  queuedCount.value = null
  scheduledSendAt.value = null

  const recipients = parsedTo.value
  if (recipients.length === 0) {
    error.value = 'Add at least one valid email address in To.'
    sending.value = false
    return
  }
  if (recipients.length > MAX_RECIPIENTS) {
    error.value = `You can send to at most ${MAX_RECIPIENTS.toLocaleString()} recipients in one schedule.`
    sending.value = false
    return
  }
  if (bodyIsEmpty(form.value.body)) {
    error.value = 'Message body is required.'
    sending.value = false
    return
  }

  let sendAtIso: string | null = null
  if (form.value.schedule_enabled) {
    if (!form.value.send_at) {
      error.value = 'Choose a date and time for the scheduled send.'
      sending.value = false
      return
    }
    const when = new Date(form.value.send_at)
    if (Number.isNaN(when.getTime()) || when.getTime() <= Date.now()) {
      error.value = 'Scheduled time must be in the future.'
      sending.value = false
      return
    }
    sendAtIso = when.toISOString()
  }

  try {
    const attachments =
      attachmentFiles.value.length > 0
        ? await Promise.all(attachmentFiles.value.map(readFileAsAttachment))
        : []

    const payload: Record<string, unknown> = {
      to: recipients,
      subject: form.value.subject,
      body: form.value.body,
      is_html: form.value.is_html,
      provider_id: form.value.provider_id,
    }

    if (parsedCc.value.length) payload.cc = parsedCc.value
    if (parsedBcc.value.length) payload.bcc = parsedBcc.value
    if (attachments.length) payload.attachments = attachments
    if (sendAtIso) payload.send_at = sendAtIso
    if (captchaKey.value) {
      payload.captcha_key = captchaKey.value
      payload.captcha = captchaAnswer.value
    }

    const res = await api.post('/admin/send-mail', payload)
    message.value = res.data.message ?? 'Email queued for delivery.'
    lastLogId.value = res.data.log_id ?? null
    queuedCount.value = res.data.queued_count ?? recipients.length
    scheduledSendAt.value = res.data.send_at ?? sendAtIso
    form.value.to = ''
    form.value.subject = ''
    form.value.body = ''
    form.value.cc = ''
    form.value.bcc = ''
    form.value.schedule_enabled = false
    form.value.send_at = ''
    editorResetKey.value += 1
    attachmentFiles.value = []
    attachmentInputKey.value += 1
    captchaResetKey.value += 1
  } catch (err) {
    error.value = apiErrorMessage(err, 'Failed to queue email. Check the form and try again.')
    captchaResetKey.value += 1
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  loading.value = true
  await loadProviders()
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader
      title="Send email"
      subtitle="Compose and queue up to 6,000 recipients in one send — optional schedule for later delivery"
    />

    <v-alert v-if="message" type="success" variant="tonal" class="mb-4">
      {{ message }}
      <div v-if="lastLogId" class="text-caption mt-1">
        <span v-if="queuedCount && queuedCount > 1">{{ queuedCount.toLocaleString() }} messages queued. </span>
        <span v-if="scheduledSendAt">Scheduled for {{ new Date(scheduledSendAt).toLocaleString() }}. </span>
        Log ID: {{ lastLogId }} —
        <router-link :to="{ name: 'logs' }">View email logs</router-link>
      </div>
    </v-alert>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>

    <ParentCard title="Compose email">
      <v-form @submit.prevent="sendMail">
        <v-row>
          <v-col cols="12" md="6" class="form-stack">
            <FormField label="To" required>
              <div class="d-flex flex-wrap ga-2 mb-2">
                <v-btn
                  size="small"
                  variant="tonal"
                  prepend-icon="mdi-file-delimited-outline"
                  :disabled="loading"
                  @click="csvInput?.click()"
                >
                  Import CSV / TXT
                </v-btn>
                <input
                  :key="csvInputKey"
                  ref="csvInput"
                  type="file"
                  class="d-none"
                  accept=".csv,.txt,text/csv,text/plain"
                  @change="onCsvPick"
                />
              </div>
              <v-textarea
                v-model="form.to"
                rows="4"
                auto-grow
                variant="outlined"
                hide-details
                :disabled="loading"
                placeholder="Paste one or many addresses (comma, semicolon, newline, or CSV)"
              />
              <div class="text-caption text-medium-emphasis mt-1">
                Paste from a spreadsheet or import a CSV/TXT file. Valid addresses are detected automatically.
              </div>
              <div class="mt-2">
                <div class="d-flex justify-space-between text-caption mb-1">
                  <span>{{ recipientCapacityLabel }}</span>
                  <span class="text-medium-emphasis">max {{ MAX_RECIPIENTS.toLocaleString() }} per schedule</span>
                </div>
                <v-progress-linear
                  :model-value="recipientProgress"
                  height="6"
                  rounded
                  :color="parsedTo.length > MAX_RECIPIENTS ? 'error' : parsedTo.length > MAX_RECIPIENTS * 0.85 ? 'warning' : 'primary'"
                />
              </div>
              <div v-if="parsedTo.length" class="recipient-chips mt-2">
                <v-chip
                  v-for="email in parsedTo.slice(0, 40)"
                  :key="email"
                  size="small"
                  variant="tonal"
                  closable
                  @click:close="removeRecipient(email)"
                >
                  {{ email }}
                </v-chip>
                <span v-if="parsedTo.length > 40" class="text-caption text-medium-emphasis">
                  +{{ (parsedTo.length - 40).toLocaleString() }} more
                </span>
              </div>
            </FormField>
            <FormField label="Subject" required>
              <v-text-field
                v-model="form.subject"
                variant="outlined"
                hide-details
                :disabled="loading"
              />
            </FormField>
            <FormField label="Email provider">
              <v-select
                v-model="form.provider_id"
                :items="providers"
                item-title="name"
                item-value="id"
                variant="outlined"
                hide-details
                :loading="loading"
                clearable
              />
            </FormField>
            <v-switch
              v-model="form.is_html"
              label="Send as HTML"
              color="primary"
              hide-details
              :disabled="loading"
            />
            <v-switch
              v-model="form.schedule_enabled"
              label="Schedule for later"
              color="primary"
              hide-details
              :disabled="loading"
            />
            <FormField v-if="form.schedule_enabled" label="Send at" required>
              <v-text-field
                v-model="form.send_at"
                type="datetime-local"
                :min="minScheduleLocal"
                variant="outlined"
                hide-details
                :disabled="loading"
              />
              <div class="text-caption text-medium-emphasis mt-1">
                Messages stay pending until this time (up to 30 days ahead). Uses your browser’s local timezone.
              </div>
            </FormField>
          </v-col>
          <v-col cols="12" md="6" class="form-stack">
            <FormField label="CC (paste multiple)">
              <v-textarea
                v-model="form.cc"
                rows="2"
                auto-grow
                variant="outlined"
                hide-details
                :disabled="loading"
                placeholder="Optional — up to 50 addresses"
              />
              <div v-if="parsedCc.length" class="text-caption text-medium-emphasis mt-1">
                {{ parsedCc.length }} CC address{{ parsedCc.length === 1 ? '' : 'es' }}
              </div>
            </FormField>
            <FormField label="BCC (paste multiple)">
              <v-textarea
                v-model="form.bcc"
                rows="2"
                auto-grow
                variant="outlined"
                hide-details
                :disabled="loading"
                placeholder="Optional — up to 50 addresses"
              />
              <div v-if="parsedBcc.length" class="text-caption text-medium-emphasis mt-1">
                {{ parsedBcc.length }} BCC address{{ parsedBcc.length === 1 ? '' : 'es' }}
              </div>
            </FormField>
            <FormField :label="form.is_html ? 'Message body' : 'Body'" required>
              <HtmlEditor
                v-if="form.is_html"
                :key="editorResetKey"
                v-model="form.body"
                :disabled="loading"
                placeholder="Write your HTML email…"
              />
              <v-textarea
                v-else
                v-model="form.body"
                rows="10"
                variant="outlined"
                hide-details
                :disabled="loading"
                placeholder="Plain text message"
              />
              <div v-if="form.is_html" class="text-caption text-medium-emphasis mt-1">
                Use the toolbar to format text. The message is sent as HTML.
              </div>
            </FormField>
            <FormField label="Attachments">
              <div class="attachment-picker">
                <v-btn
                  variant="tonal"
                  prepend-icon="mdi-paperclip"
                  :disabled="loading || attachmentFiles.length >= MAX_ATTACHMENTS"
                  @click="attachmentInput?.click()"
                >
                  Add files
                </v-btn>
                <input
                  :key="attachmentInputKey"
                  ref="attachmentInput"
                  type="file"
                  class="d-none"
                  multiple
                  @change="onAttachmentPick"
                />
                <div class="text-caption text-medium-emphasis">
                  Optional. Up to {{ MAX_ATTACHMENTS }} files, 5 MB each.
                </div>
                <div v-if="attachmentLabels.length" class="attachment-list">
                  <v-chip
                    v-for="(label, index) in attachmentLabels"
                    :key="`${label}-${index}`"
                    size="small"
                    variant="tonal"
                    closable
                    @click:close="removeAttachment(index)"
                  >
                    {{ label }}
                  </v-chip>
                </div>
              </div>
            </FormField>
          </v-col>
        </v-row>
        <div class="d-flex flex-column ga-4 mt-6">
          <CaptchaWidget
            :reset-key="captchaResetKey"
            @update:key="captchaKey = $event"
            @update:answer="captchaAnswer = $event"
          />
          <div class="d-flex ga-2">
            <v-btn color="primary" type="submit" :loading="sending" :disabled="loading">
              {{
                form.schedule_enabled
                  ? parsedTo.length > 1
                    ? `Schedule ${parsedTo.length.toLocaleString()} emails`
                    : 'Schedule email'
                  : parsedTo.length > 1
                    ? `Send to ${parsedTo.length.toLocaleString()} recipients`
                    : 'Send email'
              }}
            </v-btn>
          </div>
        </div>
      </v-form>
    </ParentCard>
  </div>
</template>

<style scoped>
.attachment-picker {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.attachment-list,
.recipient-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
</style>
