<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { BadgeCheck, Building2, ChevronDown, CircleHelp, ClipboardCheck, ImagePlus, LayoutDashboard, MapPinned, Menu, Search, Settings, ShieldCheck, Sparkles, Users, WalletCards, X } from '@lucide/vue'
import { useAuthStore } from '../stores/auth'
import { useI18n } from '../i18n'
import { useTasksStore } from '../stores/tasks'

const auth = useAuthStore(); const tasks = useTasksStore(); const route = useRoute(); const router = useRouter(); const { t } = useI18n()
const mobileOpen = ref(false); const profileOpen = ref(false)
const nav = [
  ['/', LayoutDashboard, 'dashboard'], ['/leads', Users, 'leads'], ['/tasks', ClipboardCheck, 'tasks'],
  ['/inventory', Building2, 'inventory'], ['/deals', WalletCards, 'deals'], ['/commissions', BadgeCheck, 'commissions'], ['/reports', Sparkles, 'reports'], ['/settings/locations', MapPinned, 'locations'], ['/settings/developers', Building2, 'developers'], ['/settings/company', ImagePlus, 'company'], ['/team', Settings, 'team'], ['/billing', WalletCards, 'billing'],
] as const
const canViewReports = computed(() => ['owner', 'admin', 'manager', 'finance', 'agent'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.some(permission => ['view_reports', 'view_own_reports'].includes(permission)))
const canViewCommissions = computed(() => ['owner', 'admin', 'manager', 'finance', 'agent'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.some(permission => ['view_commissions', 'view_own_commissions'].includes(permission)))
const canViewInventory = computed(() => ['owner', 'admin', 'manager', 'agent', 'operations'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('view_inventory'))
const canManageSettings = computed(() => ['owner', 'admin'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('manage_settings'))
const visibleNav = computed(() => nav.filter(([path]) => path !== '/reports' || canViewReports.value).filter(([path]) => path !== '/commissions' || canViewCommissions.value).filter(([path]) => path !== '/settings/developers' || canViewInventory.value).filter(([path]) => path !== '/settings/company' || canManageSettings.value))
const groups = [
  { id: 'workspace', title: 'WORKSPACE', paths: ['/', '/tasks'] },
  { id: 'sales', title: 'SALES & CLIENTS', paths: ['/leads', '/deals', '/commissions'] },
  { id: 'property', title: 'PROPERTY PORTFOLIO', paths: ['/inventory', '/settings/developers', '/settings/locations'] },
  { id: 'manage', title: 'COMPANY & INSIGHTS', paths: ['/reports', '/team', '/settings/company', '/billing'] },
] as const
const expandedGroup = ref('workspace')
const visibleGroups = computed(() => groups.map(group => ({ ...group, items: visibleNav.value.filter(([path]) => (group.paths as readonly string[]).includes(path)) })).filter(group => group.items.length))
watch(() => [route.path, visibleGroups.value.map(group => group.id).join('|')], ([path]) => {
  const group = visibleGroups.value.find(item => item.items.some(([itemPath]) => itemPath === '/' ? path === '/' : path.startsWith(itemPath)))
  if (group) expandedGroup.value = group.id
}, { immediate: true })
function toggleGroup(id: string) { expandedGroup.value = expandedGroup.value === id ? '' : id }
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
        <span class="workspace-avatar"><img v-if="auth.bootstrap?.company.logo_url" :src="auth.bootstrap.company.logo_url" :alt="`${auth.bootstrap.company.name} logo`"><span v-else>{{ auth.bootstrap?.company.name.charAt(0) }}</span></span>
        <span><small>WORKSPACE</small><strong>{{ auth.bootstrap?.company.name }}</strong></span>
        <ChevronDown :size="15" />
      </div>
      <nav class="main-nav">
        <section v-for="group in visibleGroups" :key="group.id" class="nav-group">
          <button class="nav-group-toggle" type="button" :aria-expanded="expandedGroup === group.id" :aria-controls="`nav-group-${group.id}`" @click="toggleGroup(group.id)">
            <span>{{ group.title }}</span><ChevronDown :size="14" :class="{ rotated: expandedGroup === group.id }" />
          </button>
          <div v-if="expandedGroup === group.id" :id="`nav-group-${group.id}`" class="nav-group-links">
            <RouterLink v-for="([path, Icon, key]) in group.items" :key="path" :to="path" :class="{ active: isActive(path) }" @click="mobileOpen=false">
              <component :is="Icon" :size="19" /><span>{{ key === 'billing' ? (auth.locale === 'ar' ? 'الفواتير' : 'Plans & billing') : t[key] }}</span><i v-if="key === 'tasks' && tasks.openCount !== null && tasks.openCount > 0">{{ tasks.openCount > 99 ? '99+' : tasks.openCount }}</i>
            </RouterLink>
          </div>
        </section>
        <span v-if="auth.platformAdmin" class="nav-label platform-nav-separator">PLATFORM OWNER</span>
        <RouterLink v-if="auth.platformAdmin" to="/platform" :class="{ active: isActive('/platform') }" @click="mobileOpen=false"><ShieldCheck :size="19"/><span>Platform management</span></RouterLink>
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
