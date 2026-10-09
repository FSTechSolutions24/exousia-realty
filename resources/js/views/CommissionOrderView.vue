<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ArrowLeft, BadgeCheck, Printer } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const entry = ref<any>(null)
const loading = ref(true)
const error = ref('')
const money = (minor: number) => new Intl.NumberFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 2 }).format((minor || 0) / 100)
const date = (value: string | null) => new Intl.DateTimeFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { dateStyle: 'long' }).format(value ? new Date(value) : new Date())
const orderNumber = () => `COM-${String(entry.value?.id || '').padStart(6, '0')}`
function printOrder() { window.print() }

onMounted(async () => {
  try { entry.value = (await api.get(`/commissions/${route.params.id}`)).data }
  catch (exception: any) { error.value = exception.response?.data?.message || 'Unable to load this commission order.' }
  finally { loading.value = false }
})
</script>

<template>
  <div class="commission-order-page">
    <div class="commission-order-actions"><button class="button secondary" @click="router.push('/commissions')"><ArrowLeft :size="15" />Back to commissions</button><button class="button primary" :disabled="!entry" @click="printOrder"><Printer :size="15" />Print order</button></div>
    <div v-if="loading" class="panel commission-order-loading">Preparing commission payment order…</div>
    <div v-else-if="error" class="detail-alert">{{ error }}</div>
    <article v-else-if="entry" class="commission-order-sheet">
      <header class="commission-order-header"><div class="commission-order-brand"><span class="brand-mark">E</span><span><strong>EXOUSIA REALTY</strong><small>WORKSPACE COMMISSION ORDER</small></span></div><span class="commission-order-copy">EMPLOYEE COPY</span></header>
      <div class="commission-order-title"><div><span class="eyebrow">COMMISSION PAYMENT</span><h1>Employee commission order</h1><p>Payment is processed after the signed order is returned and verified.</p></div><span class="commission-status" :class="entry.status">{{ entry.status.replace(/_/g, ' ') }}</span></div>
      <div class="commission-order-meta"><div><small>Order number</small><strong>{{ orderNumber() }}</strong></div><div><small>Prepared on</small><strong>{{ date(entry.created_at) }}</strong></div><div><small>Deal reference</small><strong>#{{ entry.deal_id }}</strong></div></div>
      <section class="commission-order-section"><h2>Employee</h2><div class="commission-order-employee"><div><small>Full name</small><strong>{{ entry.payee_name }}</strong></div><div><small>Email address</small><strong>{{ entry.payee_email || '—' }}</strong></div><div><small>Workspace</small><strong>{{ auth.bootstrap?.company.name }}</strong></div></div></section>
      <section class="commission-order-section"><h2>Sold unit and calculation</h2><div class="commission-order-details"><div class="commission-order-unit"><BadgeCheck :size="20" /><span><small>Property / unit</small><strong>{{ entry.unit_reference }}</strong><small v-if="entry.deal?.property">{{ entry.deal.property.location }}</small></span></div><div class="commission-order-detail-grid"><div><small>Calculation</small><strong>{{ entry.calculation_type === 'percentage' ? `${entry.rate_percent}% commission` : 'Fixed commission' }}</strong></div><div v-if="entry.calculation_type === 'percentage'"><small>Agreed unit value</small><strong>{{ money(entry.base_amount_minor_units) }}</strong></div><div v-if="entry.calculation_type === 'percentage'"><small>Commission rate</small><strong>{{ entry.rate_percent }}%</strong></div><div v-if="entry.reference"><small>Internal reference</small><strong>{{ entry.reference }}</strong></div><div v-if="entry.due_on"><small>Due date</small><strong>{{ date(entry.due_on) }}</strong></div></div></div>
        <div class="commission-order-total"><span>Commission payable</span><strong>{{ money(entry.amount_minor_units) }}</strong><small>Currency: EGP</small></div>
      </section>
      <section class="commission-order-acknowledgment"><h2>Employee acknowledgment</h2><p>I confirm that I have reviewed the commission amount and unit details above. My signature acknowledges this commission payment order.</p><div class="commission-signature-grid"><div><span>Employee signature</span><i></i></div><div><span>Printed name</span><i>{{ entry.payee_name }}</i></div><div><span>Date signed</span><i></i></div></div></section>
      <section class="commission-order-approval"><h2>For workspace administration</h2><div><span>Prepared / verified by</span><i></i><span>Payment reference</span><i></i><span>Paid on</span><i></i></div></section>
      <footer class="commission-order-footer"><span>Generated from Exousia Realty commission records.</span><span>This order is not proof of payment until the workspace records it as paid.</span></footer>
    </article>
  </div>
</template>
