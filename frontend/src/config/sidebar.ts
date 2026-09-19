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
  { title: 'Email providers', icon: 'mdi-email-multiple-outline', to: { name: 'providers' }, adminOnly: true },
  { title: 'Integrations', icon: 'mdi-api', to: { name: 'integrations' } },
  { title: 'Send email', icon: 'mdi-email-edit-outline', to: { name: 'send-mail' }, adminOnly: true },
  { title: 'Email logs', icon: 'mdi-history', to: { name: 'logs' } },
  { title: 'Security', icon: 'mdi-shield-key-outline', to: { name: 'security' } },
  { header: 'Administration' },
  { title: 'Users', icon: 'mdi-account-group-outline', to: { name: 'users' }, adminOnly: true },
  { title: 'Audit logs', icon: 'mdi-clipboard-text-clock-outline', to: { name: 'audit-logs' }, adminOnly: true },
  { title: 'Blocked access', icon: 'mdi-cancel', to: { name: 'blocked-ips' }, adminOnly: true },
  { title: 'Branding', icon: 'mdi-palette-outline', to: { name: 'branding' }, adminOnly: true },
  { title: 'Backup / Restore', icon: 'mdi-database-export-outline', to: { name: 'backup' }, adminOnly: true },
]
