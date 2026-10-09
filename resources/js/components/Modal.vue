<script setup lang="ts">
import { X } from '@lucide/vue'
defineProps<{ title: string; description?: string }>()
defineEmits<{ close: [] }>()
</script>
<template>
  <Teleport to="body"><div class="modal-backdrop" @mousedown.self="$emit('close')"><section class="modal-card" role="dialog" aria-modal="true"><header><div><h2>{{ title }}</h2><p v-if="description">{{ description }}</p></div><button class="icon-button" @click="$emit('close')"><X :size="20" /></button></header><div class="modal-body"><slot /></div></section></div></Teleport>
</template>
