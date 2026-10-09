<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ArrowDownRight, ArrowRight, ArrowUpRight, CalendarDays, Check, Clock3, Phone, Plus, TrendingUp, UserRoundPlus, UsersRound } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'
import { useI18n } from '../i18n'
import StatusBadge from '../components/StatusBadge.vue'
const auth=useAuthStore(); const {t}=useI18n(); const data=ref<any>(null); const loading=ref(true)
onMounted(async()=>{try{data.value=(await api.get('/dashboard')).data}finally{loading.value=false}})
const firstName=computed(()=>auth.bootstrap?.user.name.split(' ')[0]); const canViewReports=computed(()=>['owner','admin','manager','finance','agent'].includes(auth.bootstrap?.membership.role||'')||auth.bootstrap?.membership.permissions.some(permission=>['view_reports','view_own_reports'].includes(permission))); const today=new Intl.DateTimeFormat(auth.locale==='ar'?'ar-EG':'en-EG',{weekday:'long',day:'numeric',month:'long'}).format(new Date())
const money=(value:number)=>new Intl.NumberFormat(auth.locale==='ar'?'ar-EG':'en-EG',{style:'currency',currency:'EGP',maximumFractionDigits:0}).format(value||0)
</script>
<template><div class="dashboard-page">
  <header class="page-heading"><div><span class="eyebrow">{{today}}</span><h1>{{t.welcome}}, {{firstName}}.</h1><p>Here’s what needs your attention across the brokerage today.</p></div><RouterLink to="/leads" class="button primary"><Plus :size="18"/>{{t.newLead}}</RouterLink></header>
  <div v-if="loading" class="skeleton-grid"><i v-for="n in 4" :key="n"/></div>
  <section v-else class="metrics-grid">
    <RouterLink to="/leads" class="metric-link" aria-label="View new leads"><article><div class="metric-icon sage"><UserRoundPlus/></div><span>New leads <small>THIS MONTH</small></span><strong>{{data.metrics.new_leads}}</strong><p><ArrowUpRight/> Live from your CRM</p></article></RouterLink>
    <RouterLink to="/leads" class="metric-link" aria-label="View active pipeline"><article><div class="metric-icon blue"><UsersRound/></div><span>Active pipeline <small>OPEN</small></span><strong>{{data.metrics.active_leads}}</strong><p><TrendingUp/> Opportunities in progress</p></article></RouterLink>
    <RouterLink to="/tasks" class="metric-link" aria-label="View overdue tasks"><article><div class="metric-icon amber"><Clock3/></div><span>Overdue tasks <small>NEEDS ACTION</small></span><strong>{{data.metrics.overdue_tasks}}</strong><p><ArrowDownRight/> Clear these first</p></article></RouterLink>
    <RouterLink v-if="canViewReports" to="/reports" class="metric-link" aria-label="View conversion reports"><article><div class="metric-icon violet"><TrendingUp/></div><span>Conversion <small>ALL TIME</small></span><strong>{{data.metrics.conversion_rate}}%</strong><p><ArrowUpRight/> Based on real won leads</p></article></RouterLink>
  </section>
  <section v-if="data" class="dashboard-grid">
    <article class="panel pipeline-panel"><header><div><span class="eyebrow">PIPELINE HEALTH</span><h2>Lead progression</h2></div><RouterLink to="/leads">View pipeline <ArrowRight/></RouterLink></header><div class="pipeline-track"><div v-for="stage in data.pipeline" :key="stage.id" class="pipeline-stage"><div><span :style="{background:stage.color}"></span><strong>{{auth.locale==='ar'&&stage.name_ar?stage.name_ar:stage.name}}</strong></div><b>{{stage.count}}</b><div class="stage-bar"><i :style="{width:`${Math.max(8,stage.count/Math.max(1,...data.pipeline.map((s:any)=>s.count))*100)}%`,background:stage.color}"></i></div></div></div></article>
    <article class="panel tasks-panel"><header><div><span class="eyebrow">FOCUS LIST</span><h2>Upcoming tasks</h2></div><RouterLink to="/tasks">View all <ArrowRight/></RouterLink></header><div v-if="!data.tasks.length" class="empty-mini"><Check/><strong>You’re all caught up</strong><span>No open follow-ups.</span></div><div v-else class="task-list"><div v-for="task in data.tasks" :key="task.id" class="task-row"><span class="task-check"></span><div><strong>{{task.title}}</strong><small>{{task.lead?.name||'General task'}}</small></div><time :class="{overdue:new Date(task.due_at)<new Date()}">{{new Intl.DateTimeFormat('en-EG',{day:'numeric',month:'short'}).format(new Date(task.due_at))}}</time></div></div></article>
    <article class="panel recent-panel"><header><div><span class="eyebrow">RECENT ACTIVITY</span><h2>Newest leads</h2></div><RouterLink to="/leads">All leads <ArrowRight/></RouterLink></header><div v-if="!data.recent_leads.length" class="empty-mini"><UserRoundPlus/><strong>Your pipeline is ready</strong><span>Add the first lead to begin.</span></div><div v-else class="recent-table"><RouterLink v-for="lead in data.recent_leads" :key="lead.id" :to="`/leads/${lead.id}`"><span class="lead-avatar">{{lead.name.split(' ').map((n:string)=>n[0]).join('').slice(0,2)}}</span><div><strong>{{lead.name}}</strong><small><Phone/>{{lead.phone_original}}</small></div><StatusBadge :label="auth.locale==='ar'&&lead.stage.name_ar?lead.stage.name_ar:lead.stage.name" :color="lead.stage.color"/><span class="lead-budget">{{money(lead.budget_max)}}</span><ArrowRight class="row-arrow"/></RouterLink></div></article>
    <article class="insight-card"><div><span class="eyebrow light">TODAY’S INSIGHT</span><h2>Speed wins attention.</h2><p>Follow up with new enquiries quickly while intent is highest.</p><RouterLink to="/leads">Review new leads <ArrowRight/></RouterLink></div><div class="insight-orbit"><i></i><CalendarDays/></div></article>
  </section>
</div></template>
