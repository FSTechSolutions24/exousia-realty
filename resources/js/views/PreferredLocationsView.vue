<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { Check, MapPin, Pencil, Plus, Search, Trash2 } from '@lucide/vue'
import { api } from '../api'
import Modal from '../components/Modal.vue'
import type { PreferredLocationOption } from '../components/LocationTagPicker.vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const locations = ref<PreferredLocationOption[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const search = ref('')
const showEditor = ref(false)
const editingId = ref<number | null>(null)
const form = reactive({ name: '', name_ar: '', position: 0, is_active: true })

const canManage = computed(() => ['owner', 'admin'].includes(auth.bootstrap?.membership.role || ''))
const filteredLocations = computed(() => {
  const term = search.value.trim().toLowerCase()
  return term ? locations.value.filter(location => `${location.name} ${location.name_ar || ''}`.toLowerCase().includes(term)) : locations.value
})
const activeCount = computed(() => locations.value.filter(location => location.is_active).length)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/preferred-locations')
    locations.value = data.data
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to load preferred locations.'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingId.value = null
  Object.assign(form, { name: '', name_ar: '', position: locations.value.length, is_active: true })
  error.value = ''
  showEditor.value = true
}

function openEdit(location: PreferredLocationOption) {
  editingId.value = location.id
  Object.assign(form, { name: location.name, name_ar: location.name_ar || '', position: location.position, is_active: location.is_active })
  error.value = ''
  showEditor.value = true
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    editingId.value
      ? await api.put(`/preferred-locations/${editingId.value}`, form)
      : await api.post('/preferred-locations', form)
    showEditor.value = false
    await load()
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to save location.'] }).flat().join(' ')
  } finally {
    saving.value = false
  }
}

async function toggle(location: PreferredLocationOption) {
  try {
    await api.put(`/preferred-locations/${location.id}`, { is_active: !location.is_active })
    location.is_active = !location.is_active
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to update location.'
  }
}

async function remove(location: PreferredLocationOption) {
  if (!window.confirm(`Delete “${location.name}” from the master list? Existing leads will keep this value.`)) return
  try {
    await api.delete(`/preferred-locations/${location.id}`)
    locations.value = locations.value.filter(item => item.id !== location.id)
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to delete location.'
  }
}

onMounted(load)
</script>

<template>
  <div>
    <header class="page-heading">
      <div><span class="eyebrow">MASTER DATA</span><h1>Preferred locations</h1><p>Manage the areas your team can select for leads and property listings.</p></div>
      <button v-if="canManage" class="button primary" @click="openCreate"><Plus :size="18" />Add location</button>
    </header>

    <div v-if="error && !showEditor" class="detail-alert">{{ error }}<button @click="error = ''">Dismiss</button></div>

    <section class="location-summary">
      <article><span class="location-summary-icon"><MapPin /></span><div><strong>{{ locations.length }}</strong><small>Total locations</small></div></article>
      <article><span class="location-summary-icon active"><Check /></span><div><strong>{{ activeCount }}</strong><small>Available in lead forms</small></div></article>
    </section>

    <section class="panel location-master-panel">
      <header class="location-master-toolbar">
        <div><h2>Location master</h2><p>Inactive locations remain on existing records but cannot be selected for new assignments.</p></div>
        <div class="search-box"><Search /><input v-model="search" placeholder="Search locations…"></div>
      </header>
      <div v-if="loading" class="location-master-loading"><i v-for="item in 4" :key="item"></i></div>
      <div v-else-if="!filteredLocations.length" class="table-empty"><MapPin /><strong>No locations found</strong><span>Add the first area your agents can target.</span></div>
      <div v-else class="location-master-list">
        <article v-for="location in filteredLocations" :key="location.id" :class="{ inactive: !location.is_active }">
          <span class="location-pin"><MapPin /></span>
          <div class="location-name"><strong>{{ location.name }}</strong><small>{{ location.name_ar || 'No Arabic name' }}</small></div>
          <span class="location-preview-tag"><MapPin />{{ location.name }}</span>
          <button v-if="canManage" class="status-toggle" :class="{ active: location.is_active }" @click="toggle(location)"><i></i>{{ location.is_active ? 'Active' : 'Inactive' }}</button>
          <span v-else class="location-status">{{ location.is_active ? 'Active' : 'Inactive' }}</span>
          <div v-if="canManage" class="location-row-actions"><button class="icon-button" :aria-label="`Edit ${location.name}`" @click="openEdit(location)"><Pencil /></button><button class="icon-button danger" :aria-label="`Delete ${location.name}`" @click="remove(location)"><Trash2 /></button></div>
        </article>
      </div>
    </section>

    <Modal v-if="showEditor" :title="editingId ? 'Edit location' : 'Add preferred location'" description="This value will be available in the lead location picker." @close="showEditor = false">
      <form class="form-grid" @submit.prevent="save">
        <label>Location name<input v-model.trim="form.name" required placeholder="e.g. New Cairo"></label>
        <label>Arabic name<input v-model.trim="form.name_ar" dir="rtl" placeholder="القاهرة الجديدة"></label>
        <label>Display order<input v-model.number="form.position" type="number" min="0" max="65535"></label>
        <label class="toggle-field"><input v-model="form.is_active" type="checkbox"><span>Active in lead forms</span></label>
        <div v-if="error" class="form-error full">{{ error }}</div>
        <div class="form-actions full"><button type="button" class="button secondary" @click="showEditor = false">Cancel</button><button class="button primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save location' }}</button></div>
      </form>
    </Modal>
  </div>
</template>
