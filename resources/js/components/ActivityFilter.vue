<template>
  <!-- Activité des écrans Courses : barre à segments colorés, distincte des pastilles du jour -->
  <div v-if="filter.choices.length > 1" class="space-y-2">
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Activité">
      <span class="text-[11px] font-bold uppercase tracking-wider text-slate-600">Activité</span>
      <!-- Téléphone : une seule ligne, qu'on fait défiler si besoin -->
      <div class="no-scrollbar flex max-w-full gap-1 overflow-x-auto rounded-xl bg-white p-1 ring-1 ring-slate-300">
        <button
          v-for="a in options" :key="a.value || 'all'" type="button"
          :class="['inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-2 py-1 text-sm font-semibold transition sm:px-3', filter.current === a.value ? a.on : 'text-slate-600 hover:bg-slate-50']"
          :aria-pressed="filter.current === a.value"
          @click="choose(a.value)"
        >
          <span v-if="a.value" :class="['h-2 w-2 rounded-full', a.dot]" aria-hidden="true" />
          {{ a.label }}
          <span v-if="a.count !== null" class="font-normal tabular-nums opacity-80">{{ a.count }}</span>
        </button>
      </div>
    </div>
    <!-- Rappel : une seule activité est affichée, sur les quatre écrans Courses -->
    <div v-if="active" :class="['flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg px-3 py-2 text-sm font-semibold', active.banner]">
      <span>Vous voyez seulement : {{ active.label }}.<span v-if="note" class="font-normal">{{ ` ${note}` }}</span></span>
      <button type="button" class="ml-auto font-semibold underline" @click="choose('')">Voir toutes les activités</button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useActivityFilterStore } from '../stores/activityFilter'
import { useDispatchCountStore } from '../stores/dispatchCounts'

// Une remarque propre à l'écran (onglet qui ne suit pas l'activité, par exemple)
defineProps({ note: { type: String, default: '' } })
const emit = defineEmits(['change'])

const filter = useActivityFilterStore()
const counts = useDispatchCountStore()

// Couleurs des badges d'activité de la liste (utils/activities)
const STYLES = {
  '': { on: 'bg-slate-900 text-white', dot: '', banner: '' },
  ecommerce: { on: 'bg-sky-50 text-sky-800 ring-2 ring-sky-600', dot: 'bg-sky-600', banner: 'bg-sky-50 text-sky-800' },
  distribution: { on: 'bg-amber-50 text-amber-900 ring-2 ring-amber-600', dot: 'bg-amber-600', banner: 'bg-amber-50 text-amber-900' },
  mad: { on: 'bg-violet-50 text-violet-800 ring-2 ring-violet-600', dot: 'bg-violet-600', banner: 'bg-violet-50 text-violet-800' },
}
const LABELS = { mad: 'MAD' }

// Courses du jour par activité (toute l'entreprise) ; rien tant que les compteurs ne sont pas arrivés
const today = computed(() => counts.counts.activities_today || null)
const options = computed(() => [{ value: '', label: 'Toutes' }, ...filter.choices].map((a) => ({
  ...a,
  ...STYLES[a.value],
  label: LABELS[a.value] || a.label,
  count: today.value ? (a.value ? today.value[a.value] || 0 : filter.choices.reduce((s, c) => s + (today.value[c.value] || 0), 0)) : null,
})))
const active = computed(() => options.value.find((a) => a.value && a.value === filter.current) || null)

function choose(value) {
  if (value === filter.current) return
  filter.set(value)
  counts.fetchCounts().catch(() => {})
  emit('change', value)
}
</script>
