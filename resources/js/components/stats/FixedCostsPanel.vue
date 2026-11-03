<template>
  <section class="card space-y-3 p-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="font-semibold">Frais fixes de l'entreprise</h2>
        <p class="text-sm text-slate-500">Loyer, salaires de l'équipe, téléphone… Comptés dans la marge nette, au prorata des jours de la période.</p>
      </div>
      <p class="text-sm">Par mois : <b>{{ money(monthlyTotal) }}</b></p>
    </div>

    <p v-if="error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

    <ul class="divide-y text-sm">
      <li v-for="c in costs" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
        <span class="min-w-[12rem] flex-1">
          <b>{{ c.label }}</b> <span class="text-slate-500">· {{ c.category_label }}</span>
          <span class="block text-xs text-slate-500">{{ c.frequency === 'once' ? `une fois, le ${date(c.starts_on)}` : `chaque mois depuis le ${date(c.starts_on)}${c.ends_on ? ` jusqu'au ${date(c.ends_on)}` : ''}` }}</span>
        </span>
        <span class="font-semibold">{{ money(c.amount) }}</span>
        <span v-if="canManage" class="flex gap-2">
          <button type="button" class="text-blue-600" @click="edit(c)">Modifier</button>
          <button type="button" class="text-red-600" @click="remove(c)">Supprimer</button>
        </span>
      </li>
      <li v-if="!costs.length" class="py-4 text-center text-slate-500">Aucun frais fixe saisi : la marge nette ne compte que les coûts des courses.</li>
    </ul>

    <form v-if="canManage" class="grid gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-6" @submit.prevent="save">
      <input v-model="form.label" class="input sm:col-span-2" placeholder="Libellé (ex. Loyer du dépôt)" aria-label="Libellé" required>
      <select v-model="form.category" class="input" aria-label="Catégorie">
        <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
      </select>
      <input v-model.number="form.amount" type="number" min="1" class="input" placeholder="Montant" aria-label="Montant" required>
      <select v-model="form.frequency" class="input" aria-label="Fréquence">
        <option value="monthly">Chaque mois</option>
        <option value="once">Une fois</option>
      </select>
      <input v-model="form.starts_on" type="date" class="input" :aria-label="form.frequency === 'once' ? 'Date' : 'Depuis le'" required>
      <input v-if="form.frequency === 'monthly'" v-model="form.ends_on" type="date" class="input" aria-label="Jusqu'au (facultatif)" title="Jusqu'au (facultatif)">
      <div class="flex gap-2 sm:col-span-2">
        <button type="submit" class="btn-primary" :disabled="saving">{{ form.id ? 'Enregistrer' : '+ Ajouter' }}</button>
        <button v-if="form.id" type="button" class="btn-secondary" @click="reset">Annuler</button>
      </div>
    </form>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import { useAuthStore } from '../../stores/auth'
import { date, money, today } from '../../utils/format'

const emit = defineEmits(['changed'])
const auth = useAuthStore()
const canManage = computed(() => auth.can('finance.manage'))
const costs = ref([])
const categories = ref({})
const error = ref('')
const saving = ref(false)
const blank = () => ({ id: null, label: '', category: 'rent', amount: null, frequency: 'monthly', starts_on: `${today().slice(0, 7)}-01`, ends_on: '' })
const form = reactive(blank())
const reset = () => Object.assign(form, blank())

// Frais mensuels en cours aujourd'hui
const monthlyTotal = computed(() => costs.value.filter((c) => c.frequency === 'monthly' && c.starts_on <= today() && (!c.ends_on || c.ends_on >= today())).reduce((s, c) => s + c.amount, 0))

async function load() {
  try {
    const { data } = await http.get('/fixed-costs')
    costs.value = data.data
    categories.value = data.categories
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function edit(c) {
  Object.assign(form, { ...c, ends_on: c.ends_on || '' })
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    const payload = { ...form, ends_on: form.frequency === 'monthly' && form.ends_on ? form.ends_on : null }
    if (form.id) await http.put(`/fixed-costs/${form.id}`, payload)
    else await http.post('/fixed-costs', payload)
    reset()
    await load()
    emit('changed')
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function remove(c) {
  if (!window.confirm(`Supprimer « ${c.label} » ?`)) return
  await http.delete(`/fixed-costs/${c.id}`)
  await load()
  emit('changed')
}

onMounted(load)
</script>
