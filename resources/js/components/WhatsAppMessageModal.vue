<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { Building2, MessageCircle } from '@lucide/vue'
import { api } from '../api'
import Modal from './Modal.vue'

const props = defineProps<{ leadId: number }>()
const emit = defineEmits<{ close: []; sent: [] }>()
const loading = ref(true); const saving = ref(false); const error = ref(''); const data = ref<any>(null)
const form = reactive({ template_id: '', property_listing_id: '' })
const template = computed(() => data.value?.templates?.find((item: any) => String(item.id) === form.template_id))
const property = computed(() => data.value?.properties?.find((item: any) => String(item.id) === form.property_listing_id))
const preview = computed(() => {
  if (!template.value) return 'Choose a template to preview the message.'
  const values: Record<string, string> = { lead_name: data.value?.lead?.name || '', agent_name: 'Your name', company_name: 'Your company', property_title: property.value?.title || '[select a property]', property_reference: property.value?.reference_code || '', property_location: property.value?.location || '', property_price: property.value ? `${new Intl.NumberFormat('en-EG').format(property.value.price_minor_units / 100)} EGP` : '' }
  return String(template.value.body).replace(/\{\{([a-z_]+)\}\}/g, (_: string, key: string) => values[key] ?? '')
})
async function load() {
  try { const response = await api.get(`/leads/${props.leadId}/whatsapp/options`); data.value = response.data; form.template_id = String(response.data.templates?.[0]?.id || '') }
  catch (exception: any) { error.value = exception.response?.data?.message || 'WhatsApp options could not be loaded.' }
  finally { loading.value = false }
}
async function send() {
  saving.value = true; error.value = ''
  try { await api.post(`/leads/${props.leadId}/whatsapp/messages`, { template_id: Number(form.template_id), property_listing_id: form.property_listing_id ? Number(form.property_listing_id) : null }); emit('sent'); emit('close') }
  catch (exception: any) { error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'The WhatsApp message could not be sent.'] }).flat().join(' ') }
  finally { saving.value = false }
}
onMounted(load)
</script>
<template>
  <Modal title="Send a WhatsApp template" description="Use your linked business account, or the company account when no personal account is connected." @close="emit('close')">
    <div v-if="loading" class="whatsapp-loading">Loading WhatsApp templates…</div>
    <form v-else class="form-grid" @submit.prevent="send">
      <div v-if="!data?.account" class="whatsapp-account-warning full"><MessageCircle :size="18" /><span><strong>No WhatsApp Business account is connected.</strong><small>Connect one in WhatsApp settings before sending.</small></span></div>
      <div v-else class="whatsapp-account-note full"><MessageCircle :size="17" /><span>Sending with <strong>{{ data.account.display_name }}</strong><small>{{ data.using_company_fallback ? 'Company fallback account' : 'Personal business account' }}</small></span></div>
      <label class="full">Message template<select v-model="form.template_id" required><option v-for="item in data?.templates || []" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label>
      <label v-if="template?.requires_property" class="full">Property<select v-model="form.property_listing_id" required><option value="" disabled>Select a property</option><option v-for="item in data?.properties || []" :key="item.id" :value="String(item.id)">{{ item.title }} · {{ item.reference_code }} · {{ item.location }}</option></select></label>
      <div class="whatsapp-preview full"><span><Building2 :size="15" />MESSAGE PREVIEW</span><p>{{ preview }}</p></div>
      <div v-if="error" class="form-error full">{{ error }}</div>
      <div class="form-actions full"><button type="button" class="button secondary" @click="emit('close')">Cancel</button><button class="button primary" :disabled="saving || !data?.account">{{ saving ? 'Sending…' : 'Send via WhatsApp' }}</button></div>
    </form>
  </Modal>
</template>
