<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormField from '@/components/forms/FormField.vue'
import ParentCard from '@/components/shared/ParentCard.vue'
import PageHeader from '@/components/shared/PageHeader.vue'
import { api } from '@/lib/api'

const route = useRoute()
const router = useRouter()
const isEdit = computed(() => route.name === 'user-edit')
const id = computed(() => route.params.id as string | undefined)

const loading = ref(false)
const saving = ref(false)

const form = ref({
  name: '',
  email: '',
  password: '',
  is_admin: false,
  is_active: true,
})

async function loadUser() {
  if (!isEdit.value || !id.value) return
  const res = await api.get(`/admin/users/${id.value}`)
  const u = res.data.data
  form.value = {
    name: u.name,
    email: u.email,
    password: '',
    is_admin: u.is_admin,
    is_active: u.is_active,
  }
}

async function save() {
  saving.value = true
  try {
    const payload: Record<string, unknown> = { ...form.value }
    if (isEdit.value && !payload.password) delete payload.password

    if (isEdit.value && id.value) {
      await api.put(`/admin/users/${id.value}`, payload)
    } else {
      await api.post('/admin/users', payload)
    }
    await router.push({ name: 'users' })
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  loading.value = true
  await loadUser()
  loading.value = false
})
</script>

<template>
  <div>
    <PageHeader :title="isEdit ? 'Edit user' : 'New user'" />

    <ParentCard :title="isEdit ? 'Update account' : 'Create account'">
      <v-form @submit.prevent="save">
        <v-row>
          <v-col cols="12" md="6" class="form-stack">
            <FormField label="Full name" required>
              <v-text-field v-model="form.name" variant="outlined" hide-details />
            </FormField>
            <FormField label="Email" required>
              <v-text-field v-model="form.email" type="email" variant="outlined" hide-details />
            </FormField>
            <FormField :label="isEdit ? 'New password (optional)' : 'Password'" :required="!isEdit">
              <v-text-field
                v-model="form.password"
                type="password"
                variant="outlined"
                hide-details
                hint="Minimum 12 characters with mixed case and numbers"
              />
            </FormField>
          </v-col>
          <v-col cols="12" md="6" class="d-flex flex-column justify-center ga-4">
            <v-switch v-model="form.is_admin" label="Administrator" color="primary" hide-details />
            <v-switch v-model="form.is_active" label="Active" color="success" hide-details />
          </v-col>
        </v-row>
        <div class="d-flex ga-2 mt-6">
          <v-btn color="primary" type="submit" :loading="saving">Save</v-btn>
          <v-btn variant="text" :to="{ name: 'users' }">Cancel</v-btn>
        </div>
      </v-form>
    </ParentCard>
  </div>
</template>
