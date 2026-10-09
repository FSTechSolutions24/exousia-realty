import { computed } from 'vue'
import { useAuthStore } from './stores/auth'

const messages = {
  en: { dashboard: 'Dashboard', leads: 'Leads', tasks: 'Tasks', inventory: 'Inventory', deals: 'Deals', reports: 'Reports', locations: 'Locations', team: 'Team', search: 'Search', newLead: 'New lead', overview: 'Overview', welcome: 'Good to see you', signOut: 'Sign out' },
  ar: { dashboard: 'لوحة التحكم', leads: 'العملاء', tasks: 'المهام', inventory: 'العقارات', deals: 'الصفقات', reports: 'التقارير', locations: 'المناطق', team: 'الفريق', search: 'بحث', newLead: 'عميل جديد', overview: 'نظرة عامة', welcome: 'أهلاً بعودتك', signOut: 'تسجيل الخروج' },
} as const

export function useI18n() {
  const auth = useAuthStore()
  const t = computed(() => messages[auth.locale])
  return { t }
}
