<template>
  <!-- Département du client au ramassage : qui remet la course au livreur (nom, fonction, signature) -->
  <div class="space-y-3">
    <p v-if="department" class="rounded-xl bg-violet-50 p-3 text-sm text-violet-900">🏢 <strong>{{ department.label }}</strong><template v-if="department.location"> · {{ department.location }}</template></p>
    <div v-if="proof.handover_name !== 'off'" class="grid grid-cols-2 gap-2">
      <div>
        <label class="m-label" for="handed-by">Qui vous remet ?{{ proof.handover_name === 'required' ? ' *' : '' }}</label>
        <input id="handed-by" v-model="model.handed_by_name" class="m-input" maxlength="120" placeholder="Nom">
      </div>
      <div>
        <label class="m-label" for="handed-role">Fonction</label>
        <input id="handed-role" v-model="model.handed_by_role" class="m-input" maxlength="80" placeholder="Ex. : assistante">
      </div>
    </div>
    <div v-if="proof.handover_signature === 'required' || model.signature !== null">
      <p class="m-label">Signature{{ proof.handover_signature === 'required' ? ' *' : '' }}</p>
      <SignaturePad v-model="model.signature" />
    </div>
    <button v-else-if="proof.handover_signature === 'optional'" type="button" class="text-sm font-medium text-[var(--app-color)]" @click="model.signature = ''">+ Faire signer</button>
  </div>
</template>

<script setup>
import SignaturePad from '../mobile/SignaturePad.vue'

// model : { handed_by_name, handed_by_role, signature } (signature null : pas demandée)
defineProps({
  model: { type: Object, required: true },
  proof: { type: Object, required: true },
  department: { type: Object, default: null },
})
</script>
