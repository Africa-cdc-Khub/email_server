import { createRouter, createWebHistory } from 'vue-router'
import { getToken } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true } },
    { path: '/verify-2fa', name: 'verify-2fa', component: () => import('@/views/Verify2FAView.vue'), meta: { guest: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('@/views/ForgotPasswordView.vue'), meta: { guest: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('@/views/ResetPasswordView.vue'), meta: { guest: true } },
    {
      path: '/',
      component: () => import('@/layouts/AdminLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
        { path: 'providers', name: 'providers', component: () => import('@/views/ProvidersView.vue') },
        { path: 'providers/new', name: 'provider-new', component: () => import('@/views/ProviderFormView.vue') },
        { path: 'providers/:id/edit', name: 'provider-edit', component: () => import('@/views/ProviderFormView.vue') },
        { path: 'integrations', name: 'integrations', component: () => import('@/views/IntegrationsView.vue') },
        { path: 'integrations/new', name: 'integration-new', component: () => import('@/views/IntegrationFormView.vue') },
        { path: 'integrations/:id/edit', name: 'integration-edit', component: () => import('@/views/IntegrationFormView.vue') },
        { path: 'send-mail', name: 'send-mail', component: () => import('@/views/SendMailView.vue') },
        { path: 'logs', name: 'logs', component: () => import('@/views/EmailLogsView.vue') },
        {
          path: 'users',
          name: 'users',
          component: () => import('@/views/users/UsersListView.vue'),
          meta: { requiresAdmin: true },
        },
        {
          path: 'users/new',
          name: 'user-new',
          component: () => import('@/views/users/UserFormView.vue'),
          meta: { requiresAdmin: true },
        },
        {
          path: 'users/:id/edit',
          name: 'user-edit',
          component: () => import('@/views/users/UserFormView.vue'),
          meta: { requiresAdmin: true },
        },
        {
          path: 'branding',
          name: 'branding',
          component: () => import('@/views/BrandingView.vue'),
          meta: { requiresAdmin: true },
        },
        {
          path: 'security',
          name: 'security',
          component: () => import('@/views/SecuritySettingsView.vue'),
        },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  const authed = !!getToken()
  const auth = useAuthStore()

  if (to.name === 'verify-2fa') {
    if (!auth.pending2fa) return { name: 'login' }
    return
  }

  if (to.meta.requiresAuth && !authed) return { name: 'login' }
  if (to.meta.guest && authed) return { name: 'dashboard' }

  if (to.meta.requiresAdmin && authed) {
    const auth = useAuthStore()
    if (!auth.user) {
      try {
        await auth.fetchMe()
      } catch {
        return { name: 'login' }
      }
    }
    if (!auth.user?.is_admin) return { name: 'dashboard' }
  }
})

export default router
