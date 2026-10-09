<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { UserPlus, Users, ShieldCheck } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'

interface TeamMember { id: number; user: { id: number; name: string; email: string }; role: string; status: 'active' | 'inactive'; joined_at: string | null }
interface Invitation { id: number; name: string; email: string; role: string; status: 'pending' | 'expired'; expires_at: string }
const auth = useAuthStore()
const members = ref<TeamMember[]>([])
const invitations = ref<Invitation[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const success = ref('')
const form = reactive({ name: '', email: '', role: 'agent' })
const canManage = computed(() => ['owner', 'admin'].includes(auth.bootstrap?.membership.role || ''))
const activeCount = computed(() => members.value.filter(member => member.status === 'active').length)
const roles = ['admin', 'manager', 'agent', 'operations', 'finance']

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/team/members')
    members.value = data.data
    invitations.value = data.invitations || []
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to load the team.'
  } finally { loading.value = false }
}

async function addMember() {
  saving.value = true; error.value = ''; success.value = ''
  try {
    const { data } = await api.post('/team/members', form)
    if (data.member) success.value = `${data.member.user.name} is now a ${data.member.role}.`
    else success.value = `Invitation sent to ${data.invitation.email}.`
    form.name = ''
    form.email = ''
    await load()
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to add this member.'] }).flat().join(' ')
  } finally { saving.value = false }
}

async function revokeInvitation(invitation: Invitation) {
  try {
    await api.delete(`/team/invitations/${invitation.id}`)
    invitations.value = invitations.value.filter(item => item.id !== invitation.id)
    success.value = `Invitation for ${invitation.email} was revoked.`
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to revoke this invitation.'
  }
}

async function updateMember(member: TeamMember, field: 'role' | 'status', value: string) {
  error.value = ''; success.value = ''
  const previous = member[field]
  member[field] = value as never
  try {
    const { data } = await api.patch(`/team/members/${member.id}`, { [field]: value })
    Object.assign(member, data)
    success.value = `${member.user.name}'s access was updated.`
  } catch (exception: any) {
    member[field] = previous as never
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to update this member.'] }).flat().join(' ')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <header class="page-heading"><div><span class="eyebrow">WORKSPACE SETTINGS</span><h1>Team</h1><p>Manage who can access {{ auth.bootstrap?.company.name }} and what they can do.</p></div><div class="team-count"><Users :size="18" />{{ activeCount }} active {{ activeCount === 1 ? 'member' : 'members' }}</div></header>
    <div v-if="error" class="detail-alert">{{ error }}<button @click="error = ''">Dismiss</button></div>
    <div v-if="success" class="team-success">{{ success }}</div>

    <section v-if="canManage" class="panel team-invite-panel">
      <div class="team-section-heading"><div><span class="eyebrow">ADD TO WORKSPACE</span><h2>Add a team member</h2><p>Existing accounts are added immediately. New teammates receive an email invitation to create their account.</p></div><span class="coming-icon"><UserPlus /></span></div>
      <form class="team-add-form" @submit.prevent="addMember">
        <label>Full name<input v-model="form.name" autocomplete="name" placeholder="Beshoy Adel" required></label>
        <label>Email address<input v-model="form.email" type="email" autocomplete="email" placeholder="name@company.com" required></label>
        <label>Workspace role<select v-model="form.role"><option v-for="role in roles" :key="role" :value="role">{{ role }}</option></select></label>
        <button class="button primary" :disabled="saving"><UserPlus :size="17" />{{ saving ? 'Adding…' : 'Add member' }}</button>
      </form>
    </section>

    <section class="panel team-list-panel">
      <div class="team-section-heading"><div><span class="eyebrow">MEMBERSHIP</span><h2>People in this workspace</h2><p>Role or access changes apply to this workspace only.</p></div><ShieldCheck class="team-shield" /></div>
      <div v-if="loading" class="team-loading">Loading team members…</div>
      <div v-else-if="!members.length" class="team-empty"><Users /><strong>No members found</strong></div>
      <div v-else class="team-table-wrap"><table class="team-table"><thead><tr><th>Member</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead><tbody><tr v-for="member in members" :key="member.id"><td><span class="team-person"><span class="lead-avatar">{{ member.user.name.split(' ').map(n => n[0]).join('').slice(0, 2) }}</span><span><strong>{{ member.user.name }}</strong><small>{{ member.user.email }}</small></span></span></td><td><select :value="member.role" :disabled="!canManage || member.role === 'owner' || member.user.id === auth.bootstrap?.user.id" @change="updateMember(member, 'role', ($event.target as HTMLSelectElement).value)"><option v-for="role in (member.role === 'owner' ? ['owner'] : roles)" :key="role" :value="role">{{ role }}</option></select></td><td><select :value="member.status" :disabled="!canManage || member.role === 'owner' || member.user.id === auth.bootstrap?.user.id" @change="updateMember(member, 'status', ($event.target as HTMLSelectElement).value)"><option value="active">Active</option><option value="inactive">Inactive</option></select></td><td>{{ member.joined_at ? new Intl.DateTimeFormat('en-EG', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(member.joined_at)) : '—' }}</td></tr></tbody></table></div>
      <div v-if="invitations.length" class="team-invitations"><h3>Invitations</h3><div class="team-table-wrap"><table class="team-table"><thead><tr><th>Invitee</th><th>Role</th><th>Status</th><th>Expires</th><th></th></tr></thead><tbody><tr v-for="invitation in invitations" :key="invitation.id"><td><span class="team-person"><span class="lead-avatar">{{ invitation.name.split(' ').map(n => n[0]).join('').slice(0, 2) }}</span><span><strong>{{ invitation.name }}</strong><small>{{ invitation.email }}</small></span></span></td><td>{{ invitation.role }}</td><td><span class="invitation-status" :class="invitation.status">{{ invitation.status }}</span></td><td>{{ new Intl.DateTimeFormat('en-EG', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(invitation.expires_at)) }}</td><td><button v-if="canManage && invitation.status === 'pending'" class="button subtle" @click="revokeInvitation(invitation)">Revoke</button></td></tr></tbody></table></div></div>
      <p v-if="!canManage" class="team-readonly-note">Only workspace owners and admins can change team access.</p>
    </section>
  </div>
</template>
