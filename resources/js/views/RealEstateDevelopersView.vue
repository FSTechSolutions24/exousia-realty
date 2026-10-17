<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { AtSign, Building2, Globe2, Mail, Pencil, Phone, Plus, Search, ShieldCheck, UserRound, X } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'
import Modal from '../components/Modal.vue'

interface Developer { id: number; name: string; contact_name: string | null; email: string | null; phone: string | null; website: string | null; notes: string | null; is_active: boolean; listings_count: number }
const auth = useAuthStore()
const developers = ref<Developer[]>([])
const pagination = ref<any>({})
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const search = ref('')
const activeFilter = ref('')
const page = ref(1)
const showEditor = ref(false)
const editingId = ref<number | null>(null)
const form = reactive({ name: '', contact_name: '', email: '', phone: '', website: '', notes: '', is_active: true })
let searchTimer: number | undefined
const canManage = computed(() => ['owner', 'admin'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('manage_settings'))
const activeCount = computed(() => developers.value.filter(developer => developer.is_active).length)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params: Record<string, string | number> = { search: search.value, page: page.value, per_page: 20 }
    if (activeFilter.value) params.active = activeFilter.value === 'active' ? 1 : 0
    const { data } = await api.get('/developers', { params })
    developers.value = data.data
    pagination.value = data
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to load real estate developers.'
  } finally { loading.value = false }
}

function openCreate() {
  editingId.value = null
  Object.assign(form, { name: '', contact_name: '', email: '', phone: '', website: '', notes: '', is_active: true })
  error.value = ''
  showEditor.value = true
}

function openEdit(developer: Developer) {
  editingId.value = developer.id
  Object.assign(form, { name: developer.name, contact_name: developer.contact_name || '', email: developer.email || '', phone: developer.phone || '', website: developer.website || '', notes: developer.notes || '', is_active: developer.is_active })
  error.value = ''
  showEditor.value = true
}

async function save() {
  saving.value = true
  error.value = ''
  const payload = { ...form, contact_name: form.contact_name || null, email: form.email || null, phone: form.phone || null, website: form.website || null, notes: form.notes || null }
  try {
    editingId.value ? await api.put(`/developers/${editingId.value}`, payload) : await api.post('/developers', payload)
    showEditor.value = false
    await load()
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to save this developer.'] }).flat().join(' ')
  } finally { saving.value = false }
}

async function toggle(developer: Developer) {
  if (developer.is_active && !window.confirm(`Deactivate ${developer.name}? Properties already linked to this developer will keep their association.`)) return
  try {
    if (developer.is_active) await api.delete(`/developers/${developer.id}`)
    else await api.put(`/developers/${developer.id}`, { is_active: true })
    await load()
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to update developer status.'
  }
}

watch(search, () => { window.clearTimeout(searchTimer); page.value = 1; searchTimer = window.setTimeout(load, 250) })
watch(activeFilter, () => { page.value = 1; load() })
watch(page, load)
onMounted(load)
</script>

<template>
  <div class="developer-page">
    <header class="page-heading"><div><span class="eyebrow">YOUR PARTNER NETWORK</span><h1>Real estate developers</h1><p>Keep the builders behind your inventory close at hand.</p></div><button v-if="canManage" class="button primary" @click="openCreate"><Plus :size="18" />Add developer</button></header>
    <div v-if="error && !showEditor" class="detail-alert">{{ error }}<button @click="error = ''">Dismiss</button></div>
    <section class="developer-summary"><article><span class="developer-summary-icon"><Building2 /></span><div><strong>{{ pagination.total || 0 }}</strong><small>Developer partners</small></div></article><article><span class="developer-summary-icon active"><ShieldCheck /></span><div><strong>{{ activeCount }}</strong><small>Active relationships on this page</small></div></article></section>
    <section class="panel developer-panel">
      <header class="developer-toolbar"><div><h2>Developer directory</h2><p>Search names and contact details; link a developer when maintaining a property.</p></div><div class="developer-filters"><select v-model="activeFilter" aria-label="Filter developers by status"><option value="">All partners</option><option value="active">Active</option><option value="inactive">Inactive</option></select><label class="search-box"><Search :size="17" /><input v-model="search" placeholder="Search developers or contacts…"><button v-if="search" type="button" aria-label="Clear search" @click="search = ''"><X :size="15" /></button></label></div></header>
      <div v-if="loading" class="developer-loading"><i v-for="item in 6" :key="item" /></div>
      <div v-else-if="!developers.length" class="developer-empty"><Building2 /><strong>{{ search ? 'No developers match that search' : 'Your partner network starts here' }}</strong><span>{{ search ? 'Try a name, phone number, or contact email.' : 'Add the real estate developers your brokerage works with, then connect them to listings.' }}</span><button v-if="!search && canManage" class="button secondary" @click="openCreate"><Plus :size="15" />Add your first developer</button></div>
      <div v-else class="developer-grid">
        <article v-for="developer in developers" :key="developer.id" class="developer-card" :class="{ inactive: !developer.is_active }">
          <header><span class="developer-mark"><Building2 :size="19" /></span><span class="developer-state" :class="{ inactive: !developer.is_active }"><i />{{ developer.is_active ? 'Active partner' : 'Inactive' }}</span></header>
          <h3>{{ developer.name }}</h3><p class="developer-units">{{ developer.listings_count }} linked {{ developer.listings_count === 1 ? 'property' : 'properties' }}</p>
          <div class="developer-contact"><span v-if="developer.contact_name"><UserRound :size="14" />{{ developer.contact_name }}</span><a v-if="developer.phone" :href="`tel:${developer.phone}`"><Phone :size="14" />{{ developer.phone }}</a><a v-if="developer.email" :href="`mailto:${developer.email}`"><Mail :size="14" />{{ developer.email }}</a><a v-if="developer.website" :href="developer.website" target="_blank" rel="noopener"><Globe2 :size="14" />{{ developer.website.replace(/^https?:\/\//, '') }}</a><span v-if="!developer.contact_name && !developer.phone && !developer.email && !developer.website" class="muted"><AtSign :size="14" />No contact details yet</span></div>
          <p v-if="developer.notes" class="developer-notes">{{ developer.notes }}</p>
          <footer v-if="canManage"><button class="button secondary" @click="openEdit(developer)"><Pencil :size="14" />Edit</button><button class="developer-toggle" :class="{ deactivate: developer.is_active }" @click="toggle(developer)">{{ developer.is_active ? 'Deactivate' : 'Reactivate' }}</button></footer>
        </article>
      </div>
      <footer v-if="pagination.last_page > 1" class="developer-pagination"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} developers</span><div><button class="button secondary" :disabled="page <= 1" @click="page--">Previous</button><button class="button secondary" :disabled="page >= pagination.last_page" @click="page++">Next</button></div></footer>
    </section>
    <Modal v-if="showEditor" :title="editingId ? 'Edit developer' : 'Add a real estate developer'" description="Save useful contact details once, then link this partner to as many properties as needed." @close="showEditor = false">
      <form class="form-grid" @submit.prevent="save">
        <label class="full">Developer name<input v-model.trim="form.name" required maxlength="160" placeholder="e.g. Palm Hills Developments"></label>
        <label>Contact person<input v-model.trim="form.contact_name" maxlength="160" placeholder="Sales representative"></label><label>Phone<input v-model.trim="form.phone" type="tel" maxlength="40" placeholder="+20 10…"></label>
        <label>Email<input v-model.trim="form.email" type="email" maxlength="190" placeholder="sales@developer.com"></label><label>Website<input v-model.trim="form.website" type="url" maxlength="255" placeholder="https://example.com"></label>
        <label class="full">Notes<textarea v-model.trim="form.notes" rows="3" maxlength="5000" placeholder="Projects, payment terms, or useful relationship notes"></textarea></label>
        <label v-if="editingId" class="toggle-field"><input v-model="form.is_active" type="checkbox"><span>Active developer</span></label>
        <div v-if="error" class="form-error full">{{ error }}</div><div class="form-actions full"><button type="button" class="button secondary" @click="showEditor = false">Cancel</button><button class="button primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save developer' }}</button></div>
      </form>
    </Modal>
  </div>
</template>
