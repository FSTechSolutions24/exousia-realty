<script setup lang="ts">
import { ref } from 'vue'
import { ArrowRight, KeyRound } from '@lucide/vue'
import { api, prepareCsrf } from '../api'

const email = ref('')
const loading = ref(false)
const error = ref('')
const sent = ref(false)

async function submit() {
  loading.value = true; error.value = ''; sent.value = false
  try {
    await prepareCsrf()
    await api.post('/auth/password/forgot', { email: email.value })
    sent.value = true
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to request a reset link.'] }).flat().join(' ')
  } finally { loading.value = false }
}
</script>

<template><main class="register-page"><div class="register-brand"><div class="brand-mark">E</div><strong>EXOUSIA <span>REALTY</span></strong></div><section class="register-card"><div class="register-icon"><KeyRound /></div><span class="eyebrow">ACCOUNT RECOVERY</span><h1>Reset your password.</h1><p>Enter the email address on your account. If it exists, we’ll send a secure reset link.</p><form class="form-grid" @submit.prevent="submit"><label class="full">Work email<input v-model="email" type="email" autocomplete="email" required placeholder="you@company.com"></label><div v-if="sent" class="team-success full">If an account exists for that email, a password reset link has been sent.</div><div v-if="error" class="form-error full">{{ error }}</div><button class="button primary wide full" :disabled="loading">{{ loading ? 'Sending…' : 'Send reset link' }}<ArrowRight :size="18" /></button></form><p class="auth-alt"><RouterLink to="/login">Back to sign in</RouterLink></p></section></main></template>
