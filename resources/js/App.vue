<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import AppShell from './components/AppShell.vue'

const auth = useAuthStore(); const route = useRoute(); const router = useRouter()
onMounted(async () => {
  await auth.load()
  if (!auth.authenticated && !route.meta.guest) router.replace('/login')
  if (auth.authenticated && route.meta.guest && !route.meta.allowAuthenticated) router.replace('/')
})
</script>

<template>
  <div v-if="auth.loading" class="boot-screen"><div class="brand-mark">E</div><span>Preparing your workspace…</span></div>
  <router-view v-else-if="route.meta.guest" />
  <AppShell v-else-if="auth.authenticated"><router-view /></AppShell>
  <div v-else />
</template>
