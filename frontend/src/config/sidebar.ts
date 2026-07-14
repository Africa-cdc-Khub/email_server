export type SidebarItem = {
  header?: string
  title?: string
  icon?: string
  to?: { name: string } | string
  adminOnly?: boolean
}

export const sidebarItems: SidebarItem[] = [
  { header: 'Email Server' },
  { title: 'Dashboard', icon: 'mdi-view-dashboard-outline', to: { name: 'dashboard' } },
  { title: 'Email providers', icon: 'mdi-email-multiple-outline', to: { name: 'providers' } },
  { title: 'Integrations', icon: 'mdi-api', to: { name: 'integrations' } },
  { title: 'Send email', icon: 'mdi-email-edit-outline', to: { name: 'send-mail' } },
  { title: 'Email logs', icon: 'mdi-history', to: { name: 'logs' } },
  { title: 'Security', icon: 'mdi-shield-key-outline', to: { name: 'security' } },
  { header: 'Administration' },
  { title: 'Users', icon: 'mdi-account-group-outline', to: { name: 'users' }, adminOnly: true },
  { title: 'Branding', icon: 'mdi-palette-outline', to: { name: 'branding' }, adminOnly: true },
]
