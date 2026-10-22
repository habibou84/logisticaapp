<template>
  <section class="card overflow-x-auto">
    <div class="flex items-center justify-between gap-2 p-4 pb-2">
      <h2 class="font-semibold">{{ title }}</h2>
      <button v-if="exportable && rows.length" class="text-sm text-blue-600" @click="exportCsv">Exporter</button>
    </div>
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-left text-slate-600">
        <tr>
          <th
            v-for="c in columns"
            :key="c.key"
            :class="['p-2 cursor-pointer select-none whitespace-nowrap', c.align === 'right' ? 'text-right' : '']"
            :aria-sort="sort.key === c.key ? (sort.dir === 'desc' ? 'descending' : 'ascending') : 'none'"
            @click="sortBy(c)"
          >
            {{ c.label }}<span v-if="sort.key === c.key">{{ sort.dir === 'desc' ? ' ▾' : ' ▴' }}</span>
          </th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <tr v-for="r in sorted" :key="r.id">
          <td v-for="c in columns" :key="c.key" :class="['p-2', c.align === 'right' ? 'text-right' : '', c.class ? c.class(r) : '']">
            <slot :name="c.key" :row="r">{{ c.format ? c.format(r[c.key], r) : (r[c.key] ?? '—') }}</slot>
          </td>
        </tr>
        <tr v-if="!rows.length"><td :colspan="columns.length" class="p-6 text-center text-slate-500">{{ empty }}</td></tr>
      </tbody>
    </table>
  </section>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { downloadCsv } from './chart'

const props = defineProps({
  title: { type: String, required: true },
  // [{ key, label, align?, format?(valeur, ligne), class?(ligne), asc? (tri croissant d'abord) }]
  columns: { type: Array, required: true },
  rows: { type: Array, required: true },
  initialSort: { type: String, default: '' },
  filename: { type: String, default: 'statistiques.csv' },
  empty: { type: String, default: 'Rien sur la période.' },
  // Export Excel (statistiques avancées)
  exportable: { type: Boolean, default: true },
})

const sort = reactive({ key: props.initialSort, dir: 'desc' })

function sortBy(column) {
  if (sort.key === column.key) sort.dir = sort.dir === 'desc' ? 'asc' : 'desc'
  else Object.assign(sort, { key: column.key, dir: column.asc ? 'asc' : 'desc' })
}

const sorted = computed(() => {
  if (!sort.key) return props.rows
  const factor = sort.dir === 'desc' ? -1 : 1
  return [...props.rows].sort((a, b) => {
    const [x, y] = [a[sort.key], b[sort.key]]
    if (x === y) return 0
    if (x === null || x === undefined) return 1
    if (y === null || y === undefined) return -1
    return (typeof x === 'string' ? x.localeCompare(y, 'fr') : x - y) * factor
  })
})

// Valeurs brutes (nombres) : Excel peut les additionner
function exportCsv() {
  downloadCsv(props.filename, [props.columns.map((c) => c.label), ...sorted.value.map((r) => props.columns.map((c) => r[c.key]))])
}
</script>
