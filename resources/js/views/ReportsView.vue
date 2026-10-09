<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { BarChart3, BadgeCheck, BriefcaseBusiness, Download, Handshake, UsersRound } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const report = ref<any>(null)
const loading = ref(true)
const error = ref('')
const period = ref<'month' | 'quarter' | 'year'>('month')
const periodLabel = computed(() => ({ month: 'This month', quarter: 'This quarter', year: 'Year to date' }[period.value]))
const exporting = ref(false)
const formatter = computed(() => new Intl.NumberFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG'))
const money = (minor: number) => new Intl.NumberFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 2 }).format((minor || 0) / 100)
const maxFunnel = computed(() => Math.max(1, ...(report.value?.funnel || []).map((stage: any) => stage.count)))
const maxSource = computed(() => Math.max(1, ...(report.value?.sources || []).map((source: any) => source.lead_count)))
const maxDeals = computed(() => Math.max(1, ...(report.value?.deals_by_status || []).map((deal: any) => deal.count)))
const maxSalesUnits = computed(() => Math.max(1, ...(report.value?.sales_performance || []).map((row: any) => row.units_sold)))
const maxSalesValue = computed(() => Math.max(1, ...(report.value?.sales_performance || []).map((row: any) => row.agreed_value_minor_units)))
const topByUnits = computed(() => [...(report.value?.sales_performance || [])].sort((a: any, b: any) => b.units_sold - a.units_sold || b.agreed_value_minor_units - a.agreed_value_minor_units)[0])
const topByValue = computed(() => [...(report.value?.sales_performance || [])].filter((row: any) => row.priced_deals > 0).sort((a: any, b: any) => b.agreed_value_minor_units - a.agreed_value_minor_units)[0])
const formatPeriodDate = (date: string) => new Intl.DateTimeFormat(auth.locale === 'ar' ? 'ar-EG' : 'en-EG', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${date}T00:00:00`))
const titleCase = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase())
const comparisonText = (key: string, current: number) => {
  const previous = report.value?.comparison?.metrics?.[key]
  if (previous == null) return ''
  const difference = Math.round((current - previous) * 10) / 10
  return `${difference > 0 ? '+' : ''}${difference}${key.includes('rate') ? ' pts' : ''} vs previous period`
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    report.value = (await api.get('/reports', { params: { period: period.value } })).data
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to load reports.'
  } finally { loading.value = false }
}

async function exportCsv() {
  exporting.value = true
  error.value = ''
  try {
    const response = await api.get('/reports', { params: { period: period.value, format: 'csv' }, responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `exousia-report-${period.value}.csv`
    anchor.click()
    URL.revokeObjectURL(url)
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to export this report.'
  } finally { exporting.value = false }
}

watch(period, load)
onMounted(load)
</script>

<template>
  <div class="reports-page">
    <header class="page-heading reports-heading"><div><span class="eyebrow">BROKERAGE PERFORMANCE</span><h1>Reports</h1><p>Lead conversion and deal progress from your workspace records.</p></div><div class="reports-heading-actions"><label class="reports-period">Reporting period<select v-model="period"><option value="month">This month</option><option value="quarter">This quarter</option><option value="year">Year to date</option></select></label><button class="button secondary" :disabled="exporting || loading" @click="exportCsv"><Download />{{ exporting ? 'Preparing…' : 'Export CSV' }}</button></div></header>
    <div v-if="error" class="detail-alert">{{ error }}</div>
    <div v-else-if="loading" class="skeleton-grid"><i v-for="item in 4" :key="item"></i></div>
    <template v-else-if="report">
      <div class="reports-date-range">{{ periodLabel }} <span>{{ formatPeriodDate(report.period.from) }} – {{ formatPeriodDate(report.period.to) }}</span></div>
      <section class="report-metrics">
        <article><span class="report-icon sage"><UsersRound /></span><div><small>Leads created</small><strong>{{ formatter.format(report.metrics.leads) }}</strong><span>{{ formatter.format(report.metrics.won_leads) }} won · {{ formatter.format(report.metrics.lost_leads) }} lost</span><span class="report-comparison">{{ comparisonText('leads', report.metrics.leads) }}</span></div></article>
        <article><span class="report-icon violet"><BarChart3 /></span><div><small>Lead win rate</small><strong>{{ report.metrics.lead_win_rate }}%</strong><span>From leads created in this period</span><span class="report-comparison">{{ comparisonText('lead_win_rate', report.metrics.lead_win_rate) }}</span></div></article>
        <article><span class="report-icon amber"><Handshake /></span><div><small>Deals in progress</small><strong>{{ formatter.format(report.metrics.active_deals) }}</strong><span>{{ formatter.format(report.metrics.closed_deals) }} deals closed</span><span class="report-comparison">{{ comparisonText('active_deals', report.metrics.active_deals) }}</span></div></article>
        <article><span class="report-icon blue"><BadgeCheck /></span><div><small>Closed deal win rate</small><strong>{{ report.metrics.deal_win_rate }}%</strong><span>{{ formatter.format(report.metrics.deals) }} deals created in period</span><span class="report-comparison">{{ comparisonText('deal_win_rate', report.metrics.deal_win_rate) }}</span></div></article>
      </section>

      <section class="reports-grid">
        <article class="panel report-panel funnel-report"><header><div><span class="eyebrow">LEAD CONVERSION</span><h2>Pipeline by stage</h2><p>Current stage of leads created during this period.</p></div></header>
          <div v-if="!report.funnel.length" class="report-empty">No pipeline stages are configured.</div>
          <div v-else class="funnel-rows"><div v-for="stage in report.funnel" :key="stage.id" class="funnel-row"><div class="funnel-row-heading"><span><i :style="{ backgroundColor: stage.color }"></i>{{ auth.locale === 'ar' && stage.name_ar ? stage.name_ar : stage.name }}</span><strong>{{ formatter.format(stage.count) }}</strong></div><div class="report-track"><i :style="{ width: `${Math.max(stage.count ? 4 : 0, stage.count / maxFunnel * 100)}%`, backgroundColor: stage.color }"></i></div></div></div>
          <footer class="report-footnote">Won and lost are based on each lead’s current pipeline stage.</footer>
        </article>

        <article class="panel report-panel source-report"><header><div><span class="eyebrow">ACQUISITION</span><h2>Lead sources</h2><p>Which channels are bringing in leads that convert?</p></div></header>
          <div v-if="!report.sources.length" class="report-empty">No leads were created during this period.</div>
          <div v-else class="source-rows"><div v-for="source in report.sources" :key="source.source" class="source-row"><div class="source-row-heading"><strong>{{ titleCase(source.source) }}</strong><span>{{ formatter.format(source.lead_count) }} leads · {{ source.conversion_rate }}% won</span></div><div class="report-track"><i :style="{ width: `${Math.max(source.lead_count ? 4 : 0, source.lead_count / maxSource * 100)}%` }"></i></div></div></div>
          <footer class="report-footnote">Source comes from each lead’s Lead source field. Manually added leads default to Manual entry; campaign channels are not auto-tracked.</footer>
        </article>

        <article class="panel report-panel deal-report"><header><div><span class="eyebrow">SALES OUTCOMES</span><h2>Deals by stage</h2><p>Deal volume and agreed value; this is not cash collected.</p></div></header>
          <div class="deal-value-summary"><div><span><BriefcaseBusiness :size="15" /> Active deal value</span><strong>{{ money(report.metrics.active_deal_value_minor_units) }}</strong></div><div><span><BadgeCheck :size="15" /> Won deal value</span><strong>{{ money(report.metrics.won_deal_value_minor_units) }}</strong></div></div>
          <div v-if="!report.metrics.deals" class="report-empty">No deals were created during this period.</div>
          <div v-else class="deal-stage-rows"><div v-for="deal in report.deals_by_status" :key="deal.status" class="deal-stage-row"><span>{{ titleCase(deal.status) }}</span><div class="report-track"><i :class="deal.status" :style="{ width: `${Math.max(deal.count ? 4 : 0, deal.count / maxDeals * 100)}%` }"></i></div><strong>{{ formatter.format(deal.count) }}</strong><small>{{ money(deal.value_minor_units) }}</small></div></div>
          <footer class="report-footnote">Agreed transaction values are tracked in EGP piastres. Payments and commissions are not recorded here.</footer>
        </article>

        <article class="panel report-panel sales-performance-report"><header><div><span class="eyebrow">TEAM PERFORMANCE</span><h2>Sales leaderboard</h2><p>Won units and agreed sales value by employee.</p></div></header>
          <div v-if="topByUnits || topByValue" class="sales-leaders">
            <div v-if="topByUnits" class="sales-leader"><span class="report-icon sage"><UsersRound /></span><div><small>Most units sold</small><strong>{{ topByUnits.salesperson_name }}</strong><span>{{ formatter.format(topByUnits.units_sold) }} {{ topByUnits.units_sold === 1 ? 'unit' : 'units' }}</span></div></div>
            <div v-if="topByValue" class="sales-leader"><span class="report-icon amber"><BadgeCheck /></span><div><small>Highest agreed value</small><strong>{{ topByValue.salesperson_name }}</strong><span>{{ money(topByValue.agreed_value_minor_units) }}</span></div></div>
          </div>
          <div v-if="!report.sales_performance.length" class="report-empty">No deals were closed as won during this period.</div>
          <div v-else class="sales-performance-rows"><div v-for="(seller, index) in report.sales_performance" :key="seller.salesperson_membership_id ?? seller.salesperson_name" class="sales-performance-row"><div class="sales-performance-name"><span class="sales-rank">{{ Number(index) + 1 }}</span><strong>{{ seller.salesperson_name }}</strong></div><div class="sales-performance-units"><span>{{ formatter.format(seller.units_sold) }} {{ seller.units_sold === 1 ? 'unit' : 'units' }}</span><div class="report-track"><i :style="{ width: `${Math.max(seller.units_sold ? 4 : 0, seller.units_sold / maxSalesUnits * 100)}%` }"></i></div></div><div class="sales-performance-value"><strong>{{ money(seller.agreed_value_minor_units) }}</strong><small>{{ formatter.format(seller.priced_deals) }} of {{ formatter.format(seller.units_sold) }} with value entered</small><div class="report-track"><i :style="{ width: `${Math.max(seller.agreed_value_minor_units ? 4 : 0, seller.agreed_value_minor_units / maxSalesValue * 100)}%` }"></i></div></div></div></div>
          <footer class="report-footnote">A won deal counts as one unit. Sales are attributed to the lead owner when the deal closes. Value is recorded agreed price, not cash collected; deals without a price count toward units only.</footer>
        </article>
      </section>
    </template>
  </div>
</template>
