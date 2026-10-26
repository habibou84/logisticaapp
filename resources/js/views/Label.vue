<template>
  <div :class="['label-page p-4', `fmt-${format}`]">
    <div class="no-print mb-4 flex flex-wrap items-center gap-2">
      <button v-if="canGoBack" class="btn-secondary" @click="router.back()">← Retour</button>
      <button class="btn-primary" :disabled="!orders.length" @click="print">🖨️ Imprimer {{ orders.length > 1 ? `les ${orders.length} étiquettes` : 'l\'étiquette' }}</button>
      <label class="text-sm text-gray-600" for="label-format">Format</label>
      <select id="label-format" v-model="format" class="input w-auto">
        <option v-for="(f, key) in FORMATS" :key="key" :value="key">{{ f.label }}</option>
      </select>
      <span class="text-sm text-gray-500">{{ FORMATS[format].hint }}</span>
      <span v-if="loading" class="text-sm text-gray-500">Chargement {{ orders.length }}/{{ ids.length }}…</span>
      <span v-if="missing" class="text-sm text-amber-700">{{ missing }} course(s) introuvable(s) ou hors de votre accès.</span>
    </div>

    <p v-if="!loading && !orders.length" class="text-gray-500">Aucune étiquette à imprimer.</p>
    <!-- Une page imprimée par groupe : quatre étiquettes en A4, une sinon -->
    <div class="sheets">
      <div v-for="(page, i) in pages" :key="i" class="sheet">
        <div v-for="o in page" :key="o.id" class="cell">
          <OrderLabel :order="o" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '../bootstrap/axios'
import OrderLabel from '../components/labels/OrderLabel.vue'

/**
 * Étiquettes d'une ou plusieurs courses (/etiquette/:id, /etiquettes?ids=1,2,3) : rouleau thermique 10 × 15,
 * A6, ou planche A4 de quatre. Le format choisi est gardé sur l'appareil.
 */
const FORMATS = {
  thermal: { label: 'Rouleau 10 × 15 cm', hint: 'Imprimante thermique, une étiquette par page.', page: '10cm 15cm' },
  a6: { label: 'A6', hint: 'Une étiquette par feuille A6.', page: '105mm 148mm' },
  a4: { label: 'A4 · 4 par page', hint: 'Imprimante de bureau, à découper.', page: 'A4' },
}
const KEY = 'label_format'
const MAX = 200

const route = useRoute()
const router = useRouter()
// Ouverte depuis l'application (même onglet) : retour possible
const canGoBack = !!window.history.state?.back
const orders = ref([])
const loading = ref(true)
const missing = ref(0)
const format = ref(read() || 'thermal')

const pages = computed(() => {
  const size = format.value === 'a4' ? 4 : 1
  return Array.from({ length: Math.ceil(orders.value.length / size) }, (_, i) => orders.value.slice(i * size, (i + 1) * size))
})
const ids = ref((route.params.id ? [Number(route.params.id)] : String(route.query.ids || '').split(',').map(Number).filter(Boolean)).slice(0, MAX))

function read() {
  try {
    const v = localStorage.getItem(KEY)
    return FORMATS[v] ? v : null
  } catch {
    return null
  }
}

// Taille de la page à l'impression : une règle @page selon le format
let pageStyle = null
watch(format, (f) => {
  try {
    localStorage.setItem(KEY, f)
  } catch { /* stockage indisponible */ }
  pageStyle ??= document.head.appendChild(document.createElement('style'))
  pageStyle.textContent = `@media print { @page { size: ${FORMATS[f].page}; margin: 0; } }`
}, { immediate: true })
onBeforeUnmount(() => pageStyle?.remove())

function print() {
  window.print()
}

onMounted(async () => {
  // Toutes les courses d'un lot de distribution (?lot=) : hors annulées et refusées
  if (route.query.lot && !ids.value.length) {
    const { data } = await http.get('/orders', { params: { billing_plan_id: route.query.lot, per_page: MAX } }).catch(() => ({ data: { data: [] } }))
    ids.value = data.data.filter((o) => !['cancelled', 'rejected'].includes(o.status)).map((o) => o.id).reverse()
  }
  // Détail de chaque course (expéditeur, département, annuaire, preuve demandée), quelques-unes à la fois
  const found = new Map()
  const queue = [...ids.value]
  const worker = async () => {
    while (queue.length) {
      const id = queue.shift()
      try {
        found.set(id, (await http.get(`/orders/${id}`)).data.data)
      } catch {
        missing.value++
      }
      orders.value = ids.value.map((i) => found.get(i)).filter(Boolean)
    }
  }
  await Promise.all(Array.from({ length: Math.min(6, queue.length) }, worker))
  loading.value = false
})
</script>

<style>
.label { width: 10cm; height: 15cm; overflow: hidden; box-sizing: border-box; }
.label.receipt { font-family: Arial, Helvetica, sans-serif; }
.label .sec { border-top: 1.5px solid #000; padding-top: 4px; }
.label .k { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
.label .tag { display: inline-block; border: 1.5px solid #000; border-radius: 3px; padding: 0 5px; font-size: 10px; font-weight: 700; margin-left: 4px; }
.label .accuse { border: 2px solid #000; border-radius: 4px; padding: 7px 8px 6px; flex: 1; display: flex; flex-direction: column; min-height: 0; }
.label .line { display: flex; gap: 6px; font-size: 12px; margin-top: 9px; align-items: flex-end; }
.label .line span { white-space: nowrap; }
.label .line i { flex: 1; border-bottom: 1px dotted #000; height: 14px; }
.label .boxes { display: flex; gap: 6px; margin-top: 9px; flex: 1; min-height: 2.4cm; }
.label .box { flex: 1; border: 1.5px solid #000; border-radius: 3px; position: relative; }
.label .box span { position: absolute; top: 3px; left: 5px; font-size: 9px; text-transform: uppercase; }
/* A6 et A4 : l'étiquette tient dans 10,5 × 14,85 cm */
.fmt-a6 .label, .fmt-a4 .label { height: 14.4cm; }
.label-page .sheets { display: flex; flex-wrap: wrap; gap: 12px; }
.label-page .sheet { display: contents; }
@media print {
  .no-print { display: none; }
  .label-page { padding: 0 !important; }
  .label-page .sheets { display: block; }
  .label-page .sheet { display: block; break-after: page; page-break-after: always; }
  .label-page .sheet:last-child { break-after: auto; page-break-after: auto; }
  .fmt-a6 .cell { width: 10.5cm; height: 14.8cm; display: flex; align-items: center; justify-content: center; }
  .fmt-a4 .sheet { display: grid; grid-template-columns: 10.5cm 10.5cm; grid-template-rows: 14.85cm 14.85cm; }
  .fmt-a4 .cell { display: flex; align-items: center; justify-content: center; }
}
</style>
