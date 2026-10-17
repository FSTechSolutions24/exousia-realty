<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ArrowRight, Check, ShieldCheck } from '@lucide/vue'
import { api } from '../api'

type Plan = { id: number; slug: string; name: string; description: string; monthly_price_minor: number | null; yearly_price_minor: number | null; currency: string; features: string[]; featured: boolean; trial_days: number | null }
const plans = ref<Plan[]>([]); const yearly = ref(false); const loading = ref(true); const error = ref('')
onMounted(async () => { try { plans.value = (await api.get('/subscription-plans')).data.data } catch { error.value = 'Plans are temporarily unavailable.' } finally { loading.value = false } })
const money = (minor: number | null) => minor === null ? '—' : new Intl.NumberFormat('en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 0 }).format(minor / 100)
</script>

<template>
  <main class="plans-page">
    <header class="plans-nav"><RouterLink to="/" class="landing-brand"><span class="brand-symbol">E<span></span></span><span><strong>EXOUSIA</strong><small>REALTY</small></span></RouterLink><RouterLink to="/login" class="plans-nav-login">Sign in <ArrowRight :size="15" /></RouterLink></header>
    <section class="plans-heading"><span class="eyebrow">SIMPLE PLANS FOR REAL ESTATE TEAMS</span><h1>A better workspace<br /><em>for every next move.</em></h1><p>Choose a plan for your brokerage. Start with 14 days free, or choose a paid plan and check out securely with Paymob.</p><div class="plans-cycle"><button :class="{ active: !yearly }" @click="yearly = false">Monthly</button><button :class="{ active: yearly }" @click="yearly = true">Yearly <span>2 months free</span></button></div></section>
    <div v-if="error" class="plans-error">{{ error }}</div><div v-if="loading" class="plans-loading">Preparing your plans…</div>
    <section v-else class="plans-grid">
      <article v-for="plan in plans" :key="plan.id" class="plan-card" :class="{ featured: plan.featured, trial: plan.trial_days }">
        <span v-if="plan.featured" class="plan-popular">MOST POPULAR</span><span v-if="plan.trial_days" class="plan-popular free-popular">START HERE</span>
        <span class="plan-name">{{ plan.name }}</span><h2>{{ plan.trial_days ? '14 days' : money(yearly ? plan.yearly_price_minor : plan.monthly_price_minor) }}<small v-if="!plan.trial_days"> / {{ yearly ? 'year' : 'month' }}</small></h2>
        <p>{{ plan.description }}</p><ul><li v-for="feature in plan.features" :key="feature"><Check :size="15" />{{ feature }}</li></ul>
        <RouterLink :to="plan.trial_days ? '/register' : '/register'" class="plan-cta">{{ plan.trial_days ? 'Create your workspace' : 'Choose this plan' }} <ArrowRight :size="15" /></RouterLink>
      </article>
    </section>
    <section class="plans-assurance"><ShieldCheck :size="19" /><span>Payments are handled by Paymob. Exousia never receives or stores your full card details.</span></section>
    <footer class="plans-footer"><RouterLink to="/">← Back to Exousia Realty</RouterLink><span>Plans are billed upfront for the selected period. Renewals are manual.</span></footer>
  </main>
</template>
