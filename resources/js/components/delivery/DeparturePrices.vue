<template>
  <!-- Fenêtre « Départ : Adjamé » : paliers de la commune, puis un palier par destination (communes, quartiers à
       déplier) ; départ depuis toute la commune ou depuis un de ses quartiers -->
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex justify-end bg-black/40" @click.self="$emit('close')">
      <div class="flex h-full w-full max-w-3xl flex-col bg-slate-50 shadow-xl" role="dialog" aria-modal="true" :aria-label="`Prix au départ de ${commune.name}`">
        <header class="space-y-3 border-b bg-white px-4 pb-3 pt-4">
          <div class="flex items-center gap-3">
            <h2 class="flex-1 text-lg font-semibold">Départ : {{ commune.name }}</h2>
            <button ref="closeButton" type="button" class="btn-secondary" @click="$emit('close')">Fermer</button>
          </div>
          <div class="flex gap-2 overflow-x-auto pb-1" role="group" aria-label="Départ">
            <button
              v-for="d in departures" :key="d.id" type="button" :aria-pressed="d.id === depart.id"
              :class="['shrink-0 whitespace-nowrap rounded-full border px-3 py-1.5 text-sm font-medium', d.id === depart.id ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-emerald-700']"
              @click="depart = d"
            >
              {{ d.id === commune.id ? 'Toute la commune' : d.name }}<span v-if="d.id !== commune.id && hasOwn(d)" class="ml-1.5 inline-block h-1.5 w-1.5 rounded-full align-middle" :class="d.id === depart.id ? 'bg-white' : 'bg-emerald-700'" title="Prix à part" />
            </button>
          </div>
        </header>

        <div class="flex-1 space-y-4 overflow-y-auto px-4 pb-6 pt-4">
          <!-- Paliers de la commune (valables aussi pour ses quartiers) -->
          <section class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="flex-1 font-semibold">Paliers {{ /^[aeiouyéèêâîôûh]/i.test(commune.name) ? 'd’' : 'de ' }}{{ commune.name }}</h3>
              <label class="sr-only" for="dp-copy">Copier les prix d'une autre commune</label>
              <select id="dp-copy" class="input w-auto py-1.5 text-sm" :value="''" @change="copyFrom($event.target.value); $event.target.value = ''">
                <option value="">Copier depuis…</option>
                <option v-for="c in others" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <label v-for="(v, i) in tierList" :key="i" :class="['space-y-1 rounded-lg p-2.5', TIERS[i].box]">
                <span class="block text-xs font-bold uppercase tracking-wide">{{ TIERS[i].name }}</span>
                <span class="flex items-center gap-1">
                  <input :value="v" inputmode="numeric" class="input w-full bg-white py-1.5 text-right text-base font-semibold tabular-nums" :aria-label="`Palier ${TIERS[i].name}`" @change="setTier(i, $event.target.value)">
                  <span class="text-sm">F</span>
                </span>
              </label>
            </div>
            <p class="text-xs text-slate-600">Changer un palier change tous les trajets qui l'utilisent.</p>
          </section>

          <p class="text-sm text-slate-600">
            <template v-if="depart.id === commune.id">Touchez le palier de chaque destination depuis {{ commune.name }}. Un prix vaut dans les deux sens.</template>
            <template v-else>Depuis {{ depart.name }} seulement : touchez ce qui change, le reste suit {{ commune.name }}.</template>
          </p>

          <!-- Destinations : la commune, ses quartiers à déplier -->
          <div class="divide-y overflow-hidden rounded-xl border bg-white">
            <template v-for="c in destinations" :key="c.id">
              <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5">
                <div class="min-w-32 flex-1">
                  <p class="font-semibold">{{ c.name }} <span v-if="c.is_shipping" class="ml-1 rounded-full bg-indigo-100 px-1.5 text-xs font-medium text-indigo-800"><Icone nom="expedition" taille="petite" class="inline align-text-bottom" /> expédition</span></p>
                  <button v-if="childrenOf(c).length" type="button" class="text-xs font-medium text-emerald-700 hover:underline" @click="toggle(c)">
                    {{ opened.has(c.id) ? '▾' : '▸' }} {{ childrenOf(c).length }} quartier{{ childrenOf(c).length > 1 ? 's' : '' }}<template v-if="diffCount(c)"> · {{ diffCount(c) }} à part</template>
                  </button>
                </div>
                <TierPicker :to="c" />
              </div>
              <template v-if="opened.has(c.id)">
                <div v-for="q in childrenOf(c)" :key="q.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 bg-slate-50 py-2 pl-7 pr-3">
                  <p class="min-w-32 flex-1 text-sm">↳ {{ q.name }}</p>
                  <TierPicker :to="q" small />
                </div>
              </template>
            </template>
          </div>
        </div>

        <footer class="flex flex-wrap items-center gap-3 border-t bg-white px-4 py-3">
          <span class="flex-1 text-sm" :class="changes ? 'font-semibold text-amber-800' : 'text-slate-600'">
            {{ changes ? `${changes} modification${changes > 1 ? 's' : ''} pas encore enregistrée${changes > 1 ? 's' : ''}` : 'Sans palier : le prix indiqué en gris.' }}
          </span>
          <button v-if="changes" type="button" class="btn-secondary" :disabled="saving" @click="$emit('cancel')">Annuler</button>
          <button type="button" class="btn-primary" :disabled="!changes || saving" @click="$emit('save')">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import Icone from '../ui/Icone.vue'
import { computed, defineComponent, h, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { resolveRoute, routeKey } from '../../utils/routePrices'

/**
 * Prix au départ d'une commune (ou d'un de ses quartiers) vers toutes les destinations, par paliers : on fixe trois
 * ou quatre montants pour la commune, puis on touche le palier de chaque destination. « Autre » garde un montant
 * libre. Les trajets sont ceux de la grille (départ → arrivée, valables dans les deux sens sans prix inverse).
 */
const props = defineProps({
  commune: { type: Object, required: true },
  zones: { type: Array, required: true },
  prices: { type: Object, required: true },
  base: { type: Number, default: null },
  isDefault: { type: Boolean, default: true },
  tiers: { type: Array, required: true },
  changes: { type: Number, default: 0 },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'set', 'tiers', 'save', 'cancel'])

const TIERS = [
  { name: 'Proche', box: 'bg-emerald-50 text-emerald-900', on: 'bg-emerald-100 text-emerald-900 ring-emerald-600' },
  { name: 'Moyen', box: 'bg-sky-50 text-sky-900', on: 'bg-sky-100 text-sky-900 ring-sky-600' },
  { name: 'Loin', box: 'bg-amber-50 text-amber-900', on: 'bg-amber-100 text-amber-900 ring-amber-600' },
  { name: 'Très loin', box: 'bg-rose-50 text-rose-900', on: 'bg-rose-100 text-rose-900 ring-rose-600' },
]

const byId = computed(() => Object.fromEntries(props.zones.map((z) => [z.id, z])))
const places = computed(() => props.zones.filter((z) => !z.is_shipping))
const childrenOf = (z) => places.value.filter((q) => q.parent_id === z.id)
const departures = computed(() => [props.commune, ...childrenOf(props.commune)])
const depart = ref(props.commune)
// Destinations : les communes où l'on livre (celle du départ en tête), puis les autres villes livrées directement
const destinations = computed(() => {
  const list = places.value.filter((z) => !z.parent_id && z.is_active)
  // Puis les zones d'expédition (gare, transporteur) : prix de la course jusqu'à la gare
  return [props.commune, ...list.filter((z) => z.id !== props.commune.id), ...props.zones.filter((z) => z.is_shipping && z.is_active)]
})
const others = computed(() => places.value.filter((z) => !z.parent_id && z.is_active && z.id !== props.commune.id))
const tierList = computed(() => props.tiers)
const opened = ref(new Set())
function toggle(c) {
  const next = new Set(opened.value)
  next.has(c.id) ? next.delete(c.id) : next.add(c.id)
  opened.value = next
}

// Le prix de base ne vaut pas pour une expédition
const resolve = (from, to) => resolveRoute(from, to, { byId: byId.value, prices: props.prices, own: {}, base: to.is_shipping ? null : props.base })
const mine = (from, to) => props.prices[routeKey(from.id, to.id)]
const hasOwn = (d) => Object.keys(props.prices).some((k) => k.startsWith(`${d.id}-`))
const diffCount = (c) => childrenOf(c).filter((q) => mine(depart.value, q) !== undefined).length
const money = (n) => `${Number(n).toLocaleString('fr-FR')} F`
const name = (id) => byId.value[id]?.name ?? '?'
// D'où vient le prix d'une destination sans prix propre depuis ce départ
function hint(r, to) {
  if (r.kind === 'route' && !r.reverse && r.origin === depart.value.id && r.destination === to.id) return ''
  if (r.kind === 'route' && r.reverse && r.origin === to.id && r.destination === depart.value.id) return 'sens inverse'
  if (r.kind === 'route') return `comme ${name(r.reverse ? r.destination : r.origin)} → ${name(r.reverse ? r.origin : r.destination)}`
  if (r.kind === 'base') return `prix de base ${money(r.price)}`
  return props.isDefault ? 'sans prix' : 'grille normale'
}

function pick(to, value) {
  const current = mine(depart.value, to)
  emit('set', depart.value.id, to.id, current === value ? null : value)
}
const editing = ref(null)
function other(to, raw) {
  editing.value = null
  const v = parse(raw)
  if (v !== undefined) emit('set', depart.value.id, to.id, v)
}
function parse(raw) {
  if (raw === '' || raw === null) return null
  const n = Number(String(raw).replace(/\s/g, ''))
  return Number.isNaN(n) || n < 0 ? undefined : Math.round(n)
}

// Un palier change : tous les trajets de la commune (et de ses quartiers) à l'ancien montant suivent
function setTier(i, raw) {
  const v = parse(raw)
  if (!v) return
  const old = props.tiers[i]
  const from = new Set(departures.value.map((d) => d.id))
  for (const [k, price] of Object.entries(props.prices)) {
    const [o, d] = k.split('-').map(Number)
    if (price === old && (from.has(o) || from.has(d))) emit('set', o, d, v)
  }
  const next = [...props.tiers]
  next[i] = v
  emit('tiers', next)
}

// Copier : les prix d'une autre commune vers chaque commune deviennent ceux de ce départ
function copyFrom(id) {
  const source = byId.value[Number(id)]
  if (!source) return
  for (const to of destinations.value) {
    const target = to.id === props.commune.id ? source : to.id === source.id ? props.commune : to
    const r = resolve(source, target)
    if (r.kind === 'route') emit('set', depart.value.id, to.id, r.price)
  }
}

// Rangée de paliers d'une destination, avec « Autre » pour un montant libre
const TierPicker = defineComponent({
  props: { to: { type: Object, required: true }, small: Boolean },
  setup(p) {
    return () => {
      const to = p.to
      const r = resolve(depart.value, to)
      const set = mine(depart.value, to)
      const isOther = r.price !== null && r.kind === 'route' && !props.tiers.includes(r.price)
      const size = p.small ? 'px-1.5 py-1 text-xs sm:px-2' : 'px-1.5 py-1.5 text-xs sm:px-2.5 sm:text-sm'
      const buttons = props.tiers.map((v, i) => h('button', {
        type: 'button',
        'aria-pressed': String(r.kind === 'route' && r.price === v),
        'aria-label': `${TIERS[i].name} ${money(v)} vers ${to.name}`,
        class: ['border-l border-slate-200 font-semibold tabular-nums first:border-l-0', size,
          r.kind === 'route' && r.price === v ? `${TIERS[i].on} ${set === undefined ? 'opacity-60' : ''}` : 'bg-white text-slate-600 hover:bg-slate-50'],
        onClick: () => pick(to, v),
      }, money(v)))
      const otherKey = `${depart.value.id}-${to.id}`
      buttons.push(editing.value === otherKey
        ? h('input', {
          class: 'input w-24 rounded-none border-0 border-l py-1 text-right text-sm', inputmode: 'numeric', placeholder: 'Montant',
          'aria-label': `Autre montant vers ${to.name}`,
          onVnodeMounted: (v) => v.el.focus(),
          onChange: (e) => other(to, e.target.value),
          onBlur: () => { editing.value = null },
        })
        : h('button', {
          type: 'button', 'aria-pressed': String(isOther),
          class: ['border-l border-slate-200 font-semibold tabular-nums', size, isOther ? `bg-orange-50 text-orange-800 ${set === undefined ? 'opacity-60' : ''}` : 'bg-white text-slate-600 hover:bg-slate-50'],
          onClick: () => { editing.value = otherKey },
        }, isOther ? money(r.price) : 'Autre'))
      const text = hint(r, to)
      return h('div', { class: 'flex flex-col items-end gap-1' }, [
        h('div', { class: 'inline-flex overflow-hidden rounded-lg border border-slate-300' }, buttons),
        text ? h('span', { class: 'text-xs text-slate-600' }, text) : null,
      ])
    }
  },
})

const closeButton = ref(null)
function onKey(e) { if (e.key === 'Escape') emit('close') }
onMounted(() => { nextTick(() => closeButton.value?.focus()); document.addEventListener('keydown', onKey) })
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>
