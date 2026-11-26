<template>
  <!-- Compte connecté (repris de Jibiat) : un bouton profil qui ouvre Mon compte et Déconnexion -->
  <div ref="root" class="relative">
    <button
      type="button"
      :class="sidebar
        ? 'flex w-full min-h-11 items-center gap-3 rounded-lg bg-white/10 px-3 py-2 text-left hover:bg-white/20'
        : 'flex min-h-11 items-center gap-2 rounded-full p-1 pr-3 text-slate-700 hover:bg-black/5'"
      aria-haspopup="menu"
      :aria-expanded="open"
      :aria-label="`Compte de ${auth.user?.name || ''}`"
      @click="open = !open"
    >
      <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-full text-sm font-bold', sidebar ? 'bg-white text-slate-900' : 'bg-[var(--admin-color,#0f172a)] text-white']" aria-hidden="true">{{ initials }}</span>
      <span :class="['min-w-0', sidebar ? 'flex-1' : 'hidden lg:block text-left']">
        <span class="block truncate text-sm font-semibold">{{ auth.user?.name }}</span>
        <span :class="['block truncate text-xs', sidebar ? 'text-white/70' : 'text-slate-600']">{{ auth.user?.role_label }}</span>
      </span>
      <span aria-hidden="true" :class="sidebar ? 'text-white/70' : 'text-slate-600'">▾</span>
    </button>

    <div
      v-if="open"
      role="menu"
      :class="['absolute z-50 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-slate-800 shadow-lg', sidebar ? 'bottom-full left-0 mb-2' : 'right-0 top-full mt-2']"
    >
      <div class="border-b border-slate-100 px-4 py-3">
        <p class="truncate font-semibold">{{ auth.user?.name }}</p>
        <p class="truncate text-sm text-slate-600">{{ auth.user?.role_label }}<template v-if="auth.user?.company?.name"> · {{ auth.user.company.name }}</template></p>
      </div>
      <RouterLink to="/admin/mon-compte" role="menuitem" class="flex min-h-11 items-center gap-3 px-4 text-sm hover:bg-slate-50" @click="close">
        <Icone nom="compte" /> Mon compte
      </RouterLink>
      <button type="button" role="menuitem" class="flex min-h-11 w-full items-center gap-3 border-t border-slate-100 px-4 text-left text-sm font-medium text-red-700 hover:bg-red-50" @click="logout">
        <Icone nom="deconnexion" /> Déconnexion
      </button>
    </div>
  </div>
</template>

<script setup>
import Icone from './ui/Icone.vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'

// sidebar : en bas du menu de gauche sur téléphone (le menu s'ouvre vers le haut)
defineProps({ sidebar: Boolean })
const emit = defineEmits(['logout', 'navigate'])
const auth = useAuthStore()
const open = ref(false)
const root = ref(null)

const initials = computed(() => (auth.user?.name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join(''))

function close() {
  open.value = false
  emit('navigate')
}
function logout() {
  open.value = false
  emit('logout')
}
const outside = (e) => { if (open.value && root.value && !root.value.contains(e.target)) open.value = false }
const escape = (e) => { if (e.key === 'Escape') open.value = false }
onMounted(() => { document.addEventListener('click', outside); document.addEventListener('keydown', escape) })
onBeforeUnmount(() => { document.removeEventListener('click', outside); document.removeEventListener('keydown', escape) })
</script>
