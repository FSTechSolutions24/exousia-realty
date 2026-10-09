import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api'

export const useTasksStore = defineStore('tasks', () => {
  const openCount = ref<number | null>(null)
  let lastLoadedAt = 0
  let pending: Promise<void> | null = null

  function setOpenCount(count: number) {
    openCount.value = Math.max(0, count)
    lastLoadedAt = Date.now()
  }

  async function refresh(force = false) {
    if (pending) return pending
    if (!force && Date.now() - lastLoadedAt < 25_000) return

    pending = api.get('/tasks', { params: { open: 1, per_page: 1 } })
      .then(({ data }) => setOpenCount(Number(data.total) || 0))
      .catch(() => { /* Keep the last known count during transient network errors. */ })
      .finally(() => { pending = null })

    return pending
  }

  function reset() {
    openCount.value = null
    lastLoadedAt = 0
  }

  return { openCount, setOpenCount, refresh, reset }
})
