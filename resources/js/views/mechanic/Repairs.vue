<template>
  <div class="space-y-4">
    <!-- En service : les pannes lui sont proposées -->
    <button type="button" :class="['tap flex w-full items-center justify-between rounded-2xl px-4 py-3.5 text-left font-semibold ring-1 transition', available ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-700 ring-slate-200']" :disabled="switching" :aria-pressed="available" @click="toggle">
      <span>
        <span class="block">{{ available ? '● En service' : '○ Hors service' }}</span>
        <span class="block text-sm font-normal">{{ available ? `Les pannes vous sont proposées${board.data?.mechanic?.available_since ? ' depuis ' + time(board.data.mechanic.available_since) : ''}.` : 'Touchez pour recevoir les pannes.' }}</span>
      </span>
      <span :class="['relative h-7 w-12 shrink-0 rounded-full transition', available ? 'bg-emerald-600' : 'bg-slate-300']" aria-hidden="true">
        <span :class="['absolute top-1 h-5 w-5 rounded-full bg-white shadow transition-all', available ? 'right-1' : 'left-1']" />
      </span>
    </button>

    <div class="flex gap-1 rounded-xl bg-slate-200/70 p-1 text-sm font-semibold" role="tablist">
      <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
              :class="['flex-1 rounded-lg py-2', tab === t.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600']" @click="tab = t.key">
        {{ t.label }}<span v-if="t.key !== 'done' && lists[t.key]?.length"> · {{ lists[t.key].length }}</span>
      </button>
    </div>

    <p v-if="board.error" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ board.error }}</p>
    <SkeletonList v-else-if="!board.data" :rows="3" />
    <template v-else>
      <RepairCard v-for="r in lists[tab]" :key="r.id" :repair="r" />
      <EmptyState v-if="!lists[tab].length" :icon="tab === 'done' ? 'check' : 'atelier'" :title="EMPTY[tab].title" :text="EMPTY[tab].text" />
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { apiErrorMessage } from '../../bootstrap/axios'
import EmptyState from '../../components/mobile/EmptyState.vue'
import SkeletonList from '../../components/mobile/SkeletonList.vue'
import RepairCard from '../../components/mechanic/RepairCard.vue'
import { board, loadBoard, sendPosition, time } from '../../composables/useMechanic'
import { useToastStore } from '../../stores/toasts'

const toasts = useToastStore()
const TABS = [
  { key: 'todo', label: 'À traiter' },
  { key: 'current', label: 'En cours' },
  { key: 'done', label: 'Terminées' },
]
const EMPTY = {
  todo: { title: 'Aucune panne en attente', text: 'Les pannes signalées par les livreurs arrivent ici, avec une alerte.' },
  current: { title: 'Rien en cours', text: 'Les pannes que vous acceptez apparaissent ici.' },
  done: { title: 'Rien ces 7 derniers jours', text: 'Vos interventions terminées apparaissent ici.' },
}
const tab = ref('todo')
const lists = computed(() => ({ todo: board.data?.todo ?? [], current: board.data?.current ?? [], done: board.data?.done ?? [] }))
const available = computed(() => !!board.data?.mechanic?.is_available)

// Une panne en cours : on ouvre l'onglet « En cours » au premier affichage
let opened = false
watch(() => board.data, (data) => {
  if (opened || !data) return
  opened = true
  if (!data.todo.length && data.current.length) tab.value = 'current'
}, { immediate: true })

const switching = ref(false)
async function toggle() {
  switching.value = true
  try {
    const status = await sendPosition({ is_available: !available.value })
    if (status && board.data) board.data.mechanic = { ...board.data.mechanic, ...status }
    toasts.success(status?.is_available ? 'Vous êtes en service : les pannes vous seront proposées.' : 'Vous êtes hors service.')
    loadBoard()
  } catch (e) {
    toasts.error(apiErrorMessage(e, 'Changement impossible. Vérifiez votre réseau.'))
  } finally {
    switching.value = false
  }
}

loadBoard()
</script>
