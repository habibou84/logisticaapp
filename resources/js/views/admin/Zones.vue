<template>
  <div class="space-y-4 max-w-3xl">
    <h1 class="text-xl font-bold">Zones de livraison</h1>
    <p class="text-sm text-gray-600">Communes et, si besoin, quartiers. Un quartier sans tarif propre utilise le tarif de sa commune.</p>

    <form class="card p-4 space-y-3" @submit.prevent="create">
      <div class="flex flex-wrap gap-2 items-end">
        <div class="flex-1 min-w-40">
          <label class="label" for="zone-name">Nom</label>
          <input id="zone-name" v-model="draft.name" class="input" required :placeholder="draft.is_shipping ? 'Ex. : Expédition Bouaké (gare UTB Adjamé)' : 'Ex. : Angré'">
        </div>
        <div v-if="!draft.is_shipping" class="flex-1 min-w-40">
          <label class="label" for="zone-parent">Dans la commune</label>
          <select id="zone-parent" v-model="draft.parent_id" class="input">
            <option :value="null">— (c'est une commune)</option>
            <option v-for="z in communes" :key="z.id" :value="z.id">{{ z.name }}</option>
          </select>
        </div>
        <div v-else class="w-44">
          <label class="label" for="zone-estimate">Frais habituels (indicatif)</label>
          <input id="zone-estimate" v-model.number="draft.shipping_fee_estimate" type="number" min="0" class="input" placeholder="Ex. : 3000">
        </div>
        <button class="btn-primary">Ajouter</button>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="draft.is_shipping" type="checkbox" class="mt-1" @change="draft.parent_id = null">
        <span>Zone d'expédition
          <span class="block text-gray-500">
            Le livreur dépose le colis à une gare ou chez un transporteur et paie l'envoi. Ces frais, saisis au réel par le livreur,
            sont facturés au marchand et déduits de son point. Fixez le prix de la course jusqu'à la gare dans Tarifs.
          </span>
        </span>
      </label>
      <p v-if="error" class="field-error">{{ error }}</p>
    </form>

    <div class="card divide-y">
      <div v-for="z in zones" :key="z.id" :class="['p-3 text-sm', z.parent_id ? 'pl-8' : 'font-medium']">
        <!-- Modification -->
        <form v-if="edit.id === z.id" class="space-y-2 font-normal" @submit.prevent="saveEdit(z)">
          <div class="flex flex-wrap gap-2 items-end">
            <div class="flex-1 min-w-40">
              <label class="label" :for="`zone-edit-name-${z.id}`">Nom</label>
              <input :id="`zone-edit-name-${z.id}`" v-model.trim="edit.name" class="input" required maxlength="100">
            </div>
            <div v-if="!edit.is_shipping" class="flex-1 min-w-40">
              <label class="label" :for="`zone-edit-parent-${z.id}`">Dans la commune</label>
              <select :id="`zone-edit-parent-${z.id}`" v-model="edit.parent_id" class="input" :disabled="hasChildren(z)">
                <option :value="null">— (c'est une commune)</option>
                <option v-for="c in communes.filter((c) => c.id !== z.id)" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
            <div v-else class="w-44">
              <label class="label" :for="`zone-edit-estimate-${z.id}`">Frais habituels (indicatif)</label>
              <input :id="`zone-edit-estimate-${z.id}`" v-model.number="edit.shipping_fee_estimate" type="number" min="0" class="input">
            </div>
          </div>
          <label v-if="!hasChildren(z)" class="flex items-center gap-2">
            <input v-model="edit.is_shipping" type="checkbox" @change="edit.parent_id = null"> Zone d'expédition
          </label>
          <p v-if="hasChildren(z)" class="text-xs text-gray-500">Cette commune a des quartiers : elle reste une commune.</p>
          <div class="flex gap-2">
            <button class="btn-primary" :disabled="edit.saving">Enregistrer</button>
            <button type="button" class="btn-secondary" @click="edit.id = null">Annuler</button>
          </div>
        </form>

        <div v-else class="flex items-center justify-between gap-2">
          <span :class="z.is_active ? '' : 'line-through text-gray-400'">
            {{ z.parent_id ? '↳ ' : '' }}{{ z.name }}
            <span v-if="z.is_shipping" class="ml-2 rounded-full bg-indigo-100 text-indigo-800 text-xs font-medium px-2 py-0.5">🚌 Expédition</span>
          </span>
          <span class="flex flex-wrap items-center justify-end gap-3">
            <label v-if="z.is_shipping" class="flex items-center gap-1 text-xs text-gray-500 font-normal">
              Frais habituels
              <input :value="z.shipping_fee_estimate" type="number" min="0" class="input w-24 py-1 text-xs" aria-label="Frais d'expédition habituels" @change="setEstimate(z, $event.target.value)">
            </label>
            <button class="text-blue-600 text-xs" @click="startEdit(z)">Modifier</button>
            <button class="text-blue-600 text-xs" @click="toggle(z)">{{ z.is_active ? 'Désactiver' : 'Réactiver' }}</button>
            <button class="text-red-600 text-xs" @click="remove(z)">Supprimer</button>
          </span>
        </div>
      </div>
    </div>

    <p class="text-xs text-gray-500">
      Supprimer n'est possible que pour une zone jamais utilisée (ni course, ni e-commerçant, ni entrepôt, ni quartier) :
      ses tarifs sont supprimés avec elle. Une zone déjà utilisée se désactive : elle n'est plus proposée, l'historique est conservé.
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useToastStore } from '../../stores/toasts'

const toasts = useToastStore()
const zones = ref([])
const draft = reactive({ name: '', parent_id: null, is_shipping: false, shipping_fee_estimate: null })
const error = ref('')
const edit = reactive({ id: null, name: '', parent_id: null, is_shipping: false, shipping_fee_estimate: null, saving: false })
const communes = computed(() => zones.value.filter((z) => !z.parent_id && !z.is_shipping))

async function load() {
  zones.value = (await http.get('/zones', { params: { include_inactive: 1 } })).data.data
}

async function create() {
  error.value = ''
  try {
    const payload = { name: draft.name, parent_id: draft.parent_id, is_shipping: draft.is_shipping }
    if (draft.is_shipping) payload.shipping_fee_estimate = draft.shipping_fee_estimate || null
    await http.post('/zones', payload)
    Object.assign(draft, { name: '', parent_id: null, shipping_fee_estimate: null })
    load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function hasChildren(zone) {
  return zones.value.some((z) => z.parent_id === zone.id)
}

function startEdit(zone) {
  Object.assign(edit, {
    id: zone.id, name: zone.name, parent_id: zone.parent_id, is_shipping: zone.is_shipping,
    shipping_fee_estimate: zone.shipping_fee_estimate, saving: false,
  })
}

async function saveEdit(zone) {
  edit.saving = true
  try {
    const payload = { name: edit.name, parent_id: edit.is_shipping ? null : edit.parent_id, is_shipping: edit.is_shipping }
    if (edit.is_shipping) payload.shipping_fee_estimate = edit.shipping_fee_estimate === '' ? null : edit.shipping_fee_estimate
    await http.patch(`/zones/${zone.id}`, payload)
    edit.id = null
    toasts.success('Zone modifiée.')
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    edit.saving = false
  }
}

async function remove(zone) {
  if (!window.confirm(`Supprimer la zone « ${zone.name} » et ses tarifs ?`)) return
  try {
    await http.delete(`/zones/${zone.id}`)
    toasts.success('Zone supprimée.')
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e), { timeout: 10000 })
  }
}

async function toggle(zone) {
  await http.patch(`/zones/${zone.id}`, { is_active: !zone.is_active })
  load()
}

async function setEstimate(zone, value) {
  try {
    await http.patch(`/zones/${zone.id}`, { shipping_fee_estimate: value === '' ? null : Number(value) })
    toasts.success('Frais habituels enregistrés.')
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>
