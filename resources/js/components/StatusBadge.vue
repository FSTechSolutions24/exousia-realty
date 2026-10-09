<script setup lang="ts">defineProps<{ label: string; color?: string }>()</script>
<template><span class="status-badge" :style="{ '--badge': color || '#64748b' }"><i />{{ label }}</span></template>
