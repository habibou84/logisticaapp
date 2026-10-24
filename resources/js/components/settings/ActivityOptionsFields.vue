<template>
  <!--
    Réglages distribution / MAD : preuve de remise, messages au destinataire, réservation des livreurs en MAD.
    Entreprise : oui / non. Contrat (inherit = réglages de l'entreprise) : « comme l'entreprise », oui ou non.
  -->
  <div class="space-y-3 text-sm">
    <div>
      <p class="font-medium">Preuve de remise demandée au livreur</p>
      <div class="mt-1 grid gap-2 sm:grid-cols-3">
        <div v-for="p in PROOFS" :key="p.key" class="rounded-lg border border-slate-200 p-2">
          <label v-if="!inherit" class="flex items-start gap-2">
            <input :checked="!!modelValue[p.key]" type="checkbox" class="mt-0.5" @change="set(p.key, $event.target.checked)">
            <span>{{ p.label }}<span class="block text-xs text-gray-500">{{ p.hint }}</span></span>
          </label>
          <template v-else>
            <label class="block text-xs text-gray-600" :for="`${uid}-${p.key}`">{{ p.label }}</label>
            <select :id="`${uid}-${p.key}`" :value="tri(p.key)" class="input mt-1" @change="setTri(p.key, $event.target.value)">
              <option value="">Comme l'entreprise ({{ inherit[p.key] ? 'oui' : 'non' }})</option>
              <option value="1">Oui</option>
              <option value="0">Non</option>
            </select>
          </template>
        </div>
      </div>
    </div>

    <div class="rounded-lg border border-slate-200 p-2">
      <label v-if="!inherit" class="flex items-start gap-2">
        <input :checked="!!modelValue.notify_recipient" type="checkbox" class="mt-0.5" @change="set('notify_recipient', $event.target.checked)">
        <span>Prévenir le destinataire sur WhatsApp<span class="block text-xs text-gray-500">« Un envoi est en route », sans montant, avec le code de remise et le suivi.</span></span>
      </label>
      <template v-else>
        <label class="block text-xs text-gray-600" :for="`${uid}-notify`">Prévenir le destinataire sur WhatsApp</label>
        <select :id="`${uid}-notify`" :value="tri('notify_recipient')" class="input mt-1" @change="setTri('notify_recipient', $event.target.value)">
          <option value="">Comme l'entreprise ({{ inherit.notify_recipient ? 'oui' : 'non' }})</option>
          <option value="1">Oui</option>
          <option value="0">Non</option>
        </select>
      </template>
    </div>

    <!-- MAD : quand les livreurs sont réservés au client -->
    <div v-if="activity === 'mad'" class="rounded-lg border border-slate-200 p-2 space-y-2">
      <label v-if="!inherit" class="flex items-start gap-2">
        <input :checked="!!modelValue.mad_exclusive" type="checkbox" class="mt-0.5" @change="set('mad_exclusive', $event.target.checked)">
        <span>Livreurs réservés au client pendant la mise à disposition<span class="block text-xs text-gray-500">Ils ne reçoivent pas automatiquement les courses des autres clients.</span></span>
      </label>
      <template v-else>
        <label class="block text-xs text-gray-600" :for="`${uid}-excl`">Livreurs réservés au client</label>
        <select :id="`${uid}-excl`" :value="tri('mad_exclusive')" class="input mt-1" @change="setTri('mad_exclusive', $event.target.value)">
          <option value="">Comme l'entreprise ({{ inherit.mad_exclusive ? 'oui' : 'non' }})</option>
          <option value="1">Oui</option>
          <option value="0">Non</option>
        </select>
      </template>

      <div>
        <p class="text-xs text-gray-600">
          Jours de mise à disposition
          <button v-if="inherit && modelValue.mad_days" type="button" class="ml-1 text-blue-600" @click="clear('mad_days')">comme l'entreprise</button>
        </p>
        <div class="mt-1 flex flex-wrap gap-1" role="group" aria-label="Jours de mise à disposition">
          <button
            v-for="(d, i) in WEEKDAYS" :key="d" type="button"
            :class="['rounded-full px-2.5 py-1 text-xs ring-1', days.includes(i + 1) ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
            :aria-pressed="days.includes(i + 1)" @click="toggleDay(i + 1)"
          >
            {{ d.slice(0, 3) }}
          </button>
        </div>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <div><label class="block text-xs text-gray-600" :for="`${uid}-from`">De</label><input :id="`${uid}-from`" :value="hour('mad_from')" type="time" class="input w-32" @change="set('mad_from', $event.target.value || null)"></div>
        <div><label class="block text-xs text-gray-600" :for="`${uid}-to`">À</label><input :id="`${uid}-to`" :value="hour('mad_to')" type="time" class="input w-32" @change="set('mad_to', $event.target.value || null)"></div>
        <p class="text-xs text-gray-500 pb-2">Vide : {{ inherit && inherit.mad_from ? `comme l'entreprise (${inherit.mad_from} – ${inherit.mad_to})` : 'toute la journée' }}.</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { WEEKDAYS } from '../../views/admin/settings/options'

const props = defineProps({
  modelValue: { type: Object, required: true },
  activity: { type: String, required: true },
  // Contrat : réglages de l'entreprise (valeur vide du contrat = celle-ci)
  inherit: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue'])
const uid = `ao-${Math.random().toString(36).slice(2, 8)}`

const PROOFS = [
  { key: 'proof_name', label: 'Nom de la personne qui reçoit', hint: 'Saisi par le livreur à la remise.' },
  { key: 'proof_signature', label: 'Signature', hint: 'Signée sur le téléphone du livreur.' },
  { key: 'proof_photo', label: 'Photo de la décharge', hint: 'Ou du cachet, prise avant de valider.' },
]

const days = computed(() => props.modelValue.mad_days ?? props.inherit?.mad_days ?? [1, 2, 3, 4, 5, 6, 7])
const hour = (key) => props.modelValue[key] ?? ''

function set(key, value) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}
function clear(key) {
  const next = { ...props.modelValue }
  delete next[key]
  emit('update:modelValue', next)
}
const tri = (key) => (props.modelValue[key] === true ? '1' : props.modelValue[key] === false ? '0' : '')
function setTri(key, value) {
  if (value === '') clear(key)
  else set(key, value === '1')
}
function toggleDay(day) {
  const list = days.value.includes(day) ? days.value.filter((d) => d !== day) : [...days.value, day].sort()
  set('mad_days', list)
}
</script>
