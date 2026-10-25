<template>
  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-left text-gray-600">
        <tr>
          <th class="p-2 sticky left-0 bg-slate-50 min-w-56">Droit</th>
          <th v-for="r in roles" :key="r.value" class="p-2 text-center align-bottom">
            <span class="block text-xs font-semibold text-gray-800 whitespace-nowrap">{{ r.label }}</span>
            <span v-if="r.custom" class="text-[10px] text-indigo-700">sur mesure</span>
          </th>
        </tr>
      </thead>
      <tbody>
        <template v-for="d in permissionsByDomain" :key="d.value">
          <tr class="bg-slate-100"><td :colspan="roles.length + 1" class="p-2 text-xs font-semibold uppercase tracking-wide text-slate-600">{{ d.label }}</td></tr>
          <tr v-for="p in d.permissions" :key="p.value" class="border-t">
            <td class="p-2 sticky left-0 bg-white" :title="p.description">{{ p.label }}</td>
            <td v-for="r in roles" :key="r.value" class="p-2 text-center">
              <span v-if="r.permissions.includes(p.value)" class="text-emerald-700" aria-label="oui">✓</span>
              <span v-else class="text-slate-300" aria-label="non">·</span>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useAccessCatalog } from '../../composables/useAccessCatalog'

/**
 * Tableau « qui peut faire quoi » : les droits (par domaine) × les rôles de l'équipe.
 */
const { catalog, permissionsByDomain } = useAccessCatalog()
const roles = computed(() => catalog.roles.filter((r) => r.value !== 'courier'))
</script>
