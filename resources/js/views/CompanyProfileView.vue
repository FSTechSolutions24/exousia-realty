<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { Building2, ImagePlus, RotateCcw, Trash2, Upload, X } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const fileInput = ref<HTMLInputElement | null>(null)
const selectedFile = ref<File | null>(null)
const previewUrl = ref('')
const saving = ref(false)
const error = ref('')
const success = ref('')
const canManage = computed(() => ['owner', 'admin'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('manage_settings'))
const displayLogo = computed(() => previewUrl.value || auth.bootstrap?.company.logo_url || '')

function chooseLogo(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0] || null
  selectedFile.value = file
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = file ? URL.createObjectURL(file) : ''
  error.value = ''
  success.value = ''
}

function clearSelection() {
  selectedFile.value = null
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
  if (fileInput.value) fileInput.value.value = ''
}

async function upload() {
  if (!selectedFile.value) return
  saving.value = true
  error.value = ''
  success.value = ''
  const body = new FormData()
  body.append('logo', selectedFile.value)
  try {
    await api.post('/company/logo', body)
    clearSelection()
    await auth.load()
    success.value = 'Company logo updated. It now appears in your workspace sidebar and printed deal statements.'
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to upload the company logo.'] }).flat().join(' ')
  } finally { saving.value = false }
}

async function removeLogo() {
  if (!auth.bootstrap?.company.logo_url) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await api.delete('/company/logo')
    await auth.load()
    success.value = 'Company logo removed.'
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to remove the company logo.'
  } finally { saving.value = false }
}

onBeforeUnmount(() => { if (previewUrl.value) URL.revokeObjectURL(previewUrl.value) })
</script>

<template>
  <div class="company-profile-page">
    <header class="page-heading"><div><span class="eyebrow">WORKSPACE IDENTITY</span><h1>Company profile</h1><p>Make the brokerage workspace and its printed documents feel like your company.</p></div></header>
    <div v-if="error" class="detail-alert">{{ error }}<button @click="error = ''">Dismiss</button></div><div v-if="success" class="team-success branding-success">{{ success }}</div>
    <section class="company-profile-grid">
      <article class="panel company-identity-card"><span class="eyebrow">BROKERAGE</span><div class="company-identity"><img v-if="displayLogo" :src="displayLogo" :alt="`${auth.bootstrap?.company.name} logo preview`"><span v-else class="company-identity-placeholder"><Building2 /></span><div><h2>{{ auth.bootstrap?.company.name }}</h2><small>Company workspace</small></div></div><p>Your company logo is shown in the workspace sidebar and on printable deal closing statements.</p></article>
      <article class="panel company-logo-panel"><header><div class="branding-icon"><ImagePlus /></div><div><h2>Company logo</h2><p>Use a clear, square or horizontal mark with a transparent or simple background.</p></div></header>
        <div class="logo-upload-preview"><img v-if="displayLogo" :src="displayLogo" alt="Selected company logo"><span v-else><Building2 :size="30" /><small>Logo preview</small></span></div>
        <div v-if="canManage" class="logo-actions"><input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" @change="chooseLogo"><button class="button secondary" :disabled="saving" @click="fileInput?.click()"><ImagePlus :size="16" />{{ displayLogo ? 'Choose another' : 'Choose logo' }}</button><button v-if="auth.bootstrap?.company.logo_url && !previewUrl" class="button danger" :disabled="saving" @click="removeLogo"><Trash2 :size="15" />Remove</button><button v-if="selectedFile" class="button primary" :disabled="saving" @click="upload"><Upload :size="15" />{{ saving ? 'Uploading…' : 'Save logo' }}</button><button v-if="selectedFile" class="icon-button" aria-label="Discard selected logo" @click="clearSelection"><X :size="16" /></button></div>
        <div v-if="canManage" class="logo-guidance"><strong>File guidelines</strong><span>JPEG, PNG, or WebP</span><span>Up to 2 MB</span><span>Stored privately and visible only to active workspace members</span></div>
        <p v-else class="logo-readonly"><RotateCcw :size="15" />Only company owners and admins can change workspace branding.</p>
      </article>
    </section>
  </div>
</template>
