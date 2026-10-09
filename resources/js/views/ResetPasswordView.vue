<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, KeyRound } from '@lucide/vue'
import { api, prepareCsrf } from '../api'

const route = useRoute()
const router = useRouter()
const form = reactive({ password: '', password_confirmation: '' })
const loading = ref(false)
const error = ref('')
const email = String(route.query.email || '')
const token = String(route.query.token || '')

async function submit() {
  loading.value = true; error.value = ''
  try {
    await prepareCsrf()
    await api.post('/auth/password/reset', { email, token, ...form })
    router.replace('/login?reset=1')
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to reset the password. Request a new link and try again.'] }).flat().join(' ')
  } finally { loading.value = false }
}
</script>

<template><main class="register-page"><div class="register-brand"><div class="brand-mark">E</div><strong>EXOUSIA <span>REALTY</span></strong></div><section class="register-card"><div class="register-icon"><KeyRound /></div><span class="eyebrow">ACCOUNT RECOVERY</span><h1>Choose a new password.</h1><p>Use at least eight characters, including letters and numbers.</p><form class="form-grid" @submit.prevent="submit"><label class="full">New password<input v-model="form.password" type="password" autocomplete="new-password" minlength="8" required></label><label class="full">Confirm password<input v-model="form.password_confirmation" type="password" autocomplete="new-password" required></label><div v-if="error" class="form-error full">{{ error }}</div><button class="button primary wide full" :disabled="loading || !token || !email">{{ loading ? 'Saving…' : 'Reset password' }}<ArrowRight :size="18" /></button></form><p class="auth-alt"><RouterLink to="/login">Back to sign in</RouterLink></p></section></main></template>
