<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import AppShell from './components/AppShell.vue'

const auth = useAuthStore(); const route = useRoute(); const router = useRouter()
async function routeByAccess() {
  if (auth.loading) return
  if (!auth.authenticated && !route.meta.guest && !route.meta.public) { await router.replace('/login'); return }
  if (!auth.authenticated) return
  if (auth.platformAdmin) {
    if (route.meta.guest && !route.meta.allowAuthenticated) { await router.replace('/platform'); return }
    if (!route.meta.public && route.path !== '/platform') { await router.replace('/platform'); return }
    return
  }
  if (route.path === '/platform') { await router.replace(auth.bootstrap?.subscription.active ? '/dashboard' : '/billing'); return }
  if (route.meta.guest && !route.meta.allowAuthenticated) { await router.replace(auth.bootstrap?.subscription.active ? '/dashboard' : '/billing'); return }
  if (auth.bootstrap?.company.is_suspended && route.path !== '/billing') { await router.replace('/billing'); return }
  if (!route.meta.public && route.path !== '/billing' && !auth.bootstrap?.subscription.active) await router.replace('/billing')
}
onMounted(async () => {
  await auth.load()
  await routeByAccess()
})
watch(() => route.path, () => { void routeByAccess() })
watch(() => auth.bootstrap?.subscription.active, () => { void routeByAccess() })
</script>

<template>
  <div v-if="auth.loading" class="boot-screen"><div class="brand-mark">E</div><span>Preparing your workspace…</span></div>
  <router-view v-else-if="route.meta.guest || route.meta.public" />
  <router-view v-else-if="route.path === '/platform' && auth.platformAdmin" />
  <AppShell v-else-if="auth.authenticated && auth.bootstrap"><router-view /></AppShell>
  <div v-else />
</template>
