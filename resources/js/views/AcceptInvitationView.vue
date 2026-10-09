<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, UserPlus } from '@lucide/vue'
import { api, prepareCsrf } from '../api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const loading = ref(false)
const error = ref('')
const form = reactive({ password: '', password_confirmation: '' })
const token = computed(() => String(route.query.token || ''))

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await prepareCsrf()
    await api.post('/auth/invitations/accept', { token: token.value, ...(form.password ? form : {}) })
    await auth.load()
    router.replace('/')
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'This invitation could not be accepted.'] }).flat().join(' ')
  } finally { loading.value = false }
}
</script>

<template>
  <main class="register-page"><div class="register-brand"><div class="brand-mark">E</div><strong>EXOUSIA <span>REALTY</span></strong></div><section class="register-card"><div class="register-icon"><UserPlus /></div><span class="eyebrow">WORKSPACE INVITATION</span><h1>Join your team.</h1><p>Set a password if you’re new to Exousia. If you already have an account, continue to join the workspace.</p><form class="form-grid" @submit.prevent="submit"><label class="full">Create password<input v-model="form.password" type="password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters with letters and numbers"></label><label class="full">Confirm password<input v-model="form.password_confirmation" type="password" autocomplete="new-password" placeholder="Repeat your password"></label><div v-if="error" class="form-error full">{{ error }}</div><button class="button primary wide full" :disabled="loading || !token">{{ loading ? 'Joining…' : 'Accept invitation' }}<ArrowRight :size="18" /></button></form><p class="auth-alt"><RouterLink to="/login">Already signed in? Go to sign in</RouterLink></p></section></main>
</template>
