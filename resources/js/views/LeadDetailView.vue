<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { AlertCircle, ArrowLeft, Building2, CalendarPlus, Mail, MapPin, MessageCircle, Phone, Plus, RefreshCw, UserRound } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'
import StatusBadge from '../components/StatusBadge.vue'

const route = useRoute()
const auth = useAuthStore()
const lead = ref<any>(null)
const loading = ref(true)
const error = ref('')
const saving = ref(false)
const matches = ref<any[]>([])
const matchesLoading = ref(false)
const matchesError = ref('')
const activity = reactive({ type: 'note', title: '', description: '' })

const initials = computed(() => String(lead.value?.name || '?').split(/\s+/).filter(Boolean).map((part: string) => part.charAt(0)).join('').slice(0, 2).toUpperCase())
const activities = computed(() => Array.isArray(lead.value?.activities) ? lead.value.activities : [])
const stageLabel = computed(() => lead.value?.stage?.name || 'Stage unavailable')
const stageColor = computed(() => lead.value?.stage?.color || '#64748b')
const assigneeName = computed(() => lead.value?.assignee?.name || 'Unassigned')
const sourceLabel = computed(() => {
  const labels: Record<string, string> = { manual: 'Manual entry', referral: 'Referral', facebook: 'Facebook', instagram: 'Instagram', website: 'Website', 'property portal': 'Property portal', whatsapp: 'WhatsApp', 'phone call': 'Phone call', 'walk-in': 'Walk-in', partner: 'Partner', other: 'Other' }
  const source = String(lead.value?.source || '')
  return labels[source] || source.replace(/\b\w/g, letter => letter.toUpperCase()) || 'Unknown'
})
const assigneeInitial = computed(() => lead.value?.assignee?.name?.charAt(0)?.toUpperCase() || '—')
const whatsappUrl = computed(() => {
  const phone = String(lead.value?.phone_normalized || '').replace(/\D/g, '')
  return phone ? `https://wa.me/${phone}` : null
})

async function load() {
  loading.value = true
  error.value = ''
  lead.value = null
  try {
    const { data } = await api.get(`/leads/${route.params.id}`, { timeout: 15000 })
    lead.value = data
    await loadMatches()
  } catch (exception: any) {
    error.value = exception.response?.status === 404 ? 'This lead was not found or you do not have access to it.' : exception.response?.data?.message || 'We could not load this lead. Please try again.'
  } finally {
    loading.value = false
  }
}

async function loadMatches() {
  if (!lead.value) return
  matchesLoading.value = true
  matchesError.value = ''
  try {
    const { data } = await api.get(`/leads/${lead.value.id}/matches`, { timeout: 15000 })
    matches.value = data.matches || []
  } catch (exception: any) {
    matches.value = []
    matchesError.value = exception.response?.status === 403
      ? 'You do not have access to workspace inventory.'
      : exception.response?.data?.message || 'Property matches are currently unavailable.'
  } finally {
    matchesLoading.value = false
  }
}

async function addActivity() {
  if (!lead.value) return
  saving.value = true
  error.value = ''
  try {
    await api.post(`/leads/${lead.value.id}/activities`, activity)
    activity.title = ''
    activity.description = ''
    await load()
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'The activity could not be saved.'
  } finally {
    saving.value = false
  }
}

async function changeStage() {
  if (!lead.value) return
  const previousStage = lead.value.stage?.id
  error.value = ''
  try {
    await api.put(`/leads/${lead.value.id}`, { pipeline_stage_id: lead.value.pipeline_stage_id })
    await load()
  } catch (exception: any) {
    lead.value.pipeline_stage_id = previousStage
    error.value = exception.response?.data?.message || 'The stage could not be updated.'
  }
}

function formatDate(value?: string) {
  if (!value) return 'Date unavailable'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Date unavailable' : new Intl.DateTimeFormat('en-EG', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(parsed)
}

function formatMoney(value?: number | null) {
  return value == null ? 'Not set' : new Intl.NumberFormat('en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 0 }).format(value)
}

function formatEgp(value: string | number) {
  return new Intl.NumberFormat('en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 0 }).format(Number(value))
}

watch(() => route.params.id, load, { immediate: true })
</script>

<template>
  <div v-if="loading" class="detail-loading" aria-label="Loading lead"><i /><i /><i /></div>
  <section v-else-if="error && !lead" class="panel lead-load-error">
    <div class="load-error-icon"><AlertCircle /></div><span class="eyebrow">LEAD UNAVAILABLE</span><h1>We couldn’t open this lead.</h1><p>{{ error }}</p>
    <div><RouterLink to="/leads" class="button secondary"><ArrowLeft />Back to leads</RouterLink><button class="button primary" @click="load"><RefreshCw />Try again</button></div>
  </section>
  <div v-else-if="lead" class="lead-detail">
    <RouterLink to="/leads" class="back-link"><ArrowLeft />Back to leads</RouterLink>
    <div v-if="error" class="detail-alert"><AlertCircle />{{ error }}<button @click="error = ''">Dismiss</button></div>
    <header class="lead-hero">
      <div class="lead-avatar large">{{ initials }}</div>
      <div class="lead-title"><span class="eyebrow">LEAD #{{ lead.id }}</span><h1>{{ lead.name || 'Unnamed lead' }}</h1><div><a v-if="lead.phone_normalized" :href="`tel:${lead.phone_normalized}`"><Phone />{{ lead.phone_original || lead.phone_normalized }}</a><a v-if="lead.email" :href="`mailto:${lead.email}`"><Mail />{{ lead.email }}</a></div></div>
      <div class="lead-hero-actions"><a v-if="whatsappUrl" class="button secondary" :href="whatsappUrl" target="_blank" rel="noopener"><MessageCircle />WhatsApp</a><button class="button primary"><CalendarPlus />Schedule follow-up</button></div>
    </header>
    <div class="detail-grid">
      <section class="detail-main"><article class="panel">
        <header class="section-header"><div><span class="eyebrow">JOURNEY</span><h2>Activity timeline</h2></div></header>
        <form class="activity-composer" @submit.prevent="addActivity"><select v-model="activity.type"><option value="note">Note</option><option value="call">Call</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="meeting">Meeting</option></select><input v-model="activity.title" required placeholder="What happened?"><textarea v-model="activity.description" placeholder="Add useful context for the team…"></textarea><button class="button primary" :disabled="saving"><Plus />{{ saving ? 'Saving…' : 'Add activity' }}</button></form>
        <div v-if="activities.length" class="timeline"><div v-for="item in activities" :key="item.id" class="timeline-item"><span class="timeline-icon"><MessageCircle v-if="item.type === 'whatsapp'" /><Phone v-else-if="item.type === 'call'" /><UserRound v-else /></span><div><header><strong>{{ item.title || 'Activity' }}</strong><time>{{ formatDate(item.occurred_at) }}</time></header><p v-if="item.description">{{ item.description }}</p><small>{{ item.user?.name || 'System' }} · {{ item.type }}</small></div></div></div>
        <div v-else class="empty-mini"><MessageCircle /><strong>No activity yet</strong><span>Add the first note or communication log.</span></div>
      </article></section>
      <aside class="detail-aside">
        <article class="panel detail-card"><span class="eyebrow">STATUS</span><select v-model="lead.pipeline_stage_id" @change="changeStage"><option v-for="pipelineStage in auth.bootstrap?.stages || []" :key="pipelineStage.id" :value="pipelineStage.id">{{ pipelineStage.name }}</option></select><StatusBadge :label="stageLabel" :color="stageColor" /></article>
        <article class="panel detail-card"><span class="eyebrow">REQUIREMENTS</span><dl><div><dt><Building2 />Property</dt><dd>{{ lead.property_type || 'Any type' }}</dd></div><div><dt><MapPin />Locations</dt><dd v-if="lead.preferred_locations?.length" class="detail-location-tags"><span v-for="location in lead.preferred_locations" :key="location"><MapPin />{{ location }}</span></dd><dd v-else>Flexible</dd></div><div><dt>Budget</dt><dd>{{ formatMoney(lead.budget_max) }}</dd></div><div><dt>Intent</dt><dd class="capitalize">{{ lead.intent || 'Not set' }}</dd></div></dl></article>
        <article class="panel detail-card match-card"><div class="match-heading"><span class="eyebrow">PROPERTY MATCHES</span><button class="icon-button" :disabled="matchesLoading" aria-label="Refresh property matches" @click="loadMatches"><RefreshCw :size="14" /></button></div><p v-if="matchesError" class="match-message">{{ matchesError }}</p><p v-else-if="matchesLoading" class="match-message">Finding suitable listingsâ€¦</p><p v-else-if="!matches.length" class="match-message">No available listings match these requirements yet.</p><div v-else class="match-list"><article v-for="match in matches" :key="match.listing.id" class="match-item"><div class="match-item-title"><strong>{{ match.listing.title }}</strong><span>{{ match.score }}%</span></div><small>{{ match.listing.location }} Â· {{ match.listing.bedrooms ?? 'â€”' }} bd Â· {{ formatEgp(match.listing.price_egp) }}</small><div class="match-reasons"><span v-for="reason in match.reasons" :key="reason">{{ reason }}</span></div></article></div></article>
        <article class="panel detail-card"><span class="eyebrow">OWNERSHIP</span><div class="assignee"><span class="user-avatar">{{ assigneeInitial }}</span><div><strong>{{ assigneeName }}</strong><small>Responsible agent</small></div></div><div class="source-line"><span><strong>Lead source</strong><small>Reported in Lead sources</small></span><b class="lead-source-badge">{{ sourceLabel }}</b></div></article>
      </aside>
    </div>
  </div>
</template>
