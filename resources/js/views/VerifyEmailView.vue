<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowRight, MailCheck } from '@lucide/vue'
import { api, prepareCsrf } from '../api'

const route = useRoute()
const email = ref(String(route.query.email || ''))
const loading = ref(false)
const error = ref('')
const message = ref(route.query.sent === '1' ? 'We sent a verification link. Open it to activate your workspace.' : '')

async function resend() {
  loading.value = true; error.value = ''; message.value = ''
  try {
    await prepareCsrf()
    const { data } = await api.post('/auth/email/verification-notification', { email: email.value })
    message.value = data.message
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to resend the verification link.'] }).flat().join(' ')
  } finally { loading.value = false }
}
</script>

<template><main class="register-page"><div class="register-brand"><div class="brand-mark">E</div><strong>EXOUSIA <span>REALTY</span></strong></div><section class="register-card"><div class="register-icon"><MailCheck /></div><span class="eyebrow">VERIFY YOUR EMAIL</span><h1>Check your inbox.</h1><p>Verify your email address before opening the workspace. If the link expires, request another one here.</p><form class="form-grid" @submit.prevent="resend"><label class="full">Work email<input v-model="email" type="email" autocomplete="email" required placeholder="you@company.com"></label><div v-if="message" class="team-success full">{{ message }}</div><div v-if="error" class="form-error full">{{ error }}</div><button class="button primary wide full" :disabled="loading">{{ loading ? 'Sending…' : 'Resend verification link' }}<ArrowRight :size="18" /></button></form><p class="auth-alt"><RouterLink to="/login">Back to sign in</RouterLink></p></section></main></template>
