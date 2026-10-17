<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { AlertCircle, ArrowRight, Check, Clock3, CreditCard, RefreshCw, ShieldCheck } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

type Plan = { id: number; slug: string; name: string; description: string; monthly_price_minor: number | null; yearly_price_minor: number | null; currency: string; features: string[]; featured: boolean; trial_days: number | null }
const auth = useAuthStore(); const router = useRouter(); const plans = ref<Plan[]>([]); const status = ref<any>(null); const payments = ref<any[]>([])
const loading = ref(true); const saving = ref(false); const error = ref(''); const yearly = ref(false); const selectedPlan = ref<number | null>(null)
const canManage = computed(() => ['owner', 'admin'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('manage_billing'))
const money = (minor: number | null) => minor === null ? '—' : new Intl.NumberFormat('en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 0 }).format(minor / 100)
async function refresh() {
  loading.value = true; error.value = ''
  try { const [planResponse, billingResponse] = await Promise.all([api.get('/subscription-plans'), api.get('/subscriptions')]); plans.value = planResponse.data.data; status.value = { ...billingResponse.data.subscription, paymob_available: billingResponse.data.paymob_available }; payments.value = billingResponse.data.payments; selectedPlan.value ??= plans.value.find(p => p.slug === 'professional')?.id ?? plans.value.find(p => !p.trial_days)?.id ?? null }
  catch (e: any) { error.value = e.response?.data?.message || 'Unable to load your subscription details.' }
  finally { loading.value = false }
}
async function startTrial() {
  saving.value = true; error.value = ''
  try { await api.post('/subscriptions/free-trial'); await auth.load(); await refresh(); await router.replace('/dashboard') }
  catch (e: any) { error.value = e.response?.data?.errors?.plan?.[0] || e.response?.data?.message || 'Unable to start the free plan.' }
  finally { saving.value = false }
}
async function checkout() {
  if (!selectedPlan.value) return
  saving.value = true; error.value = ''
  try { const response = await api.post('/subscriptions/checkout', { plan_id: selectedPlan.value, billing_cycle: yearly.value ? 'year' : 'month' }); window.location.assign(response.data.checkout_url) }
  catch (e: any) { error.value = e.response?.data?.message || 'Unable to start payment.'; saving.value = false }
}
const endLabel = computed(() => status.value?.ends_at ? new Intl.DateTimeFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { dateStyle: 'long' }).format(new Date(status.value.ends_at)) : '—')
onMounted(refresh)
</script>

<template>
  <div class="billing-view">
    <header class="page-heading"><div><span class="eyebrow">COMPANY SUBSCRIPTION</span><h1>Plans & billing</h1><p>Manage the plan for {{ auth.bootstrap?.company.name }}.</p></div><button class="button secondary" @click="refresh"><RefreshCw :size="15" />Refresh</button></header>
    <div v-if="error" class="billing-error"><AlertCircle :size="16" />{{ error }}</div>
    <div v-if="loading" class="billing-loading">Loading subscription…</div>
    <template v-else>
      <div v-if="status?.company_suspended" class="billing-error"><AlertCircle :size="16"/>This company workspace has been suspended by Exousia. Contact platform support to restore access. <span v-if="status.suspension_reason">{{ status.suspension_reason }}</span></div>
      <section class="billing-current panel"><div class="billing-current-icon"><ShieldCheck :size="23" /></div><div class="billing-current-copy"><span class="eyebrow">CURRENT ACCESS</span><h2>{{ status?.plan?.name || 'No active plan' }}</h2><p v-if="status?.active">{{ status.status === 'trialing' ? 'Free trial ends' : 'Access through' }} {{ endLabel }} · {{ status.billing_cycle === 'year' ? 'Annual' : status.billing_cycle === 'month' ? 'Monthly' : '14-day trial' }}</p><p v-else-if="status?.status === 'expired'">Your access period has ended. Choose a paid plan to continue.</p><p v-else>Choose the free 14-day plan or subscribe to a paid plan to open the workspace.</p><p v-if="status?.scheduled_plan" class="billing-scheduled">{{ status.scheduled_plan.name }} starts {{ new Intl.DateTimeFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { dateStyle: 'medium' }).format(new Date(status.scheduled_plan.starts_at)) }} after your current term.</p></div><span class="billing-state" :class="{ active: status?.active }"><i></i>{{ status?.active ? status.status : 'Action needed' }}</span></section>

      <section v-if="canManage && status?.free_trial_available && !status?.company_suspended" class="trial-offer"><div><span class="eyebrow">A CHANCE TO EXPLORE</span><h2>Start with 14 days free.</h2><p>Full workspace access for two weeks. The free plan is available once per company and cannot be renewed.</p></div><button class="button primary" :disabled="saving" @click="startTrial"><Clock3 :size="16" />{{ saving ? 'Starting…' : 'Start free plan' }}</button></section>

      <section class="billing-plans"><div class="billing-section-head"><div><span class="eyebrow">PAID WORKSPACE PLANS</span><h2>Choose your plan.</h2></div><div class="plans-cycle"><button :class="{ active: !yearly }" @click="yearly = false">Monthly</button><button :class="{ active: yearly }" @click="yearly = true">Yearly <span>2 months free</span></button></div></div>
        <div class="billing-plan-grid"><button v-for="plan in plans.filter(p => !p.trial_days)" :key="plan.id" class="billing-plan" :class="{ selected: selectedPlan === plan.id, featured: plan.featured }" @click="selectedPlan = plan.id"><span class="billing-plan-top"><strong>{{ plan.name }}</strong><i v-if="plan.featured">POPULAR</i></span><b>{{ money(yearly ? plan.yearly_price_minor : plan.monthly_price_minor) }}<small> / {{ yearly ? 'year' : 'month' }}</small></b><span class="billing-plan-description">{{ plan.description }}</span><ul><li v-for="feature in plan.features" :key="feature"><Check :size="13" />{{ feature }}</li></ul></button></div>
      </section>

      <section class="billing-checkout panel"><div><span class="eyebrow">SECURE CHECKOUT</span><h2>Continue with Paymob</h2><p>Payment is collected upfront for the selected term. Renewals are manual; the plan does not auto-charge your card.</p></div><form @submit.prevent="checkout"><button class="button primary" :disabled="saving || !selectedPlan || !canManage || !status?.paymob_available || status?.company_suspended"><CreditCard :size="16" />{{ saving ? 'Opening checkout…' : 'Pay with Paymob' }}<ArrowRight :size="15" /></button></form><div v-if="!status?.paymob_available" class="billing-config-note">Online payment is temporarily unavailable. Please contact Exousia support.</div></section>

      <section class="billing-assurance"><ShieldCheck :size="17" /><span>Card details are entered and protected on Paymob’s hosted checkout. Exousia stores payment references and status only.</span></section>
      <section v-if="payments.length" class="panel billing-history"><header><div><span class="eyebrow">PAYMENT HISTORY</span><h2>Recent payments</h2></div></header><div v-for="payment in payments" :key="payment.id" class="billing-payment-row"><span><strong>{{ payment.plan }}</strong><small>{{ payment.cycle === 'year' ? 'Annual' : 'Monthly' }} · {{ new Date(payment.created_at).toLocaleDateString() }}</small></span><b>{{ money(payment.amount_minor) }}</b><i :class="payment.status">{{ payment.status }}</i></div></section>
    </template>
  </div>
</template>
