<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronLeft, ChevronRight, Download, ExternalLink, ListFilter, MessageCircle, MoreHorizontal, Pencil, Phone, Plus, Search, Upload, Users, X } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'
import type { Lead } from '../types'
import Modal from '../components/Modal.vue'
import StatusBadge from '../components/StatusBadge.vue'
import LocationTagPicker, { type PreferredLocationOption } from '../components/LocationTagPicker.vue'

const auth = useAuthStore()
const router = useRouter()
const leads = ref<Lead[]>([])
const locations = ref<PreferredLocationOption[]>([])
const locationsLoading = ref(true)
const meta = ref<any>({})
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const search = ref('')
const stage = ref('')
const archived = ref(false)
const page = ref(1)
const showEditor = ref(false)
const showFilters = ref(false)
const showImport = ref(false)
const importFile = ref<File | null>(null)
const importPreview = ref<any>(null)
const importLoading = ref(false)
const importing = ref(false)
const importError = ref('')
const importNotice = ref('')
const editingId = ref<number | null>(null)
const openMenuId = ref<number | null>(null)
const selectedLeadIds = ref<number[]>([])
const bulkAssignee = ref('')
const bulkSaving = ref(false)
const appliedFilters = reactive({ source: '', intent: '', assigned_to: '' })
const draftFilters = reactive({ source: '', intent: '', assigned_to: '' })
const form = reactive({ name: '', phone: '', email: '', source: 'manual', intent: 'buy', pipeline_stage_id: '', assigned_to: '', budget_min: '', budget_max: '', preferred_locations: [] as string[], property_type: 'apartment', bedrooms: '' })
const sourceOptions = [
  { value: 'manual', label: 'Manual entry' }, { value: 'referral', label: 'Referral' },
  { value: 'facebook', label: 'Facebook' }, { value: 'instagram', label: 'Instagram' },
  { value: 'website', label: 'Website' }, { value: 'property portal', label: 'Property portal' },
  { value: 'whatsapp', label: 'WhatsApp' }, { value: 'phone call', label: 'Phone call' },
  { value: 'walk-in', label: 'Walk-in' }, { value: 'partner', label: 'Partner' }, { value: 'other', label: 'Other' },
]
let timer: number | undefined

const activeFilterCount = computed(() => Object.values(appliedFilters).filter(Boolean).length)
const canReassign = computed(() => ['owner', 'admin', 'manager'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('reassign_leads'))
const canImportLeads = computed(() => ['owner', 'admin', 'manager', 'operations', 'agent'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('create_leads'))
const canBulkArchive = computed(() => ['owner', 'admin', 'manager'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('archive_leads'))
const canBulkActions = computed(() => !archived.value && (canBulkArchive.value || canReassign.value))
const allVisibleSelected = computed(() => leads.value.length > 0 && leads.value.every(lead => selectedLeadIds.value.includes(lead.id)))
const editorTitle = computed(() => editingId.value ? 'Edit lead' : 'Add a new lead')
const showing = computed(() => meta.value.total ? `${meta.value.from}–${meta.value.to} of ${meta.value.total}` : '0 leads')
const sourceLabel = (value?: string | null) => sourceOptions.find(option => option.value === value)?.label || (value ? value.replace(/\b\w/g, letter => letter.toUpperCase()) : 'Unknown')

async function load() {
  loading.value = true
  selectedLeadIds.value = []
  try {
    const { data } = await api.get('/leads', { params: { search: search.value, stage: stage.value, page: page.value, archived: archived.value ? 1 : 0, ...appliedFilters } })
    leads.value = data.data
    meta.value = data
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to load leads.'
  } finally {
    loading.value = false
  }
}

function toggleLeadSelection(id: number) {
  selectedLeadIds.value = selectedLeadIds.value.includes(id)
    ? selectedLeadIds.value.filter(selectedId => selectedId !== id)
    : [...selectedLeadIds.value, id]
}

function toggleVisibleSelection() {
  selectedLeadIds.value = allVisibleSelected.value ? [] : leads.value.map(lead => lead.id)
}

async function runBulkAction(action: 'archive' | 'assign') {
  if (!selectedLeadIds.value.length) return
  if (action === 'archive' && !window.confirm(`Archive ${selectedLeadIds.value.length} selected leads? You can restore them later.`)) return
  bulkSaving.value = true
  error.value = ''
  try {
    const payload: Record<string, unknown> = { action, lead_ids: [...selectedLeadIds.value] }
    if (action === 'assign') payload.assigned_to = bulkAssignee.value ? Number(bulkAssignee.value) : null
    const { data } = await api.post('/leads/bulk', payload)
    importNotice.value = `${data.updated_count} lead${data.updated_count === 1 ? '' : 's'} ${action === 'archive' ? 'archived' : 'reassigned'} successfully.`
    await load()
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to update the selected leads.'
  } finally { bulkSaving.value = false }
}

async function loadLocations() {
  locationsLoading.value = true
  try {
    const { data } = await api.get('/preferred-locations', { params: { active: 1 } })
    locations.value = data.data
  } catch {
    locations.value = []
  } finally {
    locationsLoading.value = false
  }
}

function resetForm() {
  Object.assign(form, { name: '', phone: '', email: '', source: 'manual', intent: 'buy', pipeline_stage_id: '', assigned_to: '', budget_min: '', budget_max: '', preferred_locations: [], property_type: 'apartment', bedrooms: '' })
  editingId.value = null
  error.value = ''
}

function openCreate() {
  resetForm()
  showEditor.value = true
}

function openEdit(lead: Lead) {
  editingId.value = lead.id
  Object.assign(form, {
    name: lead.name || '', phone: lead.phone_original || '', email: lead.email || '', source: lead.source || 'manual', intent: lead.intent || 'buy',
    pipeline_stage_id: String(lead.stage?.id || ''), assigned_to: lead.assignee?.id ? String(lead.assignee.id) : '',
    budget_min: lead.budget_min == null ? '' : String(lead.budget_min), budget_max: lead.budget_max == null ? '' : String(lead.budget_max),
    preferred_locations: [...(lead.preferred_locations || [])], property_type: lead.property_type || 'apartment', bedrooms: lead.bedrooms == null ? '' : String(lead.bedrooms),
  })
  error.value = ''
  openMenuId.value = null
  showEditor.value = true
}

async function saveLead() {
  saving.value = true
  error.value = ''
  const payload: Record<string, unknown> = {
    ...form,
    preferred_locations: form.preferred_locations,
    pipeline_stage_id: form.pipeline_stage_id || null, assigned_to: form.assigned_to || null,
    budget_min: form.budget_min || null, budget_max: form.budget_max || null, bedrooms: form.bedrooms || null,
  }
  if (editingId.value && !canReassign.value) delete payload.assigned_to
  try {
    const response = editingId.value ? await api.put(`/leads/${editingId.value}`, payload) : await api.post('/leads', payload)
    showEditor.value = false
    await load()
    if (response.data.duplicate_warning) alert(`Duplicate warning: ${response.data.duplicate_warning.name} has the same phone number.`)
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to save lead.'] }).flat().join(' ')
  } finally {
    saving.value = false
  }
}

async function downloadImportTemplate() {
  importError.value = ''
  try {
    const response = await api.get('/leads/import-template', { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = 'lead-import-template.csv'
    anchor.click()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to download the lead template.'
  }
}

function openImport() {
  importFile.value = null
  importPreview.value = null
  importError.value = ''
  showImport.value = true
}

async function previewImport(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0] || null
  importFile.value = file
  importPreview.value = null
  importError.value = ''
  if (!file) return
  const payload = new FormData()
  payload.append('file', file)
  importLoading.value = true
  try {
    importPreview.value = (await api.post('/leads/import/preview', payload, { timeout: 30000 })).data
  } catch (exception: any) {
    importError.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to read this CSV file.'] }).flat().join(' ')
  } finally { importLoading.value = false }
}

async function importLeads() {
  if (!importFile.value || !importPreview.value || importPreview.value.invalid_rows || importPreview.value.already_imported) return
  const payload = new FormData()
  payload.append('file', importFile.value)
  importing.value = true
  importError.value = ''
  try {
    const { data } = await api.post('/leads/import', payload, { timeout: 60000 })
    importNotice.value = data.already_imported
      ? 'This exact CSV was already imported; no additional leads were created.'
      : `${data.created_count} lead${data.created_count === 1 ? '' : 's'} imported successfully.`
    showImport.value = false
    await load()
  } catch (exception: any) {
    importError.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to import these leads.'] }).flat().join(' ')
  } finally { importing.value = false }
}

function openFiltersPanel() {
  Object.assign(draftFilters, appliedFilters)
  showFilters.value = true
}

async function applyFilters() {
  Object.assign(appliedFilters, draftFilters)
  page.value = 1
  showFilters.value = false
  await load()
}

async function clearFilters() {
  Object.assign(draftFilters, { source: '', intent: '', assigned_to: '' })
  Object.assign(appliedFilters, draftFilters)
  page.value = 1
  showFilters.value = false
  await load()
}

function toggleMenu(id: number) { openMenuId.value = openMenuId.value === id ? null : id }
async function archiveLead(lead: Lead) {
  if (!window.confirm(`Archive ${lead.name}? You can restore this lead later.`)) return
  try {
    await api.delete(`/leads/${lead.id}`)
    await load()
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to archive lead.'
  }
  closeMenus()
}
async function restoreLead(lead: Lead) {
  try {
    await api.post(`/leads/${lead.id}/restore`)
    await load()
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to restore lead.'
  }
  closeMenus()
}
function openLead(id: number) { router.push(`/leads/${id}`) }
function callLead(lead: Lead) { window.location.href = `tel:${lead.phone_normalized}` }
function whatsappLead(lead: Lead) { window.open(`https://wa.me/${lead.phone_normalized.replace(/\D/g, '')}`, '_blank', 'noopener') }
function closeMenus() { openMenuId.value = null }
const assigningLeadId = ref<number | null>(null)
async function assignLead(lead: Lead, event: Event) {
  const select = event.target as HTMLSelectElement
  const assignedTo = select.value ? Number(select.value) : null
  assigningLeadId.value = lead.id
  error.value = ''
  try {
    const { data } = await api.put(`/leads/${lead.id}`, { assigned_to: assignedTo })
    Object.assign(lead, data)
  } catch (exception: any) {
    select.value = lead.assignee?.id ? String(lead.assignee.id) : ''
    error.value = exception.response?.data?.message || 'Unable to reassign this lead.'
  } finally {
    assigningLeadId.value = null
  }
}
const money = (value?: number) => value ? new Intl.NumberFormat('en-EG', { notation: 'compact', style: 'currency', currency: 'EGP', maximumFractionDigits: 1 }).format(value) : '—'

watch([search, stage], () => { window.clearTimeout(timer); page.value = 1; timer = window.setTimeout(load, 250) })
watch(page, load)
onMounted(() => { load(); loadLocations(); document.addEventListener('click', closeMenus) })
onBeforeUnmount(() => document.removeEventListener('click', closeMenus))
</script>

<template>
  <div>
    <header class="page-heading"><div><span class="eyebrow">RELATIONSHIPS</span><h1>Leads</h1><p>Qualify enquiries, plan the next touch, and keep momentum visible.</p></div><div class="lead-page-actions"><button v-if="canImportLeads" class="button secondary" @click="downloadImportTemplate"><Download :size="16" />Download template</button><button v-if="canImportLeads" class="button secondary" @click="openImport"><Upload :size="16" />Import leads</button><button class="button primary" @click="openCreate"><Plus :size="18" />New lead</button></div></header>
    <div v-if="error && !showEditor" class="detail-alert">{{ error }}<button @click="error = ''">Dismiss</button></div>
    <div v-if="importNotice" class="import-success-notice"><span>{{ importNotice }}</span><button @click="importNotice = ''">Dismiss</button></div>
    <section class="panel data-panel">
      <div class="table-toolbar">
        <div class="search-box"><Search /><input v-model="search" placeholder="Search name or phone…"></div>
        <div class="filter-actions">
          <label class="archive-toggle"><input type="checkbox" v-model="archived" @change="page = 1; load()"> Archived</label>
          <select v-model="stage"><option value="">All stages</option><option v-for="pipelineStage in auth.bootstrap?.stages" :key="pipelineStage.id" :value="pipelineStage.id">{{ pipelineStage.name }}</option></select>
          <button class="button subtle filter-button" :class="{ active: activeFilterCount }" @click="openFiltersPanel"><ListFilter :size="17" />Filters <span v-if="activeFilterCount">{{ activeFilterCount }}</span></button>
        </div>
      </div>
      <div v-if="canBulkActions && selectedLeadIds.length" class="bulk-lead-toolbar">
        <strong>{{ selectedLeadIds.length }} selected</strong>
        <div>
          <template v-if="canReassign"><select v-model="bulkAssignee" :disabled="bulkSaving" aria-label="Choose an owner for selected leads"><option value="">Unassigned</option><option v-for="member in auth.bootstrap?.members" :key="member.id" :value="String(member.id)">{{ member.name }}</option></select><button class="button secondary" :disabled="bulkSaving" @click="runBulkAction('assign')">{{ bulkSaving ? 'Saving…' : 'Assign owner' }}</button></template>
          <button v-if="canBulkArchive" class="button bulk-archive-button" :disabled="bulkSaving" @click="runBulkAction('archive')">Archive selected</button>
        </div>
      </div>
      <div v-if="activeFilterCount" class="active-filters"><span>Filtered results</span><button @click="clearFilters"><X />Clear all</button></div>
      <div class="table-wrap"><table>
        <thead><tr><th v-if="canBulkActions" class="lead-select-cell"><input type="checkbox" :checked="allVisibleSelected" :indeterminate="selectedLeadIds.length > 0 && !allVisibleSelected" aria-label="Select all visible leads" @change="toggleVisibleSelection"></th><th>Lead</th><th>Stage</th><th>Requirements</th><th>Source</th><th>Budget</th><th>Owner</th><th>Next follow-up</th><th></th></tr></thead>
        <tbody>
          <tr v-if="loading" v-for="item in 5" :key="item"><td :colspan="canBulkActions ? 9 : 8"><div class="table-skeleton"></div></td></tr>
          <tr v-else-if="!leads.length"><td :colspan="canBulkActions ? 9 : 8"><div class="table-empty"><Users /><strong>No leads found</strong><span>Try another filter or add your first enquiry.</span></div></td></tr>
          <tr v-for="lead in leads" :key="lead.id" class="clickable-row" tabindex="0" @click="openLead(lead.id)" @keydown.enter="openLead(lead.id)">
            <td v-if="canBulkActions" class="lead-select-cell" @click.stop><input type="checkbox" :checked="selectedLeadIds.includes(lead.id)" :aria-label="`Select ${lead.name}`" @change="toggleLeadSelection(lead.id)"></td>
            <td><span class="lead-cell"><span class="lead-avatar">{{ lead.name.split(' ').map(name => name[0]).join('').slice(0, 2) }}</span><span><strong>{{ lead.name }}</strong><small><Phone />{{ lead.phone_original }}</small></span></span></td>
            <td><StatusBadge :label="lead.stage.name" :color="lead.stage.color" /></td>
            <td><strong class="regular">{{ lead.property_type || 'Not specified' }}</strong><div v-if="lead.preferred_locations?.length" class="table-location-tags"><span v-for="location in lead.preferred_locations" :key="location">{{ location }}</span></div><small v-else class="muted">Any location</small></td>
            <td><span class="lead-source-badge">{{ sourceLabel(lead.source) }}</span></td>
            <td><strong>{{ money(lead.budget_max) }}</strong></td>
            <td @click.stop><select v-if="canReassign && !archived" class="owner-assignment-select" :value="lead.assignee?.id || ''" :disabled="assigningLeadId === lead.id" :aria-label="`Assign ${lead.name} to a team member`" @change="assignLead(lead, $event)"><option value="">Unassigned</option><option v-for="member in auth.bootstrap?.members" :key="member.id" :value="member.id">{{ member.name }}</option></select><span v-else class="owner-cell"><i>{{ lead.assignee?.name?.charAt(0) || '—' }}</i>{{ lead.assignee?.name || 'Unassigned' }}</span></td>
            <td><span :class="{ 'due-date': true, overdue: lead.next_follow_up_at && new Date(lead.next_follow_up_at) < new Date() }">{{ lead.next_follow_up_at ? new Intl.DateTimeFormat('en-EG', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }).format(new Date(lead.next_follow_up_at)) : 'Not scheduled' }}</span></td>
            <td class="actions-cell" @click.stop><button class="icon-button" aria-label="Lead actions" :aria-expanded="openMenuId === lead.id" @click.stop="toggleMenu(lead.id)"><MoreHorizontal /></button><div v-if="openMenuId === lead.id" class="row-action-menu" @click.stop><button @click="openLead(lead.id)"><ExternalLink />View details</button><template v-if="!archived"><button @click="openEdit(lead)"><Pencil />Edit lead</button><button @click="callLead(lead)"><Phone />Call</button><button @click="whatsappLead(lead)"><MessageCircle />WhatsApp</button><button @click="archiveLead(lead)">Archive lead</button></template><button v-else @click="restoreLead(lead)">Restore lead</button></div></td>
          </tr>
        </tbody>
      </table></div>
      <footer class="table-footer"><span>Showing {{ showing }}</span><div><button class="icon-button" :disabled="page <= 1" @click="page--"><ChevronLeft /></button><b>{{ page }}</b><button class="icon-button" :disabled="page >= meta.last_page" @click="page++"><ChevronRight /></button></div></footer>
    </section>

    <Modal v-if="showFilters" title="Filter leads" description="Narrow the pipeline using one or more criteria." @close="showFilters = false"><form class="form-grid" @submit.prevent="applyFilters"><label>Lead source<select v-model="draftFilters.source"><option value="">All sources</option><option v-for="option in sourceOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label><label>Intent<select v-model="draftFilters.intent"><option value="">All intents</option><option value="buy">Buy</option><option value="rent">Rent</option><option value="sell">Sell</option></select></label><label class="full">Owner<select v-model="draftFilters.assigned_to"><option value="">All owners</option><option value="unassigned">Unassigned</option><option v-for="member in auth.bootstrap?.members" :key="member.id" :value="member.id">{{ member.name }}</option></select></label><div class="form-actions full"><button type="button" class="button secondary" @click="clearFilters">Clear filters</button><button class="button primary">Apply filters</button></div></form></Modal>

    <Modal v-if="showEditor" :title="editorTitle" description="Keep the enquiry and requirements current." @close="showEditor = false"><form class="form-grid" @submit.prevent="saveLead"><label>Full name<input v-model="form.name" required></label><label>Mobile number<input v-model="form.phone" required></label><label>Email<input v-model="form.email" type="email" placeholder="Optional"></label><label>Stage<select v-model="form.pipeline_stage_id"><option value="">Default stage</option><option v-for="pipelineStage in auth.bootstrap?.stages" :key="pipelineStage.id" :value="pipelineStage.id">{{ pipelineStage.name }}</option></select></label><label class="lead-source-field">Lead source<select v-model="form.source"><option v-for="option in sourceOptions" :key="option.value" :value="option.value">{{ option.label }}</option><option v-if="form.source && !sourceOptions.some(option => option.value === form.source)" :value="form.source">{{ sourceLabel(form.source) }}</option></select><small>Choose where this enquiry came from. Reports group leads using this value; external campaign sources are not tracked automatically.</small></label><label>Owner<select :disabled="!canReassign" v-model="form.assigned_to"><option value="">Unassigned</option><option v-for="member in auth.bootstrap?.members" :key="member.id" :value="member.id">{{ member.name }}</option></select></label><label>Intent<select v-model="form.intent"><option value="buy">Buy</option><option value="rent">Rent</option><option value="sell">Sell</option></select></label><label>Property type<select v-model="form.property_type"><option>apartment</option><option>villa</option><option>townhouse</option><option>office</option><option>chalet</option></select></label><label>Minimum budget<input v-model="form.budget_min" type="number" min="0"></label><label>Maximum budget<input v-model="form.budget_max" type="number" min="0"></label><LocationTagPicker v-model="form.preferred_locations" :locations="locations" :loading="locationsLoading" /><div v-if="error" class="form-error full">{{ error }}</div><div class="form-actions full"><button type="button" class="button secondary" @click="showEditor = false">Cancel</button><button class="button primary" :disabled="saving">{{ saving ? 'Saving…' : editingId ? 'Save changes' : 'Create lead' }}</button></div></form></Modal>

    <Modal v-if="showImport" title="Import leads" description="Upload a CSV file using the lead template." @close="showImport = false"><div class="lead-import-modal"><p class="import-help">Use the downloaded template. Required columns are <strong>name</strong> and <strong>phone</strong>. Separate multiple preferred locations with a vertical bar (|). Leave <strong>source</strong> blank for Manual entry; common sources include referral, Facebook, Instagram, website, property portal, WhatsApp, phone call, walk-in, and partner. Files can contain up to 500 rows and be up to 5 MB.</p><label class="import-file-picker">Choose CSV file<input type="file" accept=".csv,text/csv" @change="previewImport"></label><div v-if="importLoading" class="import-preview-loading">Checking rows…</div><template v-if="importPreview"><div class="import-summary"><strong>{{ importPreview.total_rows }} rows</strong><span>{{ importPreview.valid_rows }} ready</span><span v-if="importPreview.invalid_rows" class="invalid-count">{{ importPreview.invalid_rows }} need fixing</span></div><div v-if="importPreview.already_imported" class="import-warning">This exact CSV has already been imported. Importing again will not create duplicate rows.</div><div class="import-row-preview"><article v-for="row in importPreview.rows.slice(0, 8)" :key="row.row_number" :class="{ invalid: !row.valid, duplicate: row.duplicate_warning }"><span class="import-row-number">Row {{ row.row_number }}</span><strong>{{ row.data.name || 'Unnamed lead' }}</strong><span v-if="row.errors.length" class="import-row-errors">{{ row.errors.join(' ') }}</span><span v-else-if="row.duplicate_warning" class="import-row-warning">Phone also appears on {{ row.duplicate_warning.name }}. This lead will still be imported.</span></article></div><small v-if="importPreview.rows.length > 8" class="import-more-rows">Showing first 8 of {{ importPreview.rows.length }} rows.</small></template><div v-if="importError" class="form-error">{{ importError }}</div><div class="form-actions"><button class="button secondary" @click="showImport = false">Cancel</button><button class="button primary" :disabled="!importPreview || !!importPreview.invalid_rows || importPreview.already_imported || importing" @click="importLeads">{{ importing ? 'Importing…' : `Import ${importPreview?.valid_rows || 0} leads` }}</button></div></div></Modal>
  </div>
</template>
