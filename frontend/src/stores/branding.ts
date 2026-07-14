import { defineStore } from 'pinia'
import { api } from '@/lib/api'
import { applyBrandingTheme } from '@/lib/brandingTheme'

export type Branding = {
  app_name: string
  tagline: string | null
  logo_url: string | null
  logo_dark_url: string | null
  admin_logo_inverse: boolean
  admin_logo_size_percent: number
  favicon_url: string | null
  primary_color: string
  secondary_color: string
  support_email: string | null
}

const fallback: Branding = {
  app_name: 'Email Server',
  tagline: null,
  logo_url: '/branding-logo.png',
  logo_dark_url: '/branding-logo.png',
  admin_logo_inverse: false,
  admin_logo_size_percent: 100,
  favicon_url: null,
  primary_color: '#0d7a3a',
  secondary_color: '#c9a227',
  support_email: null,
}

export const useBrandingStore = defineStore('branding', {
  state: () => ({
    branding: { ...fallback } as Branding,
    loaded: false,
  }),
  actions: {
    async fetch() {
      try {
        const { data } = await api.get<{ data: Branding }>('/branding')
        this.branding = { ...fallback, ...data.data }
        applyBrandingTheme(this.branding)
      } catch {
        this.branding = { ...fallback }
        applyBrandingTheme(this.branding)
      } finally {
        this.loaded = true
      }
    },
    async update(form: FormData) {
      const { data } = await api.post('/admin/branding', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      this.branding = { ...fallback, ...data.data }
      applyBrandingTheme(this.branding)
      return data
    },
  },
})
