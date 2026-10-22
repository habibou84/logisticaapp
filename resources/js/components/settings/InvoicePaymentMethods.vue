<template>
  <!-- Moyens de paiement des factures clients : autant de comptes que voulu, chacun proposé ou non -->
  <div class="space-y-3">
    <div v-for="(m, i) in model" :key="i" :class="['rounded border p-3 space-y-2', m.enabled ? 'border-slate-300' : 'border-dashed border-slate-300 bg-slate-50']">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-lg">{{ ICONS[m.method] }}</span>
        <span class="font-medium">{{ title(m) }}</span>
        <label class="ml-auto flex items-center gap-2 text-sm">
          <input v-model="m.enabled" type="checkbox"> Proposé aux clients
        </label>
        <button type="button" class="text-xs text-slate-500" :disabled="i === 0" title="Monter" aria-label="Monter" @click="move(i, -1)">▲</button>
        <button type="button" class="text-xs text-slate-500" :disabled="i === model.length - 1" title="Descendre" aria-label="Descendre" @click="move(i, 1)">▼</button>
        <button type="button" class="text-xs text-red-600" @click="model.splice(i, 1)">Retirer</button>
      </div>

      <div v-if="m.method === 'bank_transfer'" class="grid sm:grid-cols-2 gap-2">
        <div><label class="label" :for="`pm-bank-${i}`">Banque *</label><input :id="`pm-bank-${i}`" v-model="m.bank" class="input" maxlength="80" placeholder="Ex. : SGCI, Ecobank, NSIA Banque"></div>
        <div><label class="label" :for="`pm-holder-${i}`">Titulaire du compte</label><input :id="`pm-holder-${i}`" v-model="m.holder" class="input" maxlength="80"></div>
        <div><label class="label" :for="`pm-iban-${i}`">IBAN *</label><input :id="`pm-iban-${i}`" v-model="m.iban" class="input font-mono uppercase" maxlength="50" placeholder="CI93 CI00 0101 2345 6789 0123 4567"></div>
        <div><label class="label" :for="`pm-bic-${i}`">BIC / SWIFT</label><input :id="`pm-bic-${i}`" v-model="m.bic" class="input font-mono uppercase" maxlength="20"></div>
      </div>

      <div v-else-if="m.method === 'mobile_money'" class="grid sm:grid-cols-3 gap-2">
        <div>
          <label class="label" :for="`pm-op-${i}`">Opérateur *</label>
          <input :id="`pm-op-${i}`" v-model="m.operator" class="input" :list="`pm-ops-${i}`" maxlength="40" placeholder="Orange Money">
          <datalist :id="`pm-ops-${i}`"><option v-for="o in OPERATORS" :key="o" :value="o" /></datalist>
        </div>
        <div><label class="label" :for="`pm-phone-${i}`">Numéro *</label><input :id="`pm-phone-${i}`" v-model="m.phone" class="input" inputmode="tel" maxlength="30" placeholder="07 00 00 00 00"></div>
        <div><label class="label" :for="`pm-mholder-${i}`">Au nom de</label><input :id="`pm-mholder-${i}`" v-model="m.holder" class="input" maxlength="80"></div>
      </div>

      <div v-else-if="m.method === 'cheque'">
        <label class="label" :for="`pm-payee-${i}`">À l'ordre de *</label><input :id="`pm-payee-${i}`" v-model="m.payee" class="input" maxlength="80">
      </div>

      <div v-else class="grid sm:grid-cols-3 gap-2">
        <div v-if="m.method === 'other'"><label class="label" :for="`pm-label-${i}`">Nom *</label><input :id="`pm-label-${i}`" v-model="m.label" class="input" maxlength="60" placeholder="Ex. : Carte bancaire"></div>
        <div :class="m.method === 'other' ? 'sm:col-span-2' : 'sm:col-span-3'">
          <label class="label" :for="`pm-details-${i}`">{{ m.method === 'cash' ? 'Où et quand' : 'Précisions' }}</label>
          <input :id="`pm-details-${i}`" v-model="m.details" class="input" maxlength="300" :placeholder="m.method === 'cash' ? 'Ex. : à l\'agence du Plateau, du lundi au vendredi' : ''">
        </div>
      </div>
    </div>

    <p v-if="!model.length" class="text-sm text-gray-500">Aucun moyen de paiement : ajoutez ceux que vous acceptez.</p>

    <div class="flex flex-wrap gap-2">
      <button v-for="t in TYPES" :key="t.value" type="button" class="btn-secondary text-sm" @click="add(t.value)">+ {{ t.label }}</button>
    </div>
  </div>
</template>

<script setup>
const model = defineModel({ type: Array, required: true })

const TYPES = [
  { value: 'bank_transfer', label: 'Compte bancaire (IBAN)' },
  { value: 'mobile_money', label: 'Mobile Money' },
  { value: 'cheque', label: 'Chèque' },
  { value: 'cash', label: 'Espèces' },
  { value: 'other', label: 'Autre' },
]
const ICONS = { bank_transfer: '🏦', mobile_money: '📱', cheque: '🧾', cash: '💵', other: '💳' }
const OPERATORS = ['Orange Money', 'MTN MoMo', 'Moov Money', 'Wave']

function title(m) {
  if (m.method === 'bank_transfer') return m.bank ? `Virement · ${m.bank}` : 'Virement bancaire'
  if (m.method === 'mobile_money') return m.operator || 'Mobile Money'
  if (m.method === 'other') return m.label || 'Autre'
  return TYPES.find((t) => t.value === m.method)?.label
}

function add(method) {
  model.value.push({ method, enabled: true })
}

function move(i, delta) {
  const [item] = model.value.splice(i, 1)
  model.value.splice(i + delta, 0, item)
}
</script>
