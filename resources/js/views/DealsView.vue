<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { BadgeCheck, Building2, CalendarDays, Eye, Handshake, Pencil, Plus, Search } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'
import Modal from '../components/Modal.vue'

interface DealLead { id: number; name: string; phone: string; assigned_to: number | null; created_by: number }
interface DealProperty { id: number; title: string; reference_code: string; location: string; status: string }
interface DealActivity { id: number; event: string; old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null; occurred_at: string; user?: { name: string } | null }
interface Deal { id: number; status: string; expected_close_date: string | null; agreed_price_egp: string | null; agreed_price_minor_units: number | null; notes: string | null; lead: DealLead; property: DealProperty | null; activities?: DealActivity[] }
interface LeadOption { id: number; name: string; phone_original: string; assigned_to: number | null; created_by: number }
interface PropertyOption { id: number; title: string; reference_code: string; location: string; status: string }
interface CommissionEntry { id: number; payee_name: string; payee_user_id: number | null; amount_egp: string; status: string; due_on: string | null; paid_at: string | null; reference: string | null }
interface DealDocument { id: number; category: string; original_name: string; mime_type: string; size_bytes: number; uploaded_at: string; url: string }

const auth = useAuthStore()
const deals = ref<Deal[]>([])
const leadOptions = ref<LeadOption[]>([])
const propertyOptions = ref<PropertyOption[]>([])
const pagination = ref<any>({})
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const search = ref('')
const statusFilter = ref('')
const page = ref(1)
const showEditor = ref(false)
const editingId = ref<number | null>(null)
const activeDeal = ref<Deal | null>(null)
const history = ref<DealActivity[]>([])
const commissions = ref<CommissionEntry[]>([])
const documents = ref<DealDocument[]>([])
const financeError = ref('')
const commissionSaving = ref(false)
const commissionForm = reactive({ payee_user_id: '' as number | '', amount_egp: '', due_on: '', reference: '' })
const form = reactive({ lead_id: '' as number | '', property_listing_id: '' as number | '', status: 'negotiation', expected_close_date: '', agreed_price_egp: '', notes: '' })
const statuses = ['negotiation', 'reserved', 'contracted', 'closed_won', 'closed_lost']
let searchTimer: number | undefined

const role = computed(() => auth.bootstrap?.membership.role || '')
const canCreate = computed(() => ['owner', 'admin', 'manager', 'operations', 'agent'].includes(role.value) || auth.bootstrap?.membership.permissions.includes('create_deals') || auth.bootstrap?.membership.permissions.includes('manage_deals'))
const canEditDeal = (deal: Deal) => ['owner', 'admin', 'manager', 'operations'].includes(role.value) || auth.bootstrap?.membership.permissions.includes('manage_deals') || (role.value === 'agent' && (deal.lead.assigned_to === auth.bootstrap?.user.id || deal.lead.created_by === auth.bootstrap?.user.id))
const canEditActive = computed(() => editingId.value ? !!activeDeal.value && canEditDeal(activeDeal.value) : canCreate.value)
const canViewCommissions = computed(() => ['owner', 'admin', 'finance'].includes(role.value) || !!auth.bootstrap?.membership.permissions.includes('view_commissions'))
const canManageCommissions = computed(() => ['owner', 'admin', 'finance'].includes(role.value) || !!auth.bootstrap?.membership.permissions.includes('manage_commissions'))
const activeCount = computed(() => pagination.value.total ? deals.value.filter(deal => !['closed_won', 'closed_lost'].includes(deal.status)).length : 0)
const wonCount = computed(() => deals.value.filter(deal => deal.status === 'closed_won').length)
const statusLabel = (status: string) => status.replace(/_/g, ' ')
const money = (value: string | null) => value === null ? 'Not set' : new Intl.NumberFormat('en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 2 }).format(Number(value))
const dateLabel = (value: string | null) => value ? new Intl.DateTimeFormat('en-EG', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`)) : 'Not scheduled'

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/deals', { params: { search: search.value, status: statusFilter.value, page: page.value, per_page: 25 } })
    deals.value = data.data
    pagination.value = data
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to load deals.'
  } finally { loading.value = false }
}

async function loadOptions() {
  if (!canCreate.value) return
  try {
    const [leadResponse, propertyResponse] = await Promise.all([
      api.get('/leads', { params: { per_page: 50 } }),
      api.get('/inventory', { params: { per_page: 50 } }),
    ])
    leadOptions.value = leadResponse.data.data
    propertyOptions.value = propertyResponse.data.data
  } catch {
    // The deal list remains useful even if selection options cannot be loaded.
  }
}

function resetForm() {
  Object.assign(form, { lead_id: '', property_listing_id: '', status: 'negotiation', expected_close_date: '', agreed_price_egp: '', notes: '' })
  editingId.value = null
  activeDeal.value = null
  history.value = []
  error.value = ''
}

function openCreate() {
  resetForm()
  showEditor.value = true
}

async function openDeal(deal: Deal) {
  resetForm()
  try {
    const { data } = await api.get(`/deals/${deal.id}`)
    editingId.value = data.id
    activeDeal.value = data
    history.value = data.activities || []
    Object.assign(form, {
      lead_id: data.lead.id, property_listing_id: data.property?.id || '', status: data.status,
      expected_close_date: data.expected_close_date || '', agreed_price_egp: data.agreed_price_egp || '', notes: data.notes || '',
    })
    showEditor.value = true
    financeError.value = ''
    commissions.value = []
    documents.value = []
    if (canViewCommissions.value) {
      try { commissions.value = (await api.get(`/deals/${data.id}/commissions`)).data }
      catch (exception: any) { financeError.value = exception.response?.data?.message || 'Unable to load commission records.' }
    }
    try { documents.value = (await api.get(`/deals/${data.id}/documents`)).data }
    catch (exception: any) { financeError.value = exception.response?.data?.message || 'Unable to load deal documents.' }
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to open this deal.'
  }
}

async function addCommission() {
  if (!activeDeal.value) return
  commissionSaving.value = true
  financeError.value = ''
  try {
    await api.post(`/deals/${activeDeal.value.id}/commissions`, {
      payee_user_id: commissionForm.payee_user_id, amount_egp: commissionForm.amount_egp,
      due_on: commissionForm.due_on || null, reference: commissionForm.reference || null,
    })
    Object.assign(commissionForm, { payee_user_id: '', amount_egp: '', due_on: '', reference: '' })
    commissions.value = (await api.get(`/deals/${activeDeal.value.id}/commissions`)).data
  } catch (exception: any) {
    financeError.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to add this commission.'] }).flat().join(' ')
  } finally { commissionSaving.value = false }
}

async function changeCommission(entry: CommissionEntry, status: string) {
  if (!activeDeal.value) return
  financeError.value = ''
  try {
    await api.patch(`/deals/${activeDeal.value.id}/commissions/${entry.id}`, { status })
    commissions.value = (await api.get(`/deals/${activeDeal.value.id}/commissions`)).data
  } catch (exception: any) { financeError.value = exception.response?.data?.message || 'Unable to update this commission.' }
}

async function uploadDocument(event: Event) {
  if (!activeDeal.value) return
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  financeError.value = ''
  const formData = new FormData()
  formData.append('document', file)
  formData.append('category', 'contract')
  try {
    await api.post(`/deals/${activeDeal.value.id}/documents`, formData)
    documents.value = (await api.get(`/deals/${activeDeal.value.id}/documents`)).data
  } catch (exception: any) {
    financeError.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to upload this document.'] }).flat().join(' ')
  } finally { input.value = '' }
}

async function save() {
  saving.value = true
  error.value = ''
  const payload = {
    lead_id: form.lead_id || undefined,
    property_listing_id: form.property_listing_id || null,
    status: form.status,
    expected_close_date: form.expected_close_date || null,
    agreed_price_egp: form.agreed_price_egp || null,
    notes: form.notes || null,
  }
  try {
    if (editingId.value) await api.put(`/deals/${editingId.value}`, payload)
    else await api.post('/deals', payload)
    showEditor.value = false
    await load()
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to save this deal.'] }).flat().join(' ')
  } finally { saving.value = false }
}

watch([statusFilter], () => { page.value = 1; load() })
watch(search, () => { window.clearTimeout(searchTimer); page.value = 1; searchTimer = window.setTimeout(load, 250) })
watch(page, load)
onMounted(() => { load(); loadOptions() })
</script>

<template>
  <div>
    <header class="page-heading"><div><span class="eyebrow">SALES PIPELINE</span><h1>Deals</h1><p>Move qualified leads through negotiation, reservation, contract, and close.</p></div><button v-if="canCreate" class="button primary" @click="openCreate"><Plus :size="18" />Add deal</button></header>
    <div v-if="error && !showEditor" class="detail-alert">{{ error }}<button @click="error = ''">Dismiss</button></div>
    <section class="deal-stats"><article><span><Handshake /></span><div><strong>{{ pagination.total || 0 }}</strong><small>Deals in this view</small></div></article><article><span class="active"><Building2 /></span><div><strong>{{ activeCount }}</strong><small>Active on this page</small></div></article><article><span class="won"><BadgeCheck /></span><div><strong>{{ wonCount }}</strong><small>Closed won on this page</small></div></article></section>
    <section class="panel deals-panel">
      <div class="table-toolbar"><div class="search-box"><Search /><input v-model="search" placeholder="Search lead or property…"></div><div class="filter-actions"><select v-model="statusFilter"><option value="">All deal stages</option><option v-for="item in statuses" :key="item" :value="item">{{ statusLabel(item) }}</option></select></div></div>
      <div class="inventory-table-wrap"><table class="inventory-table deals-table"><thead><tr><th>Lead</th><th>Property</th><th>Status</th><th>Agreed value</th><th>Expected close</th><th></th></tr></thead><tbody>
        <tr v-if="loading"><td colspan="6" class="inventory-empty">Loading deals…</td></tr>
        <tr v-else-if="!deals.length"><td colspan="6"><div class="table-empty"><Handshake /><strong>No deals yet</strong><span>{{ canCreate ? 'Create a deal when a lead starts negotiating for a property.' : 'There are no deals available in this workspace.' }}</span></div></td></tr>
        <tr v-for="deal in deals" :key="deal.id" class="inventory-row"><td><span class="deal-lead-cell"><strong>{{ deal.lead.name }}</strong><small>{{ deal.lead.phone }}</small></span></td><td><span v-if="deal.property" class="deal-property-cell"><strong>{{ deal.property.title }}</strong><small>{{ deal.property.reference_code }} · {{ deal.property.location }}</small></span><span v-else class="deal-muted">No property linked</span></td><td><span class="deal-status" :class="deal.status">{{ statusLabel(deal.status) }}</span></td><td><strong>{{ money(deal.agreed_price_egp) }}</strong></td><td><span class="deal-date"><CalendarDays :size="14" />{{ dateLabel(deal.expected_close_date) }}</span></td><td><button class="icon-button" :aria-label="`${canEditDeal(deal) ? 'Edit' : 'View'} deal for ${deal.lead.name}`" @click="openDeal(deal)"><Pencil v-if="canEditDeal(deal)" :size="16" /><Eye v-else :size="16" /></button></td></tr>
      </tbody></table></div>
      <footer class="table-footer"><span>{{ pagination.total || 0 }} deals</span><div><button class="icon-button" :disabled="page <= 1" @click="page--">‹</button><b>{{ page }}</b><button class="icon-button" :disabled="page >= (pagination.last_page || 1)" @click="page++">›</button></div></footer>
    </section>

    <Modal v-if="showEditor" :title="editingId ? 'Deal details' : 'Add a deal'" description="Track the transaction separately from the lead enquiry." @close="showEditor = false">
      <form class="form-grid deal-form" @submit.prevent="save">
        <label class="full">Lead<select v-model="form.lead_id" :disabled="!!editingId || !canEditActive" required><option value="" disabled>Select a lead</option><option v-for="lead in leadOptions" :key="lead.id" :value="lead.id">{{ lead.name }} · {{ lead.phone_original }}</option><option v-if="activeDeal && !leadOptions.some(lead => lead.id === form.lead_id)" :value="form.lead_id">{{ activeDeal.lead.name }}</option></select></label>
        <label class="full">Property listing<select v-model="form.property_listing_id" :disabled="!canEditActive"><option value="">No property linked yet</option><option v-for="property in propertyOptions" :key="property.id" :value="property.id">{{ property.title }} · {{ property.reference_code }} ({{ property.location }})</option><option v-if="activeDeal?.property && !propertyOptions.some(property => property.id === form.property_listing_id)" :value="form.property_listing_id">{{ activeDeal.property.title }}</option></select></label>
        <label>Deal stage<select v-model="form.status" :disabled="!canEditActive"><option v-for="item in statuses" :key="item" :value="item">{{ statusLabel(item) }}</option></select></label>
        <label>Expected close date<input v-model="form.expected_close_date" :disabled="!canEditActive" type="date"></label>
        <label class="full">Agreed value (EGP)<input v-model="form.agreed_price_egp" :disabled="!canEditActive" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" placeholder="Optional until negotiated"></label>
        <label class="full">Notes<textarea v-model="form.notes" :disabled="!canEditActive" rows="3" maxlength="10000" placeholder="Negotiation details or next steps"></textarea></label>
        <div v-if="history.length" class="deal-history full"><strong>Deal history</strong><span v-for="item in history.slice(0, 8)" :key="item.id">{{ statusLabel(item.event) }} · {{ new Intl.DateTimeFormat('en-EG', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(item.occurred_at)) }}</span></div>
        <section v-if="editingId && canViewCommissions" class="deal-finance-section full"><header><strong>Commission ledger</strong><small>Amounts are entered and paid explicitly; deal value is never treated as commission.</small></header><div v-if="commissions.length" class="finance-record-list"><div v-for="entry in commissions" :key="entry.id" class="finance-record"><span><strong>{{ entry.payee_name }}</strong><small>{{ money(entry.amount_egp) }}<template v-if="entry.due_on"> · Due {{ dateLabel(entry.due_on) }}</template></small></span><select v-if="canManageCommissions && !['paid','void'].includes(entry.status)" :value="entry.status" aria-label="Commission status" @change="changeCommission(entry, ($event.target as HTMLSelectElement).value)"><option value="pending">Pending</option><option value="approved">Approved</option><option value="paid">Paid</option><option value="void">Void</option></select><span v-else class="finance-status" :class="entry.status">{{ statusLabel(entry.status) }}</span></div></div><p v-else class="finance-empty">No commission entries recorded.</p><form v-if="canManageCommissions" class="commission-form" @submit.prevent="addCommission"><select v-model="commissionForm.payee_user_id" required><option value="" disabled>Payee</option><option v-for="member in auth.bootstrap?.members || []" :key="member.id" :value="member.id">{{ member.name }}</option></select><input v-model="commissionForm.amount_egp" required inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" placeholder="Amount (EGP)"><input v-model="commissionForm.due_on" type="date" aria-label="Commission due date"><input v-model="commissionForm.reference" maxlength="100" placeholder="Reference (optional)"><button class="button secondary" :disabled="commissionSaving">{{ commissionSaving ? 'Saving…' : 'Add entry' }}</button></form></section>
        <section v-if="editingId" class="deal-finance-section full"><header><strong>Contracts and documents</strong><small>Private files attached to this deal.</small></header><div v-if="documents.length" class="finance-record-list"><a v-for="document in documents" :key="document.id" class="document-record" :href="document.url"><span><strong>{{ document.original_name }}</strong><small>{{ statusLabel(document.category) }} · {{ Math.ceil(document.size_bytes / 1024) }} KB</small></span><Eye :size="15" /></a></div><p v-else class="finance-empty">No deal documents uploaded.</p><label v-if="canEditActive" class="document-upload">Upload contract or document<input type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" @change="uploadDocument"></label></section>
        <div v-if="financeError" class="form-error full">{{ financeError }}</div>
        <div v-if="!leadOptions.length && canCreate && !editingId" class="form-help full">Add a lead before creating a deal.</div>
        <div v-if="error" class="form-error full">{{ error }}</div>
        <div class="form-actions full"><button type="button" class="button secondary" @click="showEditor = false">{{ canEditActive ? 'Cancel' : 'Close' }}</button><button v-if="canEditActive" class="button primary" :disabled="saving || (!editingId && !form.lead_id)">{{ saving ? 'Saving…' : editingId ? 'Save deal' : 'Create deal' }}</button></div>
      </form>
    </Modal>
  </div>
</template>
