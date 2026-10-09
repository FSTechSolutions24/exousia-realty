<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { BadgeCheck, Building2, ChevronDown, CircleHelp, ClipboardCheck, LayoutDashboard, MapPinned, Menu, Search, Settings, Sparkles, Users, WalletCards, X } from '@lucide/vue'
import { useAuthStore } from '../stores/auth'
import { useI18n } from '../i18n'
import { useTasksStore } from '../stores/tasks'

const auth = useAuthStore(); const tasks = useTasksStore(); const route = useRoute(); const router = useRouter(); const { t } = useI18n()
const mobileOpen = ref(false); const profileOpen = ref(false)
const nav = [
  ['/', LayoutDashboard, 'dashboard'], ['/leads', Users, 'leads'], ['/tasks', ClipboardCheck, 'tasks'],
  ['/inventory', Building2, 'inventory'], ['/deals', WalletCards, 'deals'], ['/commissions', BadgeCheck, 'commissions'], ['/reports', Sparkles, 'reports'], ['/settings/locations', MapPinned, 'locations'], ['/team', Settings, 'team'],
] as const
const canViewReports = computed(() => ['owner', 'admin', 'manager', 'finance', 'agent'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.some(permission => ['view_reports', 'view_own_reports'].includes(permission)))
const canViewCommissions = computed(() => ['owner', 'admin', 'manager', 'finance', 'agent'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.some(permission => ['view_commissions', 'view_own_commissions'].includes(permission)))
const visibleNav = computed(() => nav.filter(([path]) => path !== '/reports' || canViewReports.value).filter(([path]) => path !== '/commissions' || canViewCommissions.value))
const companyId = computed(() => auth.bootstrap?.company.id)
function isActive(path: string) { return path === '/' ? route.path === '/' : route.path.startsWith(path) }
async function logout() { await auth.logout(); router.replace('/login') }
let refreshTimer: ReturnType<typeof setInterval> | undefined
function refreshWhenVisible() { if (document.visibilityState === 'visible') void tasks.refresh(true) }
watch(companyId, (id) => { if (id) void tasks.refresh(true); else tasks.reset() }, { immediate: true })
onMounted(() => {
  refreshTimer = setInterval(() => void tasks.refresh(true), 30_000)
  window.addEventListener('focus', refreshWhenVisible)
  document.addEventListener('visibilitychange', refreshWhenVisible)
})
onUnmounted(() => {
  if (refreshTimer) clearInterval(refreshTimer)
  window.removeEventListener('focus', refreshWhenVisible)
  document.removeEventListener('visibilitychange', refreshWhenVisible)
})
</script>

<template>
  <div class="app-frame">
    <div v-if="mobileOpen" class="mobile-scrim" @click="mobileOpen = false" />
    <aside class="sidebar" :class="{ open: mobileOpen }">
      <div class="sidebar-brand"><div class="brand-mark">E</div><div><strong>EXOUSIA</strong><small>REALTY</small></div><button class="icon-button mobile-only" @click="mobileOpen=false"><X :size="19" /></button></div>
      <div class="workspace-card">
        <span class="workspace-avatar">{{ auth.bootstrap?.company.name.charAt(0) }}</span>
        <span><small>WORKSPACE</small><strong>{{ auth.bootstrap?.company.name }}</strong></span>
        <ChevronDown :size="15" />
      </div>
      <nav class="main-nav">
        <span class="nav-label">{{ auth.locale === 'ar' ? 'مساحة العمل' : 'WORKSPACE' }}</span>
        <RouterLink v-for="([path, Icon, key]) in visibleNav" :key="path" :to="path" :class="{ active: isActive(path) }" @click="mobileOpen=false">
          <component :is="Icon" :size="19" /><span>{{ t[key] }}</span><i v-if="key === 'tasks' && tasks.openCount !== null && tasks.openCount > 0">{{ tasks.openCount > 99 ? '99+' : tasks.openCount }}</i>
        </RouterLink>
      </nav>
      <div class="sidebar-support"><CircleHelp :size="20" /><div><strong>{{ auth.locale === 'ar' ? 'تحتاج للمساعدة؟' : 'Need a hand?' }}</strong><small>{{ auth.locale === 'ar' ? 'راجع دليل البدء' : 'Visit the getting started guide' }}</small></div></div>
      <div class="sidebar-footer">Exousia Realty <span>v0.1</span></div>
    </aside>

    <section class="workspace">
      <header class="topbar">
        <button class="icon-button mobile-only" @click="mobileOpen=true"><Menu :size="20" /></button>
        <div class="global-search"><Search :size="18" /><input :placeholder="`${t.search} leads, properties, tasks…`"></div>
        <div class="top-actions">
          <button class="language-button" @click="auth.toggleLocale">{{ auth.locale === 'en' ? 'عربي' : 'EN' }}</button>
          <button class="profile-trigger" @click="profileOpen=!profileOpen">
            <span class="user-avatar">{{ auth.bootstrap?.user.name.split(' ').map(n=>n[0]).join('').slice(0,2) }}</span>
            <span class="profile-copy"><strong>{{ auth.bootstrap?.user.name }}</strong><small>{{ auth.bootstrap?.membership.role }}</small></span><ChevronDown :size="15" />
          </button>
          <div v-if="profileOpen" class="profile-menu"><button @click="logout">{{ t.signOut }}</button></div>
        </div>
      </header>
      <main class="page-content"><slot /></main>
    </section>
  </div>
</template>
