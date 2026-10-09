import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, prepareCsrf } from '../api'
import type { Bootstrap } from '../types'

export const useAuthStore = defineStore('auth', () => {
  const bootstrap = ref<Bootstrap | null>(null)
  const loading = ref(true)
  const locale = ref<'en' | 'ar'>((localStorage.getItem('exousia_locale') as 'en' | 'ar') || 'en')
  const authenticated = computed(() => Boolean(bootstrap.value?.user))

  function applyDirection() {
    document.documentElement.lang = locale.value
    document.documentElement.dir = locale.value === 'ar' ? 'rtl' : 'ltr'
  }
  function toggleLocale() {
    locale.value = locale.value === 'en' ? 'ar' : 'en'
    localStorage.setItem('exousia_locale', locale.value)
    applyDirection()
  }
  async function load() {
    loading.value = true
    try {
      const { data } = await api.get<Bootstrap>('/bootstrap')
      bootstrap.value = data
      localStorage.setItem('exousia_company_id', String(data.company.id))
    } catch { bootstrap.value = null }
    finally { loading.value = false; applyDirection() }
  }
  async function login(email: string, password: string) {
    await prepareCsrf(); await api.post('/auth/login', { email, password }); await load()
  }
  async function register(payload: Record<string, string>) {
    await prepareCsrf(); return api.post('/auth/register', payload)
  }
  async function logout() {
    await api.post('/auth/logout'); bootstrap.value = null; localStorage.removeItem('exousia_company_id')
  }
  async function switchCompany(id: number) {
    await api.post(`/companies/${id}/switch`); localStorage.setItem('exousia_company_id', String(id)); await load()
  }
  return { bootstrap, loading, locale, authenticated, load, login, register, logout, switchCompany, toggleLocale }
})
