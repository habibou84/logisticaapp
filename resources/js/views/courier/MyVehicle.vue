<template>
  <div v-if="info" class="space-y-4">
    <template v-if="info.vehicle">
      <div class="m-card flex items-center gap-3 p-4">
        <span class="grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-3xl">{{ VEHICLE_TYPES[info.vehicle.type]?.icon }}</span>
        <div class="min-w-0 flex-1">
          <p class="font-mono text-lg font-bold">{{ info.vehicle.plate }}</p>
          <p class="text-sm text-slate-500">{{ [info.vehicle.brand, info.vehicle.model].filter(Boolean).join(' ') }} · {{ km(info.vehicle.odometer) }}</p>
        </div>
        <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', OWNERSHIP_STYLES[info.vehicle.ownership]]">{{ info.vehicle.ownership_label }}</span>
      </div>
      <p v-if="info.summary?.problems?.length" class="rounded-2xl bg-red-50 p-4 text-sm text-red-800 ring-1 ring-red-200">⛔ {{ info.summary.problems.join(', ') }} : voyez avec l'agence.</p>

      <!-- Location-vente -->
      <div v-if="info.lease" class="m-card p-4">
        <template v-if="info.lease.kind === 'purchase'">
          <p class="font-semibold">Mon véhicule sera à moi {{ info.lease.ends_on ? `le ${date(info.lease.ends_on)}` : 'à la fin des versements' }}</p>
          <div class="mt-2 h-3 rounded-full bg-slate-100"><div class="h-3 rounded-full bg-violet-500" :style="{ width: `${info.lease.progress}%` }" /></div>
          <div class="mt-2 flex justify-between text-sm text-slate-600"><span>Payé {{ money(info.lease.paid) }}</span><span>Reste {{ money(info.lease.remaining) }}</span></div>
        </template>
        <p v-else class="font-semibold">Location : {{ money(info.lease.amount) }} par {{ PERIODS[info.lease.period] }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ info.lease.payment === 'payroll' ? 'Retenu sur votre paie.' : 'À payer en caisse.' }}</p>
        <p v-if="info.lease.late" class="mt-2 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-800">
          {{ info.lease.late_periods }} versement{{ info.lease.late_periods > 1 ? 's' : '' }} en retard ({{ money(info.lease.late) }}){{ info.lease.payment === 'payroll' ? ' : retenu sur votre prochaine paie.' : ' : passez à la caisse.' }}
        </p>
      </div>

      <!-- Papiers -->
      <div class="m-card p-4">
        <p class="font-semibold">Papiers</p>
        <ul class="mt-2 space-y-2 text-sm">
          <li v-for="d in info.documents" :key="d.id" class="flex items-center justify-between gap-2">
            <button type="button" class="text-left" :disabled="!d.has_file" @click="openDoc(d)">{{ d.icon }} {{ d.label }}<span v-if="d.has_file" class="ml-1 text-[var(--app-color)]">· voir</span></button>
            <span :class="['rounded-full px-2 text-xs', STATE_STYLES[d.state]]">{{ d.state === 'permanent' ? 'permanent' : d.state === 'ok' ? 'à jour' : d.state === 'expired' ? `dépassé le ${shortDate(d.ends_on)}` : `fin le ${shortDate(d.ends_on)}` }}</span>
          </li>
          <li v-if="!info.documents.length" class="text-slate-500">Aucun papier enregistré par l'agence.</li>
        </ul>
        <p class="mt-2 text-xs text-slate-500">Les documents sont dans l'application : montrez-les en cas de contrôle.</p>
      </div>

      <!-- Entretien et carburant -->
      <div class="m-card p-4">
        <p class="font-semibold">Entretien</p>
        <div v-for="m in info.maintenance" :key="m.key" class="mt-1.5 flex justify-between text-sm">
          <span>{{ m.icon }} {{ m.label }}</span><b :class="m.state === 'due' ? 'text-red-700' : m.state === 'soon' ? 'text-amber-700' : ''">{{ remainingText(m) }}</b>
        </div>
        <div v-if="info.fuel_month?.count" class="mt-1.5 flex justify-between text-sm">
          <span>⛽ Ce mois</span><b>{{ info.fuel_month.count }} plein{{ info.fuel_month.count > 1 ? 's' : '' }} · {{ money(info.fuel_month.amount) }}</b>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <button type="button" class="m-btn-secondary" @click="fuelOpen = true">⛽ Plein</button>
        <RouterLink :to="`/livreur/vehicule/etat-des-lieux${info.summary?.check_needed ? '' : '?moment=end'}`" class="m-btn-secondary">📋 {{ info.summary?.check_needed ? 'État des lieux' : 'Retour' }}</RouterLink>
      </div>
    </template>

    <template v-else>
      <div class="m-card p-6 text-center text-slate-600">
        <p class="text-4xl">🔑</p>
        <p class="mt-2">Aucun véhicule à votre nom.</p>
        <RouterLink v-if="info.free_vehicles.length" to="/livreur/vehicule/etat-des-lieux" class="m-btn-primary mt-4">Prendre un véhicule pour la journée</RouterLink>
      </div>
      <div v-if="info.documents.length" class="m-card p-4">
        <p class="font-semibold">Mes papiers</p>
        <p v-for="d in info.documents" :key="d.id" class="mt-2 flex justify-between text-sm"><span>{{ d.icon }} {{ d.label }}</span><span :class="['rounded-full px-2 text-xs', STATE_STYLES[d.state]]">{{ d.ends_on ? `fin le ${shortDate(d.ends_on)}` : 'permanent' }}</span></p>
      </div>
    </template>

    <!-- Plein -->
    <BottomSheet :open="fuelOpen" title="⛽ J'ai fait le plein" @close="fuelOpen = false">
      <div class="space-y-4">
        <div>
          <label class="m-label" for="fuel-amount">Montant payé</label>
          <div class="relative"><input id="fuel-amount" v-model.number="fuel.amount" type="number" inputmode="numeric" min="1" class="m-input pr-12 text-2xl font-bold"><span class="absolute right-4 top-1/2 -translate-y-1/2 font-semibold text-slate-400">F</span></div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="m-label" for="fuel-liters">Litres</label><input id="fuel-liters" v-model.number="fuel.liters" type="number" inputmode="decimal" step="0.1" min="0" class="m-input"></div>
          <div><label class="m-label" for="fuel-odo">Compteur</label><input id="fuel-odo" v-model.number="fuel.odometer" type="number" inputmode="numeric" min="0" class="m-input" :placeholder="String(info.vehicle?.odometer || '')"></div>
        </div>
        <label :class="['m-btn-secondary cursor-pointer', info.settings.fuel_receipt_required && !receipt ? 'ring-2 ring-amber-400' : '']">
          📷 {{ receipt ? 'Reçu photographié ✓' : `Photo du reçu${info.settings.fuel_receipt_required ? ' *' : ''}` }}
          <input type="file" accept="image/*" capture="environment" class="hidden" @change="addReceipt">
        </label>
        <p class="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600">{{ fuelNote }}</p>
        <p v-if="fuelError" class="text-sm text-red-600">{{ fuelError }}</p>
        <button type="button" class="m-btn-primary" :disabled="busy || !(fuel.amount > 0) || (info.settings.fuel_receipt_required && !receipt)" @click="sendFuel">Enregistrer le plein</button>
      </div>
    </BottomSheet>
  </div>
  <p v-else class="py-10 text-center text-slate-400">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import { authImage } from '../../composables/useAuthImage'
import { shrinkPhoto } from '../../composables/useFieldEvents'
import { useToastStore } from '../../stores/toasts'
import { date, money } from '../../utils/format'
import { OWNERSHIP_STYLES, PERIODS, STATE_STYLES, VEHICLE_TYPES, km, remainingText } from '../../utils/vehicles'

// Mon véhicule (application livreur) : location-vente, papiers à montrer, entretien, pleins
const toasts = useToastStore()
const info = ref(null)
const busy = ref(false)
const fuelOpen = ref(false)
const fuelError = ref('')
const fuel = reactive({ amount: null, liters: null, odometer: null })
const receipt = ref(null)
const shortDate = (d) => new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })

const fuelNote = computed(() => {
  const s = info.value.settings
  if (s.fuel_mode === 'refund') return 'Ce plein vous est remboursé sur votre prochaine paie.'
  if (s.fuel_mode === 'allowance') return `Dotation du jour : ${money(s.fuel_allowance)}, ajoutée à votre paie.${s.fuel_allowance_excess_refund ? ' Ce qui dépasse vous est remboursé.' : ''}`
  return 'Le carburant est à votre charge : le plein sert à suivre la consommation de la moto.'
})

async function load() {
  info.value = (await http.get('/courier/vehicle')).data.data
}

async function openDoc(d) {
  const src = await authImage(`/courier/vehicle/documents/${d.id}`).catch(() => null)
  if (src) window.open(src, '_blank')
}

async function addReceipt(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (file) receipt.value = await shrinkPhoto(file)
}

async function sendFuel() {
  busy.value = true
  fuelError.value = ''
  const body = new FormData()
  Object.entries(fuel).forEach(([k, v]) => v !== null && v !== '' && body.append(k, v))
  if (receipt.value) body.append('photos[]', receipt.value, 'recu.jpg')
  try {
    info.value = (await http.post('/courier/vehicle/fuel', body)).data.data
    Object.assign(fuel, { amount: null, liters: null, odometer: null })
    receipt.value = null
    fuelOpen.value = false
    toasts.success('Plein enregistré.')
  } catch (e) {
    fuelError.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
