<template>
  <!-- Département qui envoie, « demandé par », destinataire extérieur ou autre département (back-office) -->
  <section class="card p-4 space-y-3">
    <h3 class="font-semibold">Département</h3>
    <div class="grid sm:grid-cols-2 gap-3">
      <div>
        <label class="label" for="dep-from">Département qui envoie<span v-if="settings.required"> *</span></label>
        <select id="dep-from" v-model="form.department_id" class="input" :required="settings.required">
          <option v-if="!settings.required" :value="null">Aucun</option>
          <option v-else :value="null" disabled>Choisir…</option>
          <option v-for="d in senders" :key="d.id" :value="d.id">{{ d.label }}{{ quotaText(d) }}</option>
        </select>
        <p v-if="sender?.location" class="mt-1 text-xs text-gray-500">🏢 {{ sender.location }}<template v-if="sender.own_address"> · ramassage à son adresse ({{ sender.pickup_zone_name }})</template></p>
        <p v-if="errors.department_id" class="field-error">{{ errors.department_id[0] }}</p>
      </div>
      <div v-if="settings.requested_by !== 'off'">
        <label class="label" for="dep-by">Demandé par<span v-if="settings.requested_by === 'required'"> *</span></label>
        <input id="dep-by" v-model="form.requested_by" class="input" maxlength="120" :required="settings.requested_by === 'required'" placeholder="Nom de la personne du département">
        <p v-if="errors.requested_by" class="field-error">{{ errors.requested_by[0] }}</p>
      </div>
    </div>
    <div v-if="settings.internal_mail && all.length > 1">
      <p class="label">Destinataire</p>
      <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Destinataire">
        <button type="button" role="radio" :aria-checked="!internal" :class="chip(!internal)" @click="setInternal(false)">Personne extérieure</button>
        <button type="button" role="radio" :aria-checked="internal" :class="chip(internal)" @click="setInternal(true)">Un autre département (courrier interne)</button>
      </div>
      <div v-if="internal" class="mt-2">
        <label class="label" for="dep-to">Département destinataire *</label>
        <select id="dep-to" v-model="form.to_department_id" class="input" required>
          <option :value="null" disabled>Choisir…</option>
          <option v-for="d in all.filter((x) => x.id !== form.department_id)" :key="d.id" :value="d.id">{{ d.label }}</option>
        </select>
        <p v-if="target" class="mt-1 text-xs text-gray-500">
          Remis à {{ target.manager_name || 'son responsable' }}<template v-if="target.location"> · {{ target.location }}</template><template v-if="target.own_address"> · {{ target.pickup_zone_name }}</template>
        </p>
        <p v-if="errors.to_department_id" class="field-error">{{ errors.to_department_id[0] }}</p>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  form: { type: Object, required: true },
  all: { type: Array, required: true },
  senders: { type: Array, required: true },
  settings: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
})

const internal = ref(!!props.form.to_department_id)
const sender = computed(() => props.all.find((d) => d.id === props.form.department_id))
const target = computed(() => props.all.find((d) => d.id === props.form.to_department_id))
const chip = (on) => ['rounded-full px-3 py-1 text-sm ring-1', on ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']
const quotaText = (d) => (d.usage?.quota ? ` · ${d.usage.used}/${d.usage.quota} ce mois` : '')

function setInternal(on) {
  internal.value = on
  if (!on) props.form.to_department_id = null
}
// Un seul département possible : choisi d'office
watch(() => props.senders, (list) => {
  if (list.length === 1 && !props.form.department_id) props.form.department_id = list[0].id
  if (props.form.department_id && !list.some((d) => d.id === props.form.department_id)) props.form.department_id = null
}, { immediate: true })
</script>
