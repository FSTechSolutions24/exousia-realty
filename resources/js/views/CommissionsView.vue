<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { BadgeCheck, Printer, Search, Upload } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

interface Commission {
  id: number; deal_id: number; payee_name: string; payee_email: string | null; unit_reference: string
  amount_minor_units: number; calculation_type: string; rate_percent: string | null; base_amount_minor_units: number | null
  status: string; due_on: string | null; paid_at: string | null; reference: string | null
  signed_document: { id: number; original_name: string; mime_type: string; size_bytes: number; url: string } | null
}

const auth = useAuthStore()
const entries = ref<Commission[]>([])
const pagination = ref<any>({})
const loading = ref(true)
const error = ref('')
const search = ref('')
const status = ref('')
const page = ref(1)
const busyId = ref<number | null>(null)
const managerRole = computed(() => ['owner', 'admin', 'finance'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('manage_commissions'))
const statusTitle = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase())
const money = (minor: number) => new Intl.NumberFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 2 }).format((minor || 0) / 100)
const dateTime = (value: string | null) => value ? new Intl.DateTimeFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'
let searchTimer: number | undefined

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/commissions', { params: { search: search.value, status: status.value, page: page.value, per_page: 25 } })
    entries.value = data.data
    pagination.value = data
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to load commissions.'
  } finally { loading.value = false }
}

async function changeStatus(entry: Commission, nextStatus: string) {
  busyId.value = entry.id
  error.value = ''
  try {
    await api.patch(`/deals/${entry.deal_id}/commissions/${entry.id}`, { status: nextStatus })
    await load()
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to update this commission.'] }).flat().join(' ')
  } finally { busyId.value = null }
}

async function uploadSigned(entry: Commission, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const form = new FormData()
  form.append('signed_document', file)
  busyId.value = entry.id
  error.value = ''
  try {
    await api.post(`/commissions/${entry.id}/signed-document`, form)
    await load()
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to upload the signed order PDF.'] }).flat().join(' ')
  } finally { busyId.value = null; input.value = '' }
}

function printOrder(entry: Commission) {
  window.open(`/commissions/${entry.id}/order`, '_blank', 'noopener')
}

watch(status, () => { page.value = 1; load() })
watch(search, () => { window.clearTimeout(searchTimer); page.value = 1; searchTimer = window.setTimeout(load, 250) })
watch(page, load)
onMounted(load)
</script>

<template>
  <div class="commissions-page">
    <header class="page-heading"><div><span class="eyebrow">PAYROLL CONTROL</span><h1>Commissions</h1><p>Review employee commission orders, collect signed copies, and record completed payments.</p></div><span class="commission-security-note"><BadgeCheck :size="16" />Workspace private</span></header>
    <div v-if="error" class="detail-alert">{{ error }}<button @click="error = ''">Dismiss</button></div>
    <section class="panel commission-register">
      <div class="commission-register-toolbar"><div class="search-box"><Search /><input v-model="search" placeholder="Search employee, unit, or reference…"></div><select v-model="status" aria-label="Filter commissions by status"><option value="">All statuses</option><option value="pending">Pending approval</option><option value="approved">Approved</option><option value="paid">Paid</option><option value="void">Void</option></select></div>
      <div class="commission-workflow-hint"><strong>Payment workflow</strong><span>Approve the commission → print the payment order for signature → upload the signed PDF → mark it paid.</span></div>
      <div class="table-wrap"><table class="commission-table">
        <thead><tr><th>Employee / unit</th><th>Commission basis</th><th>Amount</th><th>Status</th><th>Signed order</th><th>Actions</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="6"><div class="table-skeleton"></div></td></tr>
          <tr v-else-if="!entries.length"><td colspan="6"><div class="table-empty"><BadgeCheck/><strong>No commission entries</strong><span>Commission calculations from Deals will appear here.</span></div></td></tr>
          <template v-else>
          <tr v-for="entry in entries" :key="entry.id">
            <td><div class="commission-employee-cell"><strong>{{ entry.payee_name }}</strong><small>{{ entry.payee_email || 'Workspace employee' }}</small><span>{{ entry.unit_reference }}</span><small v-if="entry.reference">Ref: {{ entry.reference }}</small></div></td>
            <td><div class="commission-basis-cell"><strong>{{ entry.calculation_type === 'percentage' ? `${entry.rate_percent}% of ${money(entry.base_amount_minor_units || 0)}` : 'Fixed amount' }}</strong><small>Deal #{{ entry.deal_id }}<template v-if="entry.due_on"> · Due {{ dateTime(`${entry.due_on}T00:00:00`) }}</template></small></div></td>
            <td><strong class="commission-amount-cell">{{ money(entry.amount_minor_units) }}</strong></td>
            <td><span class="commission-status" :class="entry.status">{{ statusTitle(entry.status) }}</span><small v-if="entry.paid_at" class="commission-paid-date">Paid {{ dateTime(entry.paid_at) }}</small></td>
            <td><div class="commission-document-cell"><a v-if="entry.signed_document" class="signed-file-link" :href="entry.signed_document.url" target="_blank" rel="noopener">{{ entry.signed_document.original_name }}</a><span v-else class="commission-no-document">No signed PDF</span><label v-if="managerRole && entry.status === 'approved'" class="signed-upload-control" :class="{ busy: busyId === entry.id }"><Upload :size="13" /><span>{{ entry.signed_document ? 'Replace PDF' : 'Upload PDF' }}</span><input type="file" accept="application/pdf,.pdf" :disabled="busyId === entry.id" @change="uploadSigned(entry, $event)"></label></div></td>
            <td><div class="commission-actions"><button v-if="entry.status === 'approved'" class="icon-button" :aria-label="`Print payment order for ${entry.payee_name}`" title="Print payment order" @click="printOrder(entry)"><Printer :size="16" /></button><button v-if="managerRole && entry.status === 'pending'" class="button commission-approve" :disabled="busyId === entry.id" @click="changeStatus(entry, 'approved')">Approve</button><button v-if="managerRole && entry.status === 'approved'" class="button commission-paid" :disabled="busyId === entry.id || !entry.signed_document" :title="entry.signed_document ? 'Mark paid' : 'Upload the signed PDF first'" @click="changeStatus(entry, 'paid')">Mark paid</button><button v-if="managerRole && ['pending','approved'].includes(entry.status)" class="commission-void" :disabled="busyId === entry.id" @click="changeStatus(entry, 'void')">Void</button></div></td>
          </tr>
          </template>
        </tbody>
      </table></div>
      <footer class="table-footer"><span>Showing {{ pagination.from || 0 }}–{{ pagination.to || 0 }} of {{ pagination.total || 0 }} commissions</span><div><button class="icon-button" :disabled="page <= 1" @click="page--">‹</button><b>{{ page }}</b><button class="icon-button" :disabled="page >= (pagination.last_page || 1)" @click="page++">›</button></div></footer>
    </section>
  </div>
</template>
