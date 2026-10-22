<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Formules d'abonnement</h1>
        <p class="text-sm text-gray-500">
          Forfait mensuel avec des courses incluses, puis un prix par course en plus. Essai gratuit des nouvelles entreprises :
          {{ trialDays ? `${trialDays} jours` : 'aucun' }} (réglage PLATFORM_TRIAL_DAYS).
        </p>
      </div>
      <button class="btn-primary" @click="edit(null)">+ Nouvelle formule</button>
    </div>

    <div v-if="loaded && !plans.length" class="card p-6 text-center space-y-3">
      <p class="text-gray-600">Aucune formule pour l'instant. Partez des trois formules proposées, puis ajustez prix et limites.</p>
      <button class="btn-primary" :disabled="creating" @click="createSuggested">Créer Essentiel, Pro et Entreprise</button>
    </div>

    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="p in plans" :key="p.id" :class="['card p-4 space-y-3', !p.is_active && 'opacity-60']">
        <div class="flex items-start justify-between gap-2">
          <div>
            <h2 class="font-semibold text-lg">{{ p.name }}</h2>
            <p v-if="p.description" class="text-sm text-gray-500">{{ p.description }}</p>
          </div>
          <span v-if="!p.is_active" class="rounded-full bg-slate-200 px-2 py-0.5 text-xs">Retirée</span>
        </div>
        <p class="text-2xl font-bold">{{ money(p.price_monthly) }} <span class="text-sm font-normal text-gray-500">/ mois</span></p>
        <ul class="text-sm space-y-1">
          <li>📦 {{ p.included_orders === null ? 'Courses illimitées' : `${p.included_orders} courses incluses` }}</li>
          <li v-if="p.included_orders !== null">➕ {{ money(p.overage_per_order) }} par course en plus</li>
          <li>🛵 {{ p.max_couriers === null ? 'Livreurs illimités' : `${p.max_couriers} livreurs actifs au plus` }}</li>
        </ul>
        <ul class="text-sm space-y-1 border-t pt-2">
          <li v-for="f in features" :key="f.key" :class="p.features.includes(f.key) ? 'text-gray-800' : 'text-gray-400 line-through'">
            {{ p.features.includes(f.key) ? '✓' : '✗' }} {{ f.label }}
          </li>
        </ul>
        <div class="flex items-center justify-between border-t pt-2">
          <span class="text-sm text-gray-500">{{ p.companies_count }} entreprise{{ p.companies_count > 1 ? 's' : '' }}</span>
          <button class="btn-secondary" @click="edit(p)">Modifier</button>
        </div>
      </div>
    </div>

    <Modal :open="form.open" :title="form.id ? `Formule ${form.name}` : 'Nouvelle formule'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div><label class="label" for="p-name">Nom *</label><input id="p-name" v-model.trim="form.name" class="input" required maxlength="60"></div>
        <div><label class="label" for="p-desc">Description</label><input id="p-desc" v-model.trim="form.description" class="input" maxlength="500"></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label" for="p-price">Prix mensuel (F) *</label><input id="p-price" v-model.number="form.price_monthly" type="number" min="0" class="input" required></div>
          <div>
            <label class="label" for="p-couriers">Livreurs actifs au plus</label>
            <input id="p-couriers" v-model.number="form.max_couriers" type="number" min="1" class="input" placeholder="Illimité">
          </div>
          <div>
            <label class="label" for="p-orders">Courses incluses par mois</label>
            <input id="p-orders" v-model.number="form.included_orders" type="number" min="0" class="input" placeholder="Illimité">
          </div>
          <div>
            <label class="label" for="p-overage">Prix par course en plus (F)</label>
            <input id="p-overage" v-model.number="form.overage_per_order" type="number" min="0" class="input" :disabled="form.included_orders === '' || form.included_orders === null">
          </div>
        </div>
        <fieldset class="border rounded p-3 space-y-2">
          <legend class="text-sm font-medium px-1">Modules inclus</legend>
          <label v-for="f in features" :key="f.key" class="flex items-start gap-2 text-sm">
            <input v-model="form.features" type="checkbox" :value="f.key" class="mt-1">
            <span>{{ f.label }}<span class="block text-gray-500">{{ f.description }}</span></span>
          </label>
          <p class="text-xs text-gray-500">Courses, livreurs, caisse, suivi, notifications et import Excel sont inclus dans toutes les formules.</p>
        </fieldset>
        <label v-if="form.id" class="flex items-start gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="mt-1">
          <span>Proposée aux nouvelles entreprises<span class="block text-gray-500">Décochée : la formule est retirée, les entreprises qui l'ont la gardent.</span></span>
        </label>
        <p v-if="form.error" class="field-error">{{ form.error }}</p>
        <button class="btn-primary w-full" :disabled="form.saving">{{ form.saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

const toasts = useToastStore()
const plans = ref([])
const features = ref([])
const trialDays = ref(0)
const loaded = ref(false)
const creating = ref(false)
const form = reactive({ open: false })

function apply(data) {
  plans.value = data.data
  features.value = data.meta.features
  trialDays.value = data.meta.trial_days
  loaded.value = true
}

async function load() {
  apply((await http.get('/console/plans')).data)
}

async function createSuggested() {
  creating.value = true
  try {
    apply((await http.post('/console/plans/suggested')).data)
    toasts.success('Formules créées : ajustez-les si besoin.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    creating.value = false
  }
}

function edit(plan) {
  Object.assign(form, {
    open: true, error: '', saving: false,
    id: plan?.id ?? null,
    name: plan?.name ?? '',
    description: plan?.description ?? '',
    price_monthly: plan?.price_monthly ?? 0,
    included_orders: plan?.included_orders ?? '',
    overage_per_order: plan?.overage_per_order ?? 0,
    max_couriers: plan?.max_couriers ?? '',
    features: [...(plan?.features ?? [])],
    is_active: plan?.is_active ?? true,
  })
}

// Champ vide = illimité
const orNull = (value) => (value === '' || value === null || value === undefined ? null : value)

async function save() {
  form.saving = true
  form.error = ''
  const payload = {
    name: form.name,
    description: form.description || null,
    price_monthly: form.price_monthly,
    included_orders: orNull(form.included_orders),
    overage_per_order: orNull(form.included_orders) === null ? 0 : (form.overage_per_order || 0),
    max_couriers: orNull(form.max_couriers),
    features: form.features,
    ...(form.id ? { is_active: form.is_active } : {}),
  }
  try {
    if (form.id) await http.patch(`/console/plans/${form.id}`, payload)
    else await http.post('/console/plans', payload)
    form.open = false
    toasts.success('Formule enregistrée.')
    await load()
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

onMounted(load)
</script>
