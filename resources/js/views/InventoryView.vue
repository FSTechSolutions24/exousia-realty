<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, reactive, ref, watch } from 'vue'
import { Building2, ImagePlus, Pencil, Plus, Search, Upload, X } from '@lucide/vue'
import { api } from '../api'
import { useAuthStore } from '../stores/auth'
import Modal from '../components/Modal.vue'

const PropertyDescriptionEditor = defineAsyncComponent(() => import('../components/PropertyDescriptionEditor.vue'))

interface PropertyPhoto { id: number; original_name: string; mime_type: string; size_bytes: number; position: number; url: string }
interface ListingActivity { id: number; event: string; old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null; occurred_at: string; user?: { id: number; name: string } }
interface PropertyListing {
  id: number; reference_code: string; title: string; description: string | null; listing_type: string; property_type: string;
  status: string; location: string; preferred_location_id: number | null; address: string | null; price_egp: string; price_minor_units: number; currency: string;
  bedrooms: number | null; bathrooms: number | null; area_sqm: string | null; photos: PropertyPhoto[]; activities?: ListingActivity[]
}
interface MasterLocation { id: number; name: string; name_ar: string | null; is_active: boolean }

const auth = useAuthStore()
const listings = ref<PropertyListing[]>([])
const pagination = ref<any>({})
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const search = ref('')
const listingType = ref('')
const status = ref('')
const page = ref(1)
const showEditor = ref(false)
const editingId = ref<number | null>(null)
const selectedPhotos = ref<File[]>([])
const currentPhotos = ref<PropertyPhoto[]>([])
const history = ref<ListingActivity[]>([])
const masterLocations = ref<MasterLocation[]>([])
const locationsLoading = ref(true)
const canManage = computed(() => ['owner', 'admin', 'manager', 'operations'].includes(auth.bootstrap?.membership.role || '') || auth.bootstrap?.membership.permissions.includes('manage_inventory'))
const form = reactive({
  reference_code: '', title: '', description: '', listing_type: 'sale', property_type: 'apartment', status: 'available',
  location: '', preferred_location_id: '' as number | '', address: '', price_egp: '', bedrooms: '', bathrooms: '', area_sqm: '',
})
let searchTimer: number | undefined

const totalAvailable = computed(() => pagination.value.total ? listings.value.filter(listing => listing.status === 'available').length : 0)
const money = (amount: string) => new Intl.NumberFormat('en-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 0 }).format(Number(amount || 0))
const historyLabel = (activity: ListingActivity) => {
  if (activity.event === 'status_changed') return `Status changed to ${activity.new_values?.status || 'updated'}`
  if (activity.event === 'price_changed') return `Price changed to ${money(String((Number(activity.new_values?.price_minor_units) / 100).toFixed(2)))}`
  if (activity.event === 'photo_uploaded' || activity.event === 'photo_removed') return `${activity.event === 'photo_uploaded' ? 'Photo added' : 'Photo removed'}: ${activity.new_values?.original_name || activity.old_values?.original_name || ''}`
  return activity.event.replace(/_/g, ' ')
}
const statuses = ['available', 'reserved', 'sold', 'rented', 'withdrawn']
const propertyTypes = ['apartment', 'villa', 'townhouse', 'twin_house', 'duplex', 'office', 'retail', 'chalet', 'land', 'other']

async function load() {
  loading.value = true; error.value = ''
  try {
    const { data } = await api.get('/inventory', { params: { search: search.value, listing_type: listingType.value, status: status.value, page: page.value, per_page: 15 } })
    listings.value = data.data
    pagination.value = data
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to load inventory.'
  } finally { loading.value = false }
}

async function loadMasterLocations() {
  locationsLoading.value = true
  try {
    const { data } = await api.get('/preferred-locations', { params: { active: true } })
    masterLocations.value = data.data
  } catch (exception: any) {
    error.value = exception.response?.data?.message || 'Unable to load workspace locations.'
  } finally { locationsLoading.value = false }
}

function openCreate() {
  editingId.value = null; selectedPhotos.value = []; currentPhotos.value = []; history.value = []
  Object.assign(form, { reference_code: '', title: '', description: '', listing_type: 'sale', property_type: 'apartment', status: 'available', preferred_location_id: '', address: '', price_egp: '', bedrooms: '', bathrooms: '', area_sqm: '' })
  error.value = ''; showEditor.value = true
}

async function openListing(listing: PropertyListing) {
  error.value = ''
  try {
    const { data } = await api.get(`/inventory/${listing.id}`)
    editingId.value = listing.id; selectedPhotos.value = []; currentPhotos.value = data.photos; history.value = data.activities || []
    Object.assign(form, {
      reference_code: data.reference_code, title: data.title, description: data.description || '', listing_type: data.listing_type,
      property_type: data.property_type, status: data.status,
      preferred_location_id: data.preferred_location_id || masterLocations.value.find(location => location.name === data.location)?.id || '',
      address: data.address || '', price_egp: data.price_egp,
      bedrooms: data.bedrooms == null ? '' : String(data.bedrooms), bathrooms: data.bathrooms == null ? '' : String(data.bathrooms),
      area_sqm: data.area_sqm || '',
    })
    showEditor.value = true
  } catch (exception: any) { error.value = exception.response?.data?.message || 'Unable to open this listing.' }
}

async function save() {
  saving.value = true; error.value = ''
  const payload = {
    ...form, reference_code: form.reference_code || null, description: form.description || null, address: form.address || null,
    bedrooms: form.bedrooms || null, bathrooms: form.bathrooms || null, area_sqm: form.area_sqm || null,
  }
  try {
    const { data: listing } = editingId.value
      ? await api.put(`/inventory/${editingId.value}`, payload)
      : await api.post('/inventory', payload)
    editingId.value = listing.id
    while (selectedPhotos.value.length) {
      const file = selectedPhotos.value[0]
      const body = new FormData(); body.append('photo', file)
      const { data: photo } = await api.post(`/inventory/${listing.id}/photos`, body)
      currentPhotos.value.push(photo)
      selectedPhotos.value.shift()
    }
    showEditor.value = false
    await load()
  } catch (exception: any) {
    error.value = Object.values(exception.response?.data?.errors || { error: [exception.response?.data?.message || 'Unable to save this listing.'] }).flat().join(' ')
    if (editingId.value) await load()
  } finally { saving.value = false }
}

function choosePhotos(event: Event) {
  const files = Array.from((event.target as HTMLInputElement).files || [])
  selectedPhotos.value = [...selectedPhotos.value, ...files].slice(0, 20 - currentPhotos.value.length)
}

async function removePhoto(photo: PropertyPhoto) {
  if (!editingId.value) return
  try {
    await api.delete(`/inventory/${editingId.value}/photos/${photo.id}`)
    currentPhotos.value = currentPhotos.value.filter(item => item.id !== photo.id)
  } catch (exception: any) { error.value = exception.response?.data?.message || 'Unable to remove photo.' }
}

function removeSelectedPhoto(index: number) { selectedPhotos.value.splice(index, 1) }
watch([listingType, status], () => { page.value = 1; load() })
watch(search, () => { window.clearTimeout(searchTimer); page.value = 1; searchTimer = window.setTimeout(load, 250) })
watch(page, load)
onMounted(() => { load(); loadMasterLocations() })
</script>

<template>
  <div>
    <header class="page-heading"><div><span class="eyebrow">PROPERTY CATALOGUE</span><h1>Inventory</h1><p>Keep property details, pricing, availability, and photos together.</p></div><button v-if="canManage" class="button primary" @click="openCreate"><Plus :size="18" />Add property</button></header>
    <div v-if="error && !showEditor" class="detail-alert">{{ error }}<button @click="error = ''">Dismiss</button></div>
    <section class="inventory-stats"><article><span class="inventory-stat-icon"><Building2 /></span><div><strong>{{ pagination.total || 0 }}</strong><small>Properties in catalogue</small></div></article><article><span class="inventory-stat-icon available"><Building2 /></span><div><strong>{{ totalAvailable }}</strong><small>Available on this page</small></div></article></section>
    <section class="panel inventory-panel">
      <div class="table-toolbar"><div class="search-box"><Search /><input v-model="search" placeholder="Search title, reference, location…"></div><div class="filter-actions"><select v-model="listingType"><option value="">Sale and rent</option><option value="sale">For sale</option><option value="rent">For rent</option></select><select v-model="status"><option value="">All availability</option><option v-for="item in statuses" :key="item" :value="item">{{ item }}</option></select></div></div>
      <div class="inventory-table-wrap"><table class="inventory-table"><thead><tr><th>Property</th><th>Location</th><th>Type</th><th>Price</th><th>Details</th><th>Status</th><th></th></tr></thead><tbody>
        <tr v-if="loading"><td colspan="7" class="inventory-empty">Loading inventory…</td></tr>
        <tr v-else-if="!listings.length"><td colspan="7"><div class="table-empty"><Building2 /><strong>No properties found</strong><span>{{ canManage ? 'Add a listing or change the filters.' : 'There are no listings in this workspace yet.' }}</span></div></td></tr>
        <tr v-for="listing in listings" :key="listing.id" class="inventory-row" tabindex="0" @click="openListing(listing)" @keydown.enter="openListing(listing)"><td><span class="inventory-property"><img v-if="listing.photos.length" :src="listing.photos[0].url" :alt="listing.title"><span v-else class="inventory-photo-placeholder"><Building2 /></span><span><strong>{{ listing.title }}</strong><small>{{ listing.reference_code }} · {{ listing.listing_type }}</small></span></span></td><td>{{ listing.location }}</td><td>{{ listing.property_type.replace('_', ' ') }}</td><td><strong>{{ money(listing.price_egp) }}</strong></td><td><span class="inventory-details">{{ listing.bedrooms ?? '—' }} bd <i>·</i> {{ listing.area_sqm || '—' }} m²</span></td><td><span class="inventory-status" :class="listing.status">{{ listing.status }}</span></td><td><button v-if="canManage" class="icon-button" :aria-label="`Edit ${listing.title}`" @click.stop="openListing(listing)"><Pencil :size="16" /></button></td></tr>
      </tbody></table></div>
      <footer class="table-footer"><span>{{ pagination.total || 0 }} properties</span><div><button class="icon-button" :disabled="page <= 1" @click="page--">‹</button><b>{{ page }}</b><button class="icon-button" :disabled="page >= (pagination.last_page || 1)" @click="page++">›</button></div></footer>
    </section>

    <Modal v-if="showEditor" :title="editingId ? (canManage ? 'Edit property' : 'Property details') : 'Add property listing'" description="Prices are stored precisely in EGP piastres." @close="showEditor = false">
      <form class="form-grid inventory-form" @submit.prevent="save">
        <label class="full">Property title<input v-model.trim="form.title" :disabled="!canManage" required maxlength="180" placeholder="3 bedroom apartment in New Cairo"></label>
        <label>Reference code<input v-model.trim="form.reference_code" :disabled="!canManage" maxlength="40" placeholder="Generated automatically"></label>
        <label>Availability<select v-model="form.status" :disabled="!canManage"><option v-for="item in statuses" :key="item" :value="item">{{ item }}</option></select></label>
        <label>Listing type<select v-model="form.listing_type" :disabled="!canManage"><option value="sale">For sale</option><option value="rent">For rent</option></select></label>
        <label>Property type<select v-model="form.property_type" :disabled="!canManage"><option v-for="item in propertyTypes" :key="item" :value="item">{{ item.replace('_', ' ') }}</option></select></label>
        <label>Location<select v-model="form.preferred_location_id" :disabled="!canManage || locationsLoading" required><option value="" disabled>{{ locationsLoading ? 'Loading locations…' : 'Select one location' }}</option><option v-for="location in masterLocations" :key="location.id" :value="location.id">{{ location.name }}{{ location.name_ar ? ` · ${location.name_ar}` : '' }}</option><option v-if="form.preferred_location_id && !masterLocations.some(location => location.id === form.preferred_location_id)" :value="form.preferred_location_id">{{ form.location }} (inactive)</option></select><small v-if="!masterLocations.length && !locationsLoading" class="inventory-help">Add an active location in the Locations master screen first.</small></label>
        <label>Price (EGP)<input v-model="form.price_egp" :disabled="!canManage" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" required placeholder="7250000.00"></label>
        <label>Bedrooms<input v-model="form.bedrooms" :disabled="!canManage" type="number" min="0" max="50"></label>
        <label>Bathrooms<input v-model="form.bathrooms" :disabled="!canManage" type="number" min="0" max="50"></label>
        <label>Area (m²)<input v-model="form.area_sqm" :disabled="!canManage" type="number" min="0.01" step="0.01"></label>
        <label class="full">Address<input v-model.trim="form.address" :disabled="!canManage" maxlength="255"></label>
        <div class="full inventory-description-field"><label>Description</label><PropertyDescriptionEditor v-model="form.description" :disabled="!canManage" /></div>
        <div class="inventory-photos full"><div class="inventory-photo-heading"><strong>Property photos</strong><small>{{ currentPhotos.length + selectedPhotos.length }} / 20</small></div><div class="inventory-photo-grid"><div v-for="photo in currentPhotos" :key="photo.id" class="inventory-photo"><img :src="photo.url" :alt="photo.original_name"><button v-if="canManage" type="button" :aria-label="`Remove ${photo.original_name}`" @click="removePhoto(photo)"><X :size="14" /></button></div><div v-for="(file, index) in selectedPhotos" :key="`${file.name}-${index}`" class="inventory-photo pending"><span class="inventory-new-photo"><ImagePlus /><small>{{ file.name }}</small></span><button type="button" :aria-label="`Remove ${file.name}`" @click="removeSelectedPhoto(index)"><X :size="14" /></button></div><label v-if="canManage && currentPhotos.length + selectedPhotos.length < 20" class="inventory-photo-add"><ImagePlus /><span>Add photos</span><input type="file" accept="image/jpeg,image/png,image/webp,image/avif" multiple @change="choosePhotos"></label></div><small class="inventory-help">JPEG, PNG, WebP or AVIF, up to 10 MB each. Photos are private to this workspace.</small></div>
        <div v-if="history.length" class="inventory-history full"><strong>Recent history</strong><span v-for="activity in history.slice(0, 5)" :key="activity.id">{{ historyLabel(activity) }} · {{ new Intl.DateTimeFormat('en-EG', { dateStyle: 'medium' }).format(new Date(activity.occurred_at)) }}</span></div>
        <div v-if="error" class="form-error full">{{ error }}</div>
        <div class="form-actions full"><button type="button" class="button secondary" @click="showEditor = false">{{ canManage ? 'Cancel' : 'Close' }}</button><button v-if="canManage" class="button primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save listing' }}<Upload v-if="selectedPhotos.length" :size="15" /></button></div>
      </form>
    </Modal>
  </div>
</template>
