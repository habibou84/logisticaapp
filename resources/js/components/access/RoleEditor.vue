<template>
  <Modal :open="open" wide :title="role ? `Rôle « ${role.label} »` : 'Nouveau rôle'" @close="$emit('close')">
    <form class="space-y-4" @submit.prevent="save">
      <p v-if="error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ error }}</p>

      <!-- Modèle de départ : un rôle courant, ou une copie d'un rôle intégré -->
      <div v-if="!role">
        <label class="label" for="re-template">Partir de</label>
        <select id="re-template" v-model="form.template" class="input" @change="applyTemplate">
          <option value="">Rien (je coche moi-même)</option>
          <optgroup label="Rôles courants">
            <option v-for="t in catalog.templates.filter((t) => !t.key.startsWith('role:'))" :key="t.key" :value="t.key">{{ t.label }}</option>
          </optgroup>
          <optgroup label="Copie d'un rôle intégré">
            <option v-for="t in catalog.templates.filter((t) => t.key.startsWith('role:'))" :key="t.key" :value="t.key">{{ t.label }}</option>
          </optgroup>
        </select>
        <p v-if="template" class="text-xs text-gray-500 mt-1">{{ template.description }}</p>
      </div>

      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label" for="re-label">Nom du rôle *</label><input id="re-label" v-model="form.label" class="input" required maxlength="80" placeholder="Comptable"></div>
        <div><label class="label" for="re-desc">Description</label><input id="re-desc" v-model="form.description" class="input" maxlength="500" placeholder="Ce que fait ce rôle"></div>
      </div>

      <details class="rounded-lg border border-slate-200 p-3" :open="hasScope">
        <summary class="text-sm font-semibold cursor-pointer">Périmètre <span class="font-normal text-gray-500">(par défaut pour les comptes de ce rôle)</span></summary>
        <ScopeFields v-model="form.scope" class="mt-2" hint="Un compte peut avoir son propre périmètre : il remplace alors celui du rôle." />
      </details>

      <div>
        <p class="text-sm font-semibold mb-2">Droits · {{ form.permissions.length }}</p>
        <PermissionPicker v-model="form.permissions" />
      </div>

      <div class="flex flex-wrap justify-between gap-2">
        <button v-if="role && role.users_count === 0" type="button" class="text-sm text-red-700" :disabled="saving" @click="remove">Supprimer ce rôle</button>
        <span v-else-if="role" class="text-xs text-gray-500">{{ role.users_count }} compte(s) ont ce rôle : les changements s'appliquent à eux.</span>
        <span v-else />
        <div class="flex gap-2">
          <button type="button" class="btn-secondary" @click="$emit('close')">Annuler</button>
          <button class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        </div>
      </div>
    </form>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import PermissionPicker from './PermissionPicker.vue'
import ScopeFields from './ScopeFields.vue'
import { useAccessCatalog } from '../../composables/useAccessCatalog'

/**
 * Créer ou modifier un rôle sur mesure ; « from » : rôle intégré à copier.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  role: { type: Object, default: null },
  from: { type: String, default: '' },
})
const emit = defineEmits(['close', 'saved'])
const { catalog } = useAccessCatalog()

const form = reactive({ template: '', label: '', description: '', permissions: [], scope: { activities: [], zone_ids: [], hub_ids: [] } })
const saving = ref(false)
const error = ref('')
const template = computed(() => catalog.templates.find((t) => t.key === form.template))
const hasScope = computed(() => form.scope.activities.length + form.scope.zone_ids.length + form.scope.hub_ids.length > 0)

watch(() => props.open, (open) => {
  if (!open) return
  error.value = ''
  const r = props.role
  Object.assign(form, {
    template: props.from || '',
    label: r?.label ?? '',
    description: r?.description ?? '',
    permissions: [...(r?.permissions ?? [])],
    scope: { activities: [...(r?.activities ?? [])], zone_ids: [...(r?.zone_ids ?? [])], hub_ids: [...(r?.hub_ids ?? [])] },
  })
  if (!r && props.from) applyTemplate()
})

// Le modèle remplit les droits (seulement ceux que l'on peut donner) et propose un nom
function applyTemplate() {
  const t = template.value
  if (!t) return
  form.permissions = t.permissions.filter((p) => catalog.grantable.includes(p))
  if (!form.label || catalog.templates.some((x) => x.label === form.label)) form.label = t.key.startsWith('role:') ? `${t.label} (sur mesure)` : t.label
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    const payload = { label: form.label, description: form.description || null, template: form.template || props.role?.template || null, permissions: form.permissions, ...form.scope }
    const { data } = props.role
      ? await http.put(`/roles/${props.role.id}`, payload)
      : await http.post('/roles', payload)
    emit('saved', data.data)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!confirm(`Supprimer le rôle « ${props.role.label} » ?`)) return
  saving.value = true
  try {
    await http.delete(`/roles/${props.role.id}`)
    emit('saved', null)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>
