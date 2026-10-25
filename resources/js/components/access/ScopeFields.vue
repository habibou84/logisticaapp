<template>
  <div class="space-y-3">
    <div>
      <p class="text-sm font-medium">Activités</p>
      <p class="text-xs text-gray-500">Ses courses, clients, factures et alertes. Aucune case : toutes.</p>
      <div class="mt-1 flex flex-wrap gap-4">
        <label v-for="a in catalog.activities" :key="a.value" class="flex items-center gap-2 text-sm">
          <input v-model="model.activities" type="checkbox" :value="a.value"> {{ a.label }}
        </label>
      </div>
    </div>
    <div v-if="catalog.zones.length">
      <p class="text-sm font-medium">Zones</p>
      <p class="text-xs text-gray-500">Les courses ramassées ou livrées dans ces communes (et leurs quartiers). Aucune case : toutes.</p>
      <div class="mt-1 grid grid-cols-2 sm:grid-cols-3 gap-1 max-h-40 overflow-y-auto">
        <label v-for="z in catalog.zones" :key="z.id" class="flex items-center gap-2 text-sm">
          <input v-model="model.zone_ids" type="checkbox" :value="z.id"> {{ z.name }}
        </label>
      </div>
    </div>
    <div v-if="catalog.hubs.length">
      <p class="text-sm font-medium">Dépôts</p>
      <p class="text-xs text-gray-500">Les courses qui passent par ces dépôts. Aucune case : tous.</p>
      <div class="mt-1 flex flex-wrap gap-4">
        <label v-for="h in catalog.hubs" :key="h.id" class="flex items-center gap-2 text-sm">
          <input v-model="model.hub_ids" type="checkbox" :value="h.id"> {{ h.name }}
        </label>
      </div>
    </div>
    <p v-if="hint" class="text-xs text-gray-500">{{ hint }}</p>
  </div>
</template>

<script setup>
import { useAccessCatalog } from '../../composables/useAccessCatalog'

/**
 * Périmètre d'un rôle ou d'un compte : activités, zones (communes) et dépôts. Zones et dépôts se cumulent : une
 * course dans l'une de ses zones ou passée par l'un de ses dépôts.
 */
const model = defineModel({ type: Object, required: true })
defineProps({ hint: { type: String, default: '' } })
const { catalog } = useAccessCatalog()
</script>
