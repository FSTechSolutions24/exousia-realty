<script setup lang="ts">
import { computed, ref } from 'vue'
import { MapPin, X } from '@lucide/vue'

export interface PreferredLocationOption {
  id: number
  name: string
  name_ar?: string | null
  is_active: boolean
  position: number
}

const props = defineProps<{
  modelValue: string[]
  locations: PreferredLocationOption[]
  loading?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()
const selected = ref('')

const available = computed(() => props.locations.filter(location => location.is_active && !props.modelValue.includes(location.name)))

function addLocation() {
  if (!selected.value || props.modelValue.includes(selected.value)) return
  emit('update:modelValue', [...props.modelValue, selected.value])
  selected.value = ''
}

function removeLocation(name: string) {
  emit('update:modelValue', props.modelValue.filter(value => value !== name))
}
</script>

<template>
  <div class="location-picker full">
    <span class="field-label">Preferred locations</span>
    <div class="location-picker-control">
      <span class="location-picker-icon"><MapPin :size="15" /></span>
      <select v-model="selected" :disabled="loading || !available.length" @change="addLocation">
        <option value="">{{ loading ? 'Loading locations…' : available.length ? 'Select a location' : 'All locations selected' }}</option>
        <option v-for="location in available" :key="location.id" :value="location.name">
          {{ location.name }}{{ location.name_ar ? ` · ${location.name_ar}` : '' }}
        </option>
      </select>
    </div>
    <div v-if="modelValue.length" class="location-tags" aria-label="Selected preferred locations">
      <span v-for="name in modelValue" :key="name" class="location-tag">
        <MapPin :size="11" />{{ name }}
        <button type="button" :aria-label="`Remove ${name}`" @click="removeLocation(name)"><X :size="11" /></button>
      </span>
    </div>
    <small v-else class="location-picker-help">Choose one or more areas from your workspace location master.</small>
  </div>
</template>
