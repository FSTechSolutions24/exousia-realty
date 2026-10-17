<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { CalendarClock, FilePlus2 } from '@lucide/vue'
import { api } from '../api'
import Modal from '../components/Modal.vue'

const tab = ref<'invoices' | 'installments'>('invoices')
const invoices = ref<any[]>([]); const installments = ref<any[]>([]); const deals = ref<any[]>([])
const loading = ref(true); const error = ref(''); const showInvoice = ref(false); const showSchedule = ref(false); const showPayment = ref(false); const saving = ref(false); const selectedInstallment = ref<any>(null)
const invoice = reactive({ deal_id: '', issue_date: new Date().toISOString().slice(0, 10), due_date: '', billing_name: '', billing_phone: '', tax_egp: '0', notes: '', description: 'Brokerage services', quantity: 1, unit_price_egp: '' })
const schedule = reactive({ deal_id: '', count: 12, first_due_date: new Date().toISOString().slice(0, 10), frequency: 'monthly' })
const payment = reactive({ amount_egp: '', paid_on: new Date().toISOString().slice(0, 10), payment_method: 'bank_transfer', payment_reference: '', notes: '' })
function money(minor: number) { return new Intl.NumberFormat('en-EG', { style: 'currency', currency: 'EGP' }).format((minor || 0) / 100) }
async function load() {
  loading.value = true
  try { const [i, s, d] = await Promise.all([api.get('/invoices'), api.get('/installments'), api.get('/deals', { params: { per_page: 50 } })]); invoices.value = i.data.data || []; installments.value = s.data.data || []; deals.value = d.data.data || [] }
  catch (e: any) { error.value = e.response?.data?.message || 'Finance records could not be loaded.' }
  finally { loading.value = false }
}
function chooseDeal() { const deal = deals.value.find(item => String(item.id) === invoice.deal_id); if (deal) { invoice.billing_name = deal.lead?.name || ''; invoice.billing_phone = deal.lead?.phone || ''; invoice.unit_price_egp = deal.agreed_price_egp || '' } }
function openPayment(item: any) { selectedInstallment.value = item; payment.amount_egp = ((item.amount_minor_units - item.paid_minor_units) / 100).toFixed(2); payment.notes = ''; payment.payment_reference = ''; showPayment.value = true }
async function createInvoice() {
  saving.value = true; error.value = ''
  try { await api.post('/invoices', { deal_id: invoice.deal_id ? Number(invoice.deal_id) : null, issue_date: invoice.issue_date, due_date: invoice.due_date || null, billing_name: invoice.billing_name, billing_phone: invoice.billing_phone || null, tax_egp: invoice.tax_egp, notes: invoice.notes || null, items: [{ description: invoice.description, quantity: invoice.quantity, unit_price_egp: invoice.unit_price_egp }] }); showInvoice.value = false; await load() }
  catch (e: any) { error.value = Object.values(e.response?.data?.errors || { error: [e.response?.data?.message || 'Invoice could not be created.'] }).flat().join(' ') }
  finally { saving.value = false }
}
async function createSchedule() {
  saving.value = true; error.value = ''
  try { await api.post(`/deals/${schedule.deal_id}/installments/generate`, { count: schedule.count, first_due_date: schedule.first_due_date, frequency: schedule.frequency }); showSchedule.value = false; await load() }
  catch (e: any) { error.value = Object.values(e.response?.data?.errors || { error: [e.response?.data?.message || 'Schedule could not be created.'] }).flat().join(' ') }
  finally { saving.value = false }
}
async function recordInstallmentPayment() {
  if (!selectedInstallment.value) return
  saving.value = true; error.value = ''
  try { await api.post(`/installments/${selectedInstallment.value.id}/payments`, payment); showPayment.value = false; selectedInstallment.value = null; await load() }
  catch (e: any) { error.value = Object.values(e.response?.data?.errors || { error: [e.response?.data?.message || 'Payment could not be saved.'] }).flat().join(' ') }
  finally { saving.value = false }
}
onMounted(load)
</script>

<template>
  <div class="finance-page">
    <header class="page-heading"><div><span class="eyebrow">FINANCIAL OPERATIONS</span><h1>Invoices & installments</h1><p>Issue client invoices, record collections, and follow every scheduled deal payment.</p></div><div class="page-actions"><button class="button secondary" @click="showSchedule = true"><CalendarClock />New schedule</button><button class="button primary" @click="showInvoice = true"><FilePlus2 />New invoice</button></div></header>
    <div v-if="error" class="detail-alert">{{ error }}<button @click="error = ''">Dismiss</button></div>
    <div class="finance-tabs"><button :class="{ active: tab === 'invoices' }" @click="tab = 'invoices'">Invoices</button><button :class="{ active: tab === 'installments' }" @click="tab = 'installments'">Installments</button></div>
    <section class="panel">
      <div v-if="loading" class="whatsapp-loading">Loading finance records…</div>
      <div v-else-if="tab === 'invoices'" class="table-wrap"><table><thead><tr><th>Invoice</th><th>Client</th><th>Issue / due</th><th>Total</th><th>Paid</th><th>Status</th></tr></thead><tbody><tr v-for="item in invoices" :key="item.id"><td><RouterLink :to="`/invoices/${item.id}`"><strong>{{ item.invoice_number }}</strong></RouterLink></td><td>{{ item.billing_name }}</td><td>{{ item.issue_date }}<small>{{ item.due_date || 'No due date' }}</small></td><td>{{ money(item.total_minor_units) }}</td><td>{{ money(item.amount_paid_minor_units) }}</td><td><span class="finance-status">{{ item.status.replace('_', ' ') }}</span></td></tr><tr v-if="!invoices.length"><td colspan="6">No invoices yet.</td></tr></tbody></table></div>
      <div v-else class="table-wrap"><table><thead><tr><th>Deal</th><th>Client</th><th>Installment</th><th>Due</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody><tr v-for="item in installments" :key="item.id"><td>#{{ item.deal_id }} · {{ item.deal?.property_listing?.reference_code || 'No property' }}</td><td>{{ item.deal?.lead?.name }}</td><td>{{ item.sequence }}</td><td>{{ item.due_date }}</td><td>{{ money(item.amount_minor_units) }}<small>Paid {{ money(item.paid_minor_units) }} · {{ item.payments?.length || 0 }} payment(s)</small></td><td><span class="finance-status">{{ item.status.replace('_', ' ') }}</span></td><td><button v-if="item.status !== 'paid'" class="button secondary compact-button" @click="openPayment(item)">Record payment</button></td></tr><tr v-if="!installments.length"><td colspan="7">No installment schedules yet.</td></tr></tbody></table></div>
    </section>
    <Modal v-if="showInvoice" title="Create invoice" description="Amounts are stored exactly in EGP piastres." @close="showInvoice = false"><form class="form-grid" @submit.prevent="createInvoice"><label class="full">Related deal<select v-model="invoice.deal_id" @change="chooseDeal"><option value="">No deal</option><option v-for="deal in deals" :key="deal.id" :value="String(deal.id)">#{{ deal.id }} · {{ deal.lead?.name }} · {{ deal.property?.title || 'No property' }}</option></select></label><label>Issue date<input v-model="invoice.issue_date" type="date" required></label><label>Due date<input v-model="invoice.due_date" type="date"></label><label>Billing name<input v-model="invoice.billing_name" required></label><label>Billing phone<input v-model="invoice.billing_phone"></label><label class="full">Line description<input v-model="invoice.description" required></label><label>Quantity<input v-model.number="invoice.quantity" type="number" min="1" required></label><label>Unit price (EGP)<input v-model="invoice.unit_price_egp" inputmode="decimal" required></label><label>Tax (EGP)<input v-model="invoice.tax_egp" inputmode="decimal" required></label><label class="full">Notes<textarea v-model="invoice.notes" rows="3"></textarea></label><div class="form-actions full"><button type="button" class="button secondary" @click="showInvoice = false">Cancel</button><button class="button primary" :disabled="saving">{{ saving ? 'Creating…' : 'Create draft' }}</button></div></form></Modal>
    <Modal v-if="showSchedule" title="Generate installment schedule" description="The outstanding deal balance is split exactly; any piastre remainder is added to the final installment." @close="showSchedule = false"><form class="form-grid" @submit.prevent="createSchedule"><label class="full">Deal<select v-model="schedule.deal_id" required><option value="" disabled>Select a deal</option><option v-for="deal in deals" :key="deal.id" :value="String(deal.id)">#{{ deal.id }} · {{ deal.lead?.name }} · {{ money(deal.balance_due_minor_units) }}</option></select></label><label>Installments<input v-model.number="schedule.count" type="number" min="1" max="120" required></label><label>First due date<input v-model="schedule.first_due_date" type="date" required></label><label class="full">Frequency<select v-model="schedule.frequency"><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option></select></label><div class="form-actions full"><button type="button" class="button secondary" @click="showSchedule = false">Cancel</button><button class="button primary" :disabled="saving">{{ saving ? 'Generating…' : 'Generate schedule' }}</button></div></form></Modal>
    <Modal v-if="showPayment" title="Record installment payment" :description="`Installment ${selectedInstallment?.sequence} · ${selectedInstallment?.due_date}`" @close="showPayment = false"><form class="form-grid" @submit.prevent="recordInstallmentPayment"><label>Amount (EGP)<input v-model="payment.amount_egp" inputmode="decimal" required></label><label>Paid on<input v-model="payment.paid_on" type="date" required></label><label>Method<select v-model="payment.payment_method"><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="cheque">Cheque</option><option value="financing">Financing</option><option value="other">Other</option></select></label><label>Reference<input v-model="payment.payment_reference"></label><label class="full">Notes<textarea v-model="payment.notes" rows="3"></textarea></label><div class="form-actions full"><button type="button" class="button secondary" @click="showPayment = false">Cancel</button><button class="button primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save payment' }}</button></div></form></Modal>
  </div>
</template>
