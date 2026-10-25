<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Rôles</h1>
        <p class="text-sm text-gray-600">Ce que chaque rôle de l'équipe peut faire. Les rôles intégrés servent de modèles ; créez vos rôles sur mesure.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <RouterLink to="/admin/utilisateurs" class="btn-secondary">👥 Comptes</RouterLink>
        <button v-if="canManage" type="button" class="btn-primary" @click="edit(null)">+ Nouveau rôle</button>
      </div>
    </div>

    <div class="flex gap-2 text-sm" role="tablist">
      <button v-for="t in TABS" :key="t.value" type="button" role="tab" :aria-selected="tab === t.value"
              :class="['rounded-full px-3 py-1 ring-1', tab === t.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']" @click="tab = t.value">
        {{ t.label }}
      </button>
    </div>

    <p v-if="!catalog.loaded" class="text-sm text-gray-500">Chargement…</p>

    <template v-else-if="tab === 'roles'">
      <section v-for="group in groups" :key="group.title" class="space-y-2">
        <h2 class="font-semibold">{{ group.title }}</h2>
        <p v-if="!group.roles.length" class="text-sm text-gray-500">{{ group.empty }}</p>
        <ul class="grid md:grid-cols-2 gap-3">
          <li v-for="r in group.roles" :key="r.value" class="card p-4 space-y-2">
            <div class="flex items-start justify-between gap-2">
              <div>
                <p class="font-semibold">{{ r.label }}</p>
                <p class="text-sm text-gray-600">{{ r.description || '—' }}</p>
              </div>
              <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ r.users_count }} compte(s)</span>
            </div>
            <p class="text-xs text-gray-500">
              {{ r.value === 'admin' ? 'Tous les droits' : `${r.permissions.length} droit(s)` }}
              <template v-if="scopeText(r)"> · {{ scopeText(r) }}</template>
            </p>
            <div v-if="r.value !== 'admin'" class="flex flex-wrap gap-1">
              <span v-for="d in domainCounts(r)" :key="d.value" class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-800">{{ d.label }} {{ d.count }}</span>
            </div>
            <div class="flex flex-wrap gap-3 text-sm">
              <button v-if="r.editable" type="button" class="text-blue-600" @click="edit(r)">Modifier</button>
              <button v-if="canManage && !r.custom && r.value !== 'admin' && r.value !== 'courier'" type="button" class="text-blue-600" @click="edit(null, `role:${r.value}`)">Créer un rôle à partir de celui-ci</button>
              <RouterLink :to="{ path: '/admin/utilisateurs', query: { role: r.value } }" class="text-gray-600">Voir les comptes</RouterLink>
            </div>
          </li>
        </ul>
      </section>
    </template>

    <PermissionMatrix v-else />

    <RoleEditor :open="editor.open" :role="editor.role" :from="editor.from" @close="editor.open = false" @saved="saved" />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import RoleEditor from '../../components/access/RoleEditor.vue'
import PermissionMatrix from '../../components/access/PermissionMatrix.vue'
import { useAccessCatalog } from '../../composables/useAccessCatalog'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

/**
 * Rôles de l'équipe : intégrés (modèles fixes) et sur mesure, tableau « qui peut faire quoi ».
 */
const auth = useAuthStore()
const toasts = useToastStore()
const { catalog, load, permissionsByDomain } = useAccessCatalog()
const TABS = [{ value: 'roles', label: 'Rôles' }, { value: 'matrix', label: 'Qui peut faire quoi' }]
const tab = ref('roles')
const editor = reactive({ open: false, role: null, from: '' })
const canManage = computed(() => auth.can('users.manage'))

const groups = computed(() => [
  { title: 'Rôles sur mesure', empty: 'Aucun pour l\'instant : « + Nouveau rôle », ou partez d\'un rôle intégré.', roles: catalog.roles.filter((r) => r.custom) },
  { title: 'Rôles intégrés', empty: '', roles: catalog.roles.filter((r) => !r.custom) },
])

const domainCounts = (r) => permissionsByDomain.value
  .map((d) => ({ ...d, count: d.permissions.filter((p) => r.permissions.includes(p.value)).length }))
  .filter((d) => d.count)

function scopeText(r) {
  const parts = []
  if (r.activities?.length) parts.push(r.activities.map((a) => catalog.activities.find((x) => x.value === a)?.label || a).join(', '))
  if (r.zone_ids?.length) parts.push(`${r.zone_ids.length} zone(s)`)
  if (r.hub_ids?.length) parts.push(`${r.hub_ids.length} dépôt(s)`)
  return parts.join(' · ')
}

function edit(role, from = '') {
  Object.assign(editor, { open: true, role, from })
}

async function saved(role) {
  editor.open = false
  toasts.success(role ? `Rôle « ${role.label} » enregistré.` : 'Rôle supprimé.')
  await load()
}

onMounted(load)
</script>
