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
const MAX_RECIPIENTS = 200
const EMAIL_RE = /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g

const providers = ref<ProviderOption[]>([])
const loading = ref(false)
const sending = ref(false)
const message = ref('')
const error = ref('')
const lastLogId = ref<number | null>(null)
const queuedCount = ref<number | null>(null)
const captchaKey = ref<string | null>(null)
const captchaAnswer = ref('')
const captchaResetKey = ref(0)
const attachmentFiles = ref<File[]>([])
const attachmentInputKey = ref(0)
const attachmentInput = ref<HTMLInputElement | null>(null)
const editorResetKey = ref(0)

const form = ref({
  to: '',
  subject: '',
  body: '',
  is_html: true,
  provider_id: null as number | null,
  cc: '',
  bcc: '',
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

const attachmentLabels = computed(() =>
  attachmentFiles.value.map((file) => `${file.name} (${formatBytes(file.size)})`),
)

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
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

  const recipients = parsedTo.value
  if (recipients.length === 0) {
    error.value = 'Add at least one valid email address in To.'
    sending.value = false
    return
  }
  if (recipients.length > MAX_RECIPIENTS) {
    error.value = `You can send to at most ${MAX_RECIPIENTS} recipients at once.`
    sending.value = false
    return
  }
  if (bodyIsEmpty(form.value.body)) {
    error.value = 'Message body is required.'
    sending.value = false
    return
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
    if (captchaKey.value) {
      payload.captcha_key = captchaKey.value
      payload.captcha = captchaAnswer.value
    }

    const res = await api.post('/admin/send-mail', payload)
    message.value = res.data.message ?? 'Email queued for delivery.'
    lastLogId.value = res.data.log_id ?? null
    queuedCount.value = res.data.queued_count ?? recipients.length
    form.value.to = ''
    form.value.subject = ''
    form.value.body = ''
    form.value.cc = ''
    form.value.bcc = ''
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
      subtitle="Compose and queue an email through the configured provider"
    />

    <v-alert v-if="message" type="success" variant="tonal" class="mb-4">
      {{ message }}
      <div v-if="lastLogId" class="text-caption mt-1">
        <span v-if="queuedCount && queuedCount > 1">{{ queuedCount }} messages queued. </span>
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
                Paste from a spreadsheet or CSV. Valid addresses are detected automatically.
                <span v-if="parsedTo.length"> — {{ parsedTo.length }} recipient{{ parsedTo.length === 1 ? '' : 's' }}</span>
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
                  +{{ parsedTo.length - 40 }} more
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
                placeholder="Optional — comma, semicolon, newline, or CSV"
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
                placeholder="Optional — comma, semicolon, newline, or CSV"
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
                parsedTo.length > 1
                  ? `Send to ${parsedTo.length} recipients`
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
