<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { CalendarCheck, Check, Clock3, Plus } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'
import Modal from '../components/Modal.vue'
import { useTasksStore } from '../stores/tasks'

type TaskBucket = 'all' | 'overdue' | 'today' | 'upcoming'
const auth = useAuthStore()
const taskStore = useTasksStore()
const tasks = ref<any[]>([])
const counts = ref<Record<TaskBucket, number>>({ all: 0, overdue: 0, today: 0, upcoming: 0 })
const bucket = ref<TaskBucket>('all')
const bucketOptions: [TaskBucket, string][] = [['all', 'All open'], ['overdue', 'Overdue'], ['today', 'Due today'], ['upcoming', 'Upcoming']]
const loading = ref(true)
const showCreate = ref(false)
const saving = ref(false)
const error = ref('')
const form = reactive({ title: '', description: '', assigned_to: auth.bootstrap?.user.id || 0, priority: 'normal', due_at: '' })

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/tasks', { params: { bucket: bucket.value } })
    tasks.value = data.data
    counts.value = data.task_counts || counts.value
    taskStore.setOpenCount(Number(counts.value.all) || 0)
  } finally {
    loading.value = false
  }
}

async function selectBucket(value: TaskBucket) {
  bucket.value = value
  await load()
}

async function toggle(id: number) {
  await api.patch(`/tasks/${id}/complete`)
  await load()
}

async function create() {
  saving.value = true
  error.value = ''
  try {
    await api.post('/tasks', form)
    showCreate.value = false
    form.title = ''
    form.description = ''
    form.due_at = ''
    bucket.value = 'all'
    await load()
  } catch (e: any) {
    error.value = Object.values(e.response?.data?.errors || { error: [e.response?.data?.message || 'Unable to create task.'] }).flat().join(' ')
  } finally {
    saving.value = false
  }
}

const relative = (date: string) => {
  const due = new Date(date)
  const today = new Date()
  const startToday = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const startDue = new Date(due.getFullYear(), due.getMonth(), due.getDate())
  const diff = Math.round((startDue.getTime() - startToday.getTime()) / 86400000)
  return diff < 0 ? `${Math.abs(diff)}d overdue` : diff === 0 ? 'Due today' : diff === 1 ? 'Tomorrow' : `In ${diff} days`
}

onMounted(load)
</script>

<template>
  <div>
    <header class="page-heading">
      <div><span class="eyebrow">FOLLOW-THROUGH</span><h1>Tasks</h1><p>Your focused list of calls, meetings, and follow-ups.</p></div>
      <button class="button primary" @click="showCreate = true"><Plus />New task</button>
    </header>
    <section class="panel task-page-panel">
      <nav class="task-tabs" aria-label="Filter follow-up tasks">
        <button v-for="item in bucketOptions" :key="item[0]" :class="{ active: bucket === item[0] }" :aria-pressed="bucket === item[0]" @click="selectBucket(item[0])">
          {{ item[1] }} <span>{{ counts[item[0]] }}</span>
        </button>
      </nav>
      <div v-if="loading" class="task-loading"><i v-for="n in 4" :key="n" /></div>
      <div v-else-if="!tasks.length" class="large-empty">
        <div><CalendarCheck /></div><h2>{{ bucket === 'all' ? 'Your day is clear.' : `Nothing ${bucket === 'overdue' ? 'overdue' : bucket === 'today' ? 'due today' : 'coming up'}.` }}</h2>
        <p>{{ bucket === 'all' ? 'There are no outstanding follow-ups for this workspace.' : 'This list will update as your team schedules follow-ups.' }}</p>
        <button v-if="bucket !== 'all' && counts.all" class="button secondary" @click="selectBucket('all')">View all open tasks</button>
      </div>
      <div v-else class="full-task-list">
        <article v-for="task in tasks" :key="task.id">
          <button class="complete-button" :aria-label="`Complete ${task.title}`" @click="toggle(task.id)"><Check /></button>
          <div class="task-body"><strong>{{ task.title }}</strong><p v-if="task.description">{{ task.description }}</p><div><span v-if="task.lead">{{ task.lead.name }}</span><span>{{ task.assignee.name }}</span><span class="task-priority" :class="`priority-${task.priority}`">{{ task.priority }} priority</span></div></div>
          <time :class="{ overdue: new Date(task.due_at) < new Date() }"><Clock3 />{{ relative(task.due_at) }}<small>{{ new Intl.DateTimeFormat('en-EG', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }).format(new Date(task.due_at)) }}</small></time>
        </article>
      </div>
    </section>
    <Modal v-if="showCreate" title="Create a follow-up" description="Assign a clear next action and due time." @close="showCreate = false">
      <form class="form-grid" @submit.prevent="create">
        <label class="full">Task title<input v-model="form.title" required placeholder="Call client with availability update"></label>
        <label>Assigned to<select v-model="form.assigned_to" required><option v-for="member in auth.bootstrap?.members" :key="member.id" :value="member.id">{{ member.name }}</option></select></label>
        <label>Priority<select v-model="form.priority"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></select></label>
        <label class="full">Due at<input v-model="form.due_at" type="datetime-local" required></label>
        <label class="full">Notes<input v-model="form.description" placeholder="Optional context"></label>
        <div v-if="error" class="form-error full">{{ error }}</div>
        <div class="form-actions full"><button type="button" class="button secondary" @click="showCreate = false">Cancel</button><button class="button primary" :disabled="saving">{{ saving ? 'Saving…' : 'Create task' }}</button></div>
      </form>
    </Modal>
  </div>
</template>
