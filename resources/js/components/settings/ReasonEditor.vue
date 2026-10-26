<template>
  <form class="p-4 space-y-3" @submit.prevent="emit('save')">
    <div class="grid sm:grid-cols-2 gap-3">
      <div class="sm:col-span-2">
        <label class="label" for="reason-label">Libellé *</label>
        <input id="reason-label" v-model="model.label" class="input" maxlength="120" required>
      </div>
      <div v-if="tab !== 'follow_up'">
        <label class="label" for="reason-stage">Étape</label>
        <select id="reason-stage" v-model="model.applies_to" class="input">
          <option value="delivery">Livraison</option>
          <option value="pickup">Ramassage</option>
          <option value="both">Ramassage et livraison</option>
        </select>
      </div>
      <div v-if="tab !== 'follow_up'">
        <label class="label" for="reason-family">Famille</label>
        <select id="reason-family" v-model="model.family" class="input">
          <option v-for="(f, key) in REASON_FAMILIES" :key="key" :value="key">{{ f.icon }} {{ f.label }}</option>
        </select>
      </div>
    </div>

    <fieldset v-if="tab !== 'follow_up'">
      <legend class="label">Activités (rien de coché : toutes)</legend>
      <div class="flex flex-wrap gap-3">
        <label v-for="a in ACTIVITIES" :key="a.value" class="flex items-center gap-1.5 text-sm">
          <input v-model="model.activities" type="checkbox" :value="a.value"> {{ a.label }}
        </label>
      </div>
    </fieldset>

    <fieldset class="grid sm:grid-cols-2 gap-2 text-sm">
      <legend class="label">Ce que le motif demande et ce qu'il fait</legend>
      <label class="flex items-start gap-2"><input v-model="model.requires_date" type="checkbox" class="mt-1">
        <span>Demande une date<span class="block text-gray-500">{{ tab === 'follow_up' ? 'La course est reprogrammée à cette date.' : 'Report : nouvelle date de livraison.' }}</span></span></label>
      <label class="flex items-start gap-2"><input v-model="model.requires_note" type="checkbox" class="mt-1">
        <span>Note obligatoire<span class="block text-gray-500">Préciser ce qui se passe (« Autres »).</span></span></label>
      <template v-if="tab !== 'follow_up'">
        <label class="flex items-start gap-2"><input v-model="model.counts_as_attempt" type="checkbox" class="mt-1">
          <span>Compte une tentative<span class="block text-gray-500">Au bout du nombre de tentatives réglé, la course part en retour.</span></span></label>
        <label class="flex items-start gap-2"><input v-model="model.triggers_return" type="checkbox" class="mt-1">
          <span>Retour au client tout de suite<span class="block text-gray-500">Le pli revient, compté comme une course ; elle peut être reconduite.</span></span></label>
        <div>
          <label class="label" for="reason-photo">Photo du livreur</label>
          <select id="reason-photo" v-model="model.photo" class="input">
            <option value="off">Non</option>
            <option value="optional">Possible</option>
            <option value="required">Obligatoire</option>
          </select>
        </div>
      </template>
      <template v-else>
        <label class="flex items-start gap-2"><input v-model="model.holds" type="checkbox" class="mt-1">
          <span>Met la course en attente<span class="block text-gray-500">Ni attribution automatique, ni file « sans livreur » tant que la note est posée.</span></span></label>
        <label class="flex items-start gap-2"><input v-model="model.visible_to_client" type="checkbox" class="mt-1">
          <span>Visible du client<span class="block text-gray-500">La note apparaît dans son suivi de la course.</span></span></label>
      </template>
      <div>
        <label class="label" for="reason-effect">Fiche de l'annuaire</label>
        <select id="reason-effect" v-model="model.destination_effect" class="input">
          <option :value="null">Ne rien changer</option>
          <option v-for="(label, key) in DESTINATION_EFFECTS" :key="key" :value="key">{{ label }}</option>
        </select>
      </div>
    </fieldset>

    <p v-if="error" class="text-red-600 text-sm">{{ error }}</p>
    <div class="flex gap-2">
      <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      <button type="button" class="btn-secondary" @click="emit('cancel')">Annuler</button>
      <button v-if="deletable" type="button" class="ml-auto text-sm text-red-600" @click="emit('delete')">Supprimer</button>
    </div>
  </form>
</template>

<script setup>
import { ACTIVITIES } from '../../utils/activities'
import { DESTINATION_EFFECTS, REASON_FAMILIES } from '../../utils/reasons'

// Le formulaire modifie l'objet en cours d'édition, le parent enregistre
defineProps({
  model: { type: Object, required: true },
  tab: { type: String, required: true },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
  deletable: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'cancel', 'delete'])
</script>
