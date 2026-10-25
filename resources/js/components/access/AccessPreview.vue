<template>
  <Modal :open="!!userId" wide :title="data ? `Ce que voit ${data.name}` : 'Ce que voit ce compte'" @close="$emit('close')">
    <p v-if="error" class="field-error">{{ error }}</p>
    <p v-else-if="!data" class="text-sm text-gray-500">Chargement…</p>
    <div v-else class="space-y-4">
      <p class="text-sm">
        Rôle <strong>{{ data.role_label || '—' }}</strong>
        <span v-if="data.extra_permissions.length" class="text-emerald-700"> · {{ data.extra_permissions.length }} droit(s) ajouté(s)</span>
        <span v-if="data.denied_permissions.length" class="text-red-600"> · {{ data.denied_permissions.length }} retiré(s)</span>
      </p>

      <div class="rounded-lg bg-slate-50 p-3 text-sm space-y-1">
        <p class="font-semibold">Périmètre <span class="font-normal text-gray-500">({{ data.scope.from_role ? 'celui du rôle' : 'propre au compte' }})</span></p>
        <p>Activités : {{ data.scope.activities.length ? data.scope.activities.map(activityLabel).join(', ') : 'toutes' }}</p>
        <p>Zones : {{ data.scope.zones.length ? data.scope.zones.join(', ') : 'toutes' }}</p>
        <p v-if="data.scope.hubs.length">Dépôts : {{ data.scope.hubs.join(', ') }}</p>
      </div>

      <div v-if="data.space === 'staff'" class="grid sm:grid-cols-[14rem_1fr] gap-4">
        <!-- Son menu, tel qu'il l'aura -->
        <nav class="rounded-lg bg-slate-900 text-slate-100 p-3 text-sm space-y-2" aria-label="Menu de ce compte">
          <p v-for="i in menu.top" :key="i.to">{{ i.icon }} {{ i.label }}</p>
          <div v-for="g in menu.groups" :key="g.id">
            <p class="text-[11px] uppercase tracking-wide text-slate-400 mt-2">{{ g.label }}</p>
            <p v-for="i in g.items" :key="i.to" class="pl-1">{{ i.icon }} {{ i.label }}</p>
          </div>
        </nav>
        <div class="space-y-2">
          <div v-for="d in permissionsByDomain" :key="d.value" class="text-sm">
            <p class="font-semibold">{{ d.label }}</p>
            <ul class="flex flex-wrap gap-1 mt-1">
              <li v-for="p in d.permissions" :key="p.value" :title="p.description"
                  :class="['rounded-full px-2 py-0.5 text-xs', data.permissions.includes(p.value) ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-400 line-through']">
                {{ p.label }}
              </li>
            </ul>
          </div>
        </div>
      </div>
      <p v-else class="text-sm text-gray-600">Compte {{ data.space === 'courier' ? 'livreur : application livreur, ses missions' : 'client : espace client' }}.</p>
    </div>
  </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../Modal.vue'
import { filterMenu } from '../../utils/adminMenu'
import { useAccessCatalog } from '../../composables/useAccessCatalog'

/**
 * « Voir comme ce compte » : son menu, ses droits effectifs (rôle, ajoutés, retirés) et son périmètre.
 */
const props = defineProps({ userId: { type: Number, default: null } })
defineEmits(['close'])
const { catalog, permissionsByDomain } = useAccessCatalog()
const data = ref(null)
const error = ref('')
const activityLabel = (a) => catalog.activities.find((x) => x.value === a)?.label || a

const menu = computed(() => {
  if (!data.value) return { top: [], groups: [] }
  const perms = data.value.permissions
  const activities = data.value.scope.activities.length ? data.value.scope.activities : catalog.activities.map((a) => a.value)
  return filterMenu((p) => [p].flat().some((x) => perms.includes(x)), activities)
})

watch(() => props.userId, async (id) => {
  data.value = null
  error.value = ''
  if (!id) return
  try {
    data.value = (await http.get(`/users/${id}/access`)).data.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>
