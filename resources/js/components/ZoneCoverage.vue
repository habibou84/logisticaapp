<template>
  <!-- Zones couvertes par le forfait d'un contrat : communes d'Abidjan, villes de l'intérieur (leurs quartiers suivent) -->
  <div class="space-y-2">
    <div class="flex flex-wrap items-center gap-2 text-xs">
      <input v-model="search" class="input w-40 py-1 text-sm" placeholder="Filtrer…" aria-label="Filtrer les zones">
      <button type="button" class="rounded-full px-2 py-1 ring-1 ring-slate-300" @click="set(defaults)">Communes « Forfait »</button>
      <button type="button" class="rounded-full px-2 py-1 ring-1 ring-slate-300" @click="add(abidjan)">+ tout Abidjan</button>
      <button type="button" class="rounded-full px-2 py-1 ring-1 ring-slate-300" @click="add(interior)">+ tout l'intérieur</button>
      <button type="button" class="rounded-full px-2 py-1 ring-1 ring-slate-300" @click="set([])">Aucune</button>
    </div>
    <div v-for="g in groups" :key="g.label">
      <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mt-1">{{ g.label }} ({{ g.zones.filter((z) => model.includes(z.id)).length }}/{{ g.zones.length }})</p>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-3 gap-y-1">
        <label v-for="z in g.zones.filter(matches)" :key="z.id" class="flex items-center gap-2 text-sm">
          <input v-model="model" type="checkbox" :value="z.id"> {{ z.name }}
        </label>
      </div>
      <p v-if="!g.zones.length" class="text-xs text-gray-500">{{ g.empty }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({ zones: { type: Array, required: true } })
const model = defineModel({ type: Array, required: true })
const search = ref('')

const norm = (v) => (v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim()
const isAbidjan = (z) => !z.city || norm(z.city) === 'abidjan'
// Zones de premier niveau : communes et villes (les quartiers suivent leur commune)
const top = computed(() => props.zones.filter((z) => !z.parent_id && z.is_active !== false).sort((a, b) => a.name.localeCompare(b.name, 'fr')))
const abidjan = computed(() => top.value.filter(isAbidjan))
const interior = computed(() => top.value.filter((z) => !isAbidjan(z)))
const defaults = computed(() => top.value.filter((z) => z.in_forfait).map((z) => z.id))
const groups = computed(() => [
  { label: 'Abidjan', zones: abidjan.value, empty: 'Aucune commune (Réglages › Zones).' },
  { label: 'Intérieur', zones: interior.value, empty: 'Aucune ville : ajoutez-les dans Distribution › Tarifs.' },
])
const matches = (z) => !search.value || norm(z.name).includes(norm(search.value))

function set(ids) {
  model.value = [...ids]
}

function add(zones) {
  model.value = [...new Set([...model.value, ...zones.map((z) => z.id)])]
}

defineExpose({ defaults })
</script>
