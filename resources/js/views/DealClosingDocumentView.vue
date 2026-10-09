<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ArrowLeft, Printer } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const deal = ref<any>(null)
const loading = ref(true)
const error = ref('')
const money = (minor: number | null) => new Intl.NumberFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 2 }).format((minor || 0) / 100)
const date = (value: string | null) => value ? new Intl.DateTimeFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { dateStyle: 'long' }).format(new Date(`${value}T00:00:00`)) : '—'
const paymentMethod = (value: string | null) => ({ cash: 'Cash', bank_transfer: 'Bank transfer', cheque: 'Cheque', financing: 'Financing', other: 'Other' } as Record<string, string>)[value || ''] || 'Not recorded'
function printDocument() { window.print() }

onMounted(async () => {
  try { deal.value = (await api.get(`/deals/${route.params.id}`)).data }
  catch (exception: any) { error.value = exception.response?.data?.message || 'Unable to prepare the deal closing document.' }
  finally { loading.value = false }
})
</script>

<template>
  <main class="deal-closing-document-page">
    <div class="deal-closing-document-actions"><button class="button secondary" @click="router.push('/deals')"><ArrowLeft :size="15" />Back to deals</button><button class="button primary" :disabled="!deal" @click="printDocument"><Printer :size="15" />Print / Save as PDF</button></div>
    <div v-if="loading" class="panel deal-closing-loading">Preparing the deal closing statement…</div>
    <div v-else-if="error" class="detail-alert">{{ error }}</div>
    <article v-else-if="deal" class="deal-closing-sheet">
      <header class="deal-closing-brand"><span class="deal-closing-mark">E</span><div><strong>EXOUSIA REALTY</strong><small>{{ auth.bootstrap?.company.name }} · DEAL CLOSING DOCUMENT</small></div><span class="deal-closing-number">DEAL #{{ String(deal.id).padStart(6, '0') }}</span></header>
      <section class="deal-closing-title"><div><span class="eyebrow">SALE AGREEMENT & PAYMENT SCHEDULE</span><h1>Property transaction closing statement</h1><p>Transaction particulars and payment schedule for review and signature by the parties.</p></div><span class="deal-status" :class="deal.status">{{ deal.status.replace(/_/g, ' ') }}</span></section>
      <section class="deal-closing-parties"><div><small>Buyer / customer</small><strong>{{ deal.lead.name }}</strong><span>{{ deal.lead.phone }}</span></div><div><small>Brokerage / seller representative</small><strong>{{ auth.bootstrap?.company.name }}</strong><span>{{ deal.salesperson_name || 'Workspace representative' }}</span></div><div><small>Document date</small><strong>{{ date(new Date().toISOString().slice(0, 10)) }}</strong></div></section>
      <section class="deal-closing-section"><h2>Property and transaction</h2><div class="deal-closing-grid"><div><small>Property / unit</small><strong>{{ deal.property?.title || 'Property not linked' }}</strong></div><div><small>Unit reference</small><strong>{{ deal.property?.reference_code || '—' }}</strong></div><div><small>Location</small><strong>{{ deal.property?.location || '—' }}</strong></div><div><small>Agreed purchase price</small><strong>{{ money(deal.agreed_price_minor_units) }}</strong></div></div></section>
      <section class="deal-closing-section"><h2>Payment details</h2><div class="deal-payment-summary"><div><small>Payment method</small><strong>{{ paymentMethod(deal.payment_method) }}</strong></div><div><small>Amount received</small><strong>{{ money(deal.amount_received_minor_units) }}</strong></div><div><small>Received on</small><strong>{{ date(deal.payment_received_on) }}</strong></div><div><small>Payment reference</small><strong>{{ deal.payment_reference || '—' }}</strong></div><div class="deal-balance-due"><small>Outstanding balance</small><strong>{{ money(deal.balance_due_minor_units) }}</strong></div></div>
        <div class="deal-payment-terms"><small>Agreed payment terms / installment schedule</small><p>{{ deal.payment_terms || 'No additional installment terms recorded.' }}</p></div>
      </section>
      <section class="deal-closing-acknowledgment"><h2>Transaction acknowledgment</h2><p>The parties confirm that the property and payment details shown in this schedule reflect the agreed transaction. This schedule should be read together with the complete sale contract executed by the parties. The signed contract, including any additional terms and attachments, is the controlling agreement.</p><div class="deal-signature-grid"><div><strong>Buyer / customer</strong><span>Printed name: {{ deal.lead.name }}</span><i></i><small>Signature and date</small></div><div><strong>Authorized workspace representative</strong><span>Printed name: {{ deal.salesperson_name || ' ' }}</span><i></i><small>Signature and date</small></div><div><strong>Witness (optional)</strong><span>Printed name</span><i></i><small>Signature and date</small></div></div></section>
      <footer class="deal-closing-footer"><span>Generated from workspace deal records · Currency: EGP</span><span>Attach the fully executed sale contract as a signed PDF to close this deal in Exousia Realty.</span></footer>
    </article>
  </main>
</template>
