import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import vuetify from './plugins/vuetify'
import { applyBrandingTheme } from './lib/brandingTheme'
import { initThemePreference } from './lib/themePreference'
import { useAuthStore } from './stores/auth'
import { useBrandingStore } from './stores/branding'
import './assets/styles/main.scss'

async function bootstrap() {
  const pinia = createPinia()
  const branding = useBrandingStore(pinia)

  try {
    await branding.fetch()
  } catch {
    applyBrandingTheme(branding.branding)
  }

  const app = createApp(App)
  app.use(pinia)
  app.use(vuetify)
  initThemePreference()
  app.use(router)

  const auth = useAuthStore(pinia)
  await auth.bootstrap()

  app.mount('#app')
}

void bootstrap()
