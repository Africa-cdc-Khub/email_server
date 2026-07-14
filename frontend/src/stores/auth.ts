import { defineStore } from 'pinia'
import { api, getToken, setToken } from '@/lib/api'

export type AdminUser = {
  id: number
  name: string
  email: string
  is_admin: boolean
  is_active: boolean
  two_factor_email_enabled: boolean
  two_factor_totp_enabled: boolean
}

export type TwoFactorChallenge = {
  challengeToken: string
  methods: Array<'email' | 'totp'>
  email: string
}

export type TwoFactorStatus = {
  two_factor_email_enabled: boolean
  two_factor_totp_enabled: boolean
  has_recovery_codes: boolean
}

type LoginResult = { requires2fa: boolean }

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as AdminUser | null,
    bootstrapped: false,
    pending2fa: null as TwoFactorChallenge | null,
  }),
  getters: {
    isAuthenticated: (s) => s.user !== null,
    isAdmin: (s) => s.user?.is_admin === true,
  },
  actions: {
    async login(email: string, password: string): Promise<LoginResult> {
      const { data } = await api.post('/admin/auth/login', { email, password })

      if (data.requires_2fa) {
        this.pending2fa = {
          challengeToken: data.challenge_token,
          methods: data.methods,
          email: data.email,
        }

        return { requires2fa: true }
      }

      setToken(data.token)
      this.user = data.user
      this.pending2fa = null

      return { requires2fa: false }
    },
    async verify2fa(method: 'email' | 'totp', code: string) {
      if (!this.pending2fa) {
        throw new Error('No pending verification session.')
      }

      const { data } = await api.post('/admin/auth/verify-2fa', {
        challenge_token: this.pending2fa.challengeToken,
        method,
        code,
      })

      setToken(data.token)
      this.user = data.user
      this.pending2fa = null
    },
    async resend2faEmail() {
      if (!this.pending2fa) {
        throw new Error('No pending verification session.')
      }

      const { data } = await api.post<{ message: string }>('/admin/auth/resend-2fa-email', {
        challenge_token: this.pending2fa.challengeToken,
      })

      return data.message
    },
    clearPending2fa() {
      this.pending2fa = null
    },
    async fetchMe() {
      const { data } = await api.get<AdminUser>('/admin/auth/me')
      this.user = data
    },
    async fetch2faStatus() {
      const { data } = await api.get<{ data: TwoFactorStatus }>('/admin/auth/2fa/status')
      return data.data
    },
    async enableEmail2fa(password: string) {
      const { data } = await api.post<{ data: TwoFactorStatus; message: string }>(
        '/admin/auth/2fa/email/enable',
        { password },
      )
      if (this.user) {
        this.user.two_factor_email_enabled = data.data.two_factor_email_enabled
      }
      return data
    },
    async disableEmail2fa(password: string) {
      const { data } = await api.post<{ data: TwoFactorStatus; message: string }>(
        '/admin/auth/2fa/email/disable',
        { password },
      )
      if (this.user) {
        this.user.two_factor_email_enabled = data.data.two_factor_email_enabled
      }
      return data
    },
    async setupTotp(password: string) {
      const { data } = await api.post<{ data: { secret: string; otpauth_url: string } }>(
        '/admin/auth/2fa/totp/setup',
        { password },
      )
      return data.data
    },
    async confirmTotp(code: string) {
      const { data } = await api.post<{
        data: TwoFactorStatus & { recovery_codes?: string[] }
        message: string
      }>('/admin/auth/2fa/totp/confirm', { code })
      if (this.user) {
        this.user.two_factor_totp_enabled = data.data.two_factor_totp_enabled
      }
      return data
    },
    async disableTotp(password: string) {
      const { data } = await api.post<{ data: TwoFactorStatus; message: string }>(
        '/admin/auth/2fa/totp/disable',
        { password },
      )
      if (this.user) {
        this.user.two_factor_totp_enabled = data.data.two_factor_totp_enabled
      }
      return data
    },
    async logout() {
      try {
        await api.post('/admin/auth/logout')
      } finally {
        setToken(null)
        this.user = null
        this.pending2fa = null
      }
    },
    async forgotPassword(email: string) {
      const { data } = await api.post<{ message: string }>('/admin/auth/forgot-password', { email })
      return data.message
    },
    async resetPassword(payload: {
      email: string
      token: string
      password: string
      password_confirmation: string
    }) {
      const { data } = await api.post<{ message: string }>('/admin/auth/reset-password', payload)
      return data.message
    },
    async bootstrap() {
      if (!getToken()) {
        this.bootstrapped = true
        return
      }
      try {
        await this.fetchMe()
      } catch {
        setToken(null)
        this.user = null
      } finally {
        this.bootstrapped = true
      }
    },
  },
})
