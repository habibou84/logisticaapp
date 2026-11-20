<template>
  <form v-if="repair" class="space-y-5" @submit.prevent="save">
    <p class="text-sm text-slate-600">Réparation {{ time(repair.started_at || repair.arrived_at) }} → maintenant · {{ duration(repair.started_at || repair.arrived_at) }}</p>

    <!-- Pièces utilisées -->
    <section class="space-y-2">
      <p class="m-label">Pièces utilisées</p>
      <div v-for="(line, i) in form.parts" :key="i" class="m-card space-y-2 p-3">
        <div class="flex gap-2">
          <input v-model="line.label" class="m-input flex-1 py-2.5" maxlength="120" placeholder="Pièce" :aria-label="`Pièce ${i + 1}`">
          <button type="button" class="h-11 w-11 shrink-0 rounded-xl bg-slate-100 text-slate-600" :aria-label="`Retirer la pièce ${i + 1}`" @click="form.parts.splice(i, 1)">✕</button>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <input v-model.number="line.quantity" class="m-input py-2.5" type="number" min="1" inputmode="numeric" :aria-label="`Quantité, pièce ${i + 1}`" placeholder="Quantité">
          <input v-model.number="line.unit_price" class="m-input py-2.5" type="number" min="0" inputmode="numeric" :aria-label="`Prix unitaire en francs, pièce ${i + 1}`" placeholder="Prix unitaire (F)">
        </div>
      </div>
      <button type="button" class="m-btn-secondary py-3" @click="form.parts.push({ label: '', quantity: 1, unit_price: null })">＋ Ajouter une pièce</button>
      <p class="text-xs text-slate-500">Le magasin de pièces arrive bientôt : en attendant, indiquez le prix de chaque pièce.</p>
    </section>

    <!-- Achats sur place accordés -->
    <section v-if="approved.length" class="space-y-2">
      <p class="m-label">Achats sur place</p>
      <div v-for="p in approved" :key="p.id" class="m-card space-y-2 p-3">
        <p class="text-sm font-semibold">{{ p.quantity }} × {{ p.label }} <span class="font-normal text-slate-500">· accordé jusqu'à {{ money(p.max_amount) }}</span></p>
        <input v-model.number="paid[p.id]" class="m-input py-2.5" type="number" min="0" inputmode="numeric" :aria-label="`Prix payé pour ${p.label}`" placeholder="Prix payé (F) ; vide si pas acheté">
        <p v-if="Number(paid[p.id]) > p.max_amount" class="text-sm text-red-700">Au-delà du montant accordé ({{ money(p.max_amount) }}) : demandez un nouvel accord pour la différence.</p>
        <PhotoCapture class="m-btn-secondary py-2.5 text-sm" @captured="receipts[p.id] = $event.file">
          {{ receipts[p.id] ? '✓ Facture photographiée' : '📷 Photographier la facture' }}
        </PhotoCapture>
      </div>
    </section>
    <p v-if="pending.length" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{{ pending.length }} demande(s) d'achat attend(ent) la réponse de l'administrateur : vous pourrez clôturer après sa réponse.</p>

    <div v-if="repair.decision === 'towed'" class="grid grid-cols-2 gap-2">
      <div class="col-span-2">
        <label for="close-garage" class="m-label">Garage</label>
        <input id="close-garage" v-model="form.garage" class="m-input" maxlength="120" placeholder="Nom du garage">
      </div>
      <div class="col-span-2">
        <label for="close-garage-amount" class="m-label">Montant du garage (F)</label>
        <input id="close-garage-amount" v-model.number="form.garage_amount" class="m-input" type="number" min="0" inputmode="numeric">
      </div>
    </div>

    <div>
      <label for="close-odometer" class="m-label">Compteur (km)</label>
      <input id="close-odometer" v-model.number="form.odometer" class="m-input" type="number" min="0" inputmode="numeric" :placeholder="repair.vehicle?.odometer ? `Dernier relevé : ${repair.vehicle.odometer.toLocaleString('fr-FR')} km` : ''">
    </div>

    <fieldset>
      <legend class="m-label">Cause de la panne</legend>
      <div class="space-y-2">
        <label v-for="c in mechanicSetup.causes" :key="c.key" :class="['tap flex items-center gap-3 rounded-xl border-[1.5px] bg-white px-4 py-3', form.cause === c.key ? 'border-[var(--app-color)] bg-orange-50' : 'border-slate-200']">
          <input v-model="form.cause" type="radio" name="cause" :value="c.key" class="h-5 w-5 accent-[var(--app-color)]">
          <span class="font-medium">{{ c.label }}</span>
        </label>
      </div>
    </fieldset>

    <div>
      <p class="m-label">Photos après réparation</p>
      <RepairPhotos :repair="repair" kind="after" editable @updated="repair = $event" />
    </div>

    <div>
      <label for="close-remark" class="m-label">À prévoir (facultatif)</label>
      <input id="close-remark" v-model="form.remark" class="m-input" maxlength="2000" placeholder="Ex. : batterie à changer sous un mois">
    </div>

    <label v-if="repair.decision === 'fixed'" class="tap flex items-center justify-between gap-3 rounded-2xl bg-emerald-50 px-4 py-3.5 font-semibold text-emerald-800 ring-1 ring-emerald-200">
      <span>Le livreur peut repartir</span>
      <input v-model="form.courier_can_resume" type="checkbox" class="h-6 w-6 accent-emerald-600">
    </label>
    <p v-else class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{{ repair.decision_label }} : le livreur reste à l'arrêt, l'agence organise la suite.</p>

    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
    <button type="submit" class="m-btn-success text-lg" :disabled="busy || !form.cause || pending.length > 0 || overLimit">Terminer · total {{ money(total) }}</button>
  </form>
  <p v-else-if="error" class="py-10 text-center text-slate-600">{{ error }}</p>
  <p v-else class="py-10 text-center text-slate-400">Chargement…</p>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import PhotoCapture from '../../components/mobile/PhotoCapture.vue'
import RepairPhotos from '../../components/mechanic/RepairPhotos.vue'
import { duration, loadBoard, loadMechanicSetup, mechanicSetup, time } from '../../composables/useMechanic'
import { useToastStore } from '../../stores/toasts'
import { money } from '../../utils/format'

const route = useRoute()
const router = useRouter()
const toasts = useToastStore()
const repair = ref(null)
const error = ref('')
const busy = ref(false)
const form = reactive({ parts: [{ label: '', quantity: 1, unit_price: null }], odometer: null, cause: '', remark: '', garage: '', garage_amount: null, courier_can_resume: true })
const paid = reactive({})
const receipts = reactive({})

const approved = computed(() => (repair.value?.purchases || []).filter((p) => p.status === 'approved'))
const pending = computed(() => (repair.value?.purchases || []).filter((p) => p.status === 'pending'))
const overLimit = computed(() => approved.value.some((p) => Number(paid[p.id]) > p.max_amount))
const total = computed(() => form.parts.reduce((sum, l) => sum + (l.label ? (Number(l.quantity) || 1) * (Number(l.unit_price) || 0) : 0), 0)
  + approved.value.reduce((sum, p) => sum + (Number(paid[p.id]) || 0), 0)
  + (repair.value?.decision === 'towed' ? Number(form.garage_amount) || 0 : 0))

onMounted(async () => {
  try {
    await loadMechanicSetup()
    repair.value = (await http.get(`/mechanic/repairs/${route.params.id}`)).data.data
  } catch (e) {
    error.value = apiErrorMessage(e, 'Panne introuvable.')
  }
})

async function save() {
  busy.value = true
  error.value = ''
  try {
    const data = new FormData()
    form.parts.filter((l) => l.label?.trim()).forEach((l, i) => {
      data.append(`parts[${i}][label]`, l.label.trim())
      data.append(`parts[${i}][quantity]`, l.quantity || 1)
      data.append(`parts[${i}][unit_price]`, l.unit_price || 0)
    })
    approved.value.forEach((p) => {
      if (paid[p.id] !== undefined && paid[p.id] !== '' && paid[p.id] !== null) data.append(`purchases[${p.id}]`, paid[p.id])
      if (receipts[p.id]) data.append(`receipts[${p.id}]`, receipts[p.id])
    })
    for (const key of ['odometer', 'cause', 'remark', 'garage', 'garage_amount']) {
      if (form[key] !== null && form[key] !== '') data.append(key, form[key])
    }
    data.append('courier_can_resume', form.courier_can_resume && repair.value.decision === 'fixed' ? '1' : '0')
    data.append('at', new Date().toISOString())
    await http.post(`/mechanic/repairs/${repair.value.id}/close`, data)
    toasts.success(form.courier_can_resume && repair.value.decision === 'fixed' ? 'Intervention terminée : le livreur est remis en service.' : 'Intervention terminée : l\'agence est prévenue.')
    loadBoard()
    router.replace(`/mecanicien/pannes/${repair.value.id}`)
  } catch (e) {
    error.value = apiErrorMessage(e, 'Clôture pas enregistrée. Vérifiez votre réseau.')
  } finally {
    busy.value = false
  }
}
</script>
