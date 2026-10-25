<template>
  <div class="space-y-3">
    <fieldset v-for="d in permissionsByDomain" :key="d.value" class="rounded-lg border border-slate-200 p-3">
      <legend class="px-1 text-sm font-semibold">
        {{ d.label }}
        <span class="font-normal text-gray-500">· {{ d.permissions.filter((p) => has(p.value)).length }}/{{ d.permissions.length }}</span>
      </legend>
      <ul class="space-y-1.5">
        <li v-for="p in d.permissions" :key="p.value" class="flex items-start justify-between gap-2 text-sm">
          <label :class="['flex items-start gap-2', locked(p.value) ? 'opacity-60' : 'cursor-pointer']">
            <input type="checkbox" class="mt-1" :checked="has(p.value)" :disabled="locked(p.value)" @change="toggle(p.value, $event.target.checked)">
            <span>
              {{ p.label }}
              <span class="block text-xs text-gray-500">{{ p.description }}</span>
            </span>
          </label>
          <span v-if="base" class="shrink-0 text-xs">
            <span v-if="has(p.value) && !base.includes(p.value)" class="text-emerald-700">ajoutée</span>
            <span v-else-if="!has(p.value) && base.includes(p.value)" class="text-red-600">retirée</span>
            <span v-else-if="base.includes(p.value)" class="text-gray-400">du rôle</span>
          </span>
        </li>
      </ul>
    </fieldset>
  </div>
</template>

<script setup>
import { useAccessCatalog } from '../../composables/useAccessCatalog'

/**
 * Choix des permissions, rangées par domaine, avec ce que chacune ouvre. « base » : permissions du rôle (réglage fin
 * d'un compte : ajoutée, retirée). Une permission que le compte connecté n'a pas ne peut pas être donnée.
 */
const props = defineProps({
  modelValue: { type: Array, required: true },
  base: { type: Array, default: null },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const { catalog, permissionsByDomain } = useAccessCatalog()

const has = (p) => props.modelValue.includes(p)
// Donner : seulement ce qu'on a soi-même ; retirer reste possible
const locked = (p) => props.disabled || (!has(p) && !catalog.grantable.includes(p))

function toggle(p, checked) {
  emit('update:modelValue', checked ? [...new Set([...props.modelValue, p])] : props.modelValue.filter((x) => x !== p))
}
</script>
