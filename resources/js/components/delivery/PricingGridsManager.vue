<template>
  <!-- Grilles négociées : des prix à part pour certains clients -->
  <section class="space-y-3">
    <div class="flex flex-wrap items-center gap-3">
      <p class="flex-1 text-sm text-slate-600">
        La grille normale s'applique à tous les clients. Une grille négociée donne d'autres prix à ceux qui y sont rattachés
        (fiche du client › Modifier › Grille tarifaire) ; un lieu sans prix dans la grille négociée garde celui de la grille normale.
      </p>
      <button type="button" class="btn-primary" @click="create">+ Grille négociée</button>
    </div>

    <div class="card divide-y overflow-hidden">
      <div v-for="g in grids" :key="g.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
        <div class="min-w-48 flex-1">
          <p class="font-semibold">
            {{ g.name }}
            <span v-if="g.is_default" class="ml-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200">grille normale</span>
          </p>
          <p class="text-sm text-slate-600">{{ g.is_default ? 'Tous les clients sans grille négociée' : `${g.merchants_count ?? 0} client${(g.merchants_count ?? 0) > 1 ? 's' : ''}` }}</p>
        </div>
        <button type="button" class="btn-primary" @click="$emit('edit', g.id)">Régler ses prix</button>
        <button type="button" class="btn-secondary" @click="rename(g)">Renommer</button>
        <button v-if="!g.is_default" type="button" class="btn-secondary" @click="makeDefault(g)">En faire la grille normale</button>
        <button v-if="!g.is_default" type="button" class="btn-secondary text-red-700" @click="remove(g)">Supprimer</button>
      </div>
    </div>
  </section>
</template>

<script setup>
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'

defineProps({ grids: { type: Array, required: true } })
const emit = defineEmits(['changed', 'edit'])
const toasts = useToastStore()

async function create() {
  const name = (window.prompt('Nom de la grille négociée (ex. : Gros volume) :'))?.trim()
  if (!name) return
  try {
    const { data } = await http.post('/pricing-grids', { name })
    emit('changed')
    toasts.success('Grille créée : donnez-lui ses prix.')
    emit('edit', data.data.id)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function rename(g) {
  const name = (window.prompt('Nouveau nom de la grille :', g.name))?.trim()
  if (!name || name === g.name) return
  try {
    await http.patch(`/pricing-grids/${g.id}`, { name })
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function makeDefault(g) {
  if (!window.confirm(`Faire de « ${g.name} » la grille normale, appliquée à tous les clients sans grille négociée ?`)) return
  try {
    await http.patch(`/pricing-grids/${g.id}`, { is_default: true })
    emit('changed')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function remove(g) {
  const count = g.merchants_count ?? 0
  const consequence = count ? ` Ses ${count} client(s) passeront sur la grille normale.` : ''
  if (!window.confirm(`Supprimer la grille « ${g.name} » et tous ses prix ?${consequence}`)) return
  try {
    await http.delete(`/pricing-grids/${g.id}`)
    emit('changed')
    toasts.success('Grille supprimée.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}
</script>
