<template>
  <section class="card p-4">
    <h2 class="font-semibold">À décider</h2>
    <p class="text-xs text-slate-600">{{ decisions.length ? `${decisions.length} point(s), du plus grave au moins grave` : 'Rien à signaler sur cette période.' }}</p>
    <ul class="mt-3 space-y-2">
      <li v-for="(d, i) in decisions" :key="i" :class="['flex flex-wrap items-start gap-3 rounded-lg border px-3 py-3', LEVELS[d.level].box]">
        <span :class="['mt-0.5 inline-flex h-6 w-6 flex-none items-center justify-center rounded-full text-xs font-bold text-white', LEVELS[d.level].dot]" aria-hidden="true">{{ LEVELS[d.level].sign }}</span>
        <div class="min-w-0 flex-1">
          <p :class="['text-xs font-bold uppercase tracking-wide', LEVELS[d.level].text]">{{ [LEVELS[d.level].label, d.activity_label, d.topic].filter(Boolean).join(' · ') }}</p>
          <p class="font-semibold text-slate-900">{{ d.title }}</p>
          <p class="text-sm text-slate-600">{{ d.body }}</p>
        </div>
        <RouterLink :to="d.href" class="rounded-md border bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-slate-50">{{ d.action }}</RouterLink>
      </li>
    </ul>
  </section>
</template>

<script setup>
defineProps({ decisions: { type: Array, required: true } })

const LEVELS = {
  critical: { label: 'Urgent', sign: '!', box: 'border-red-200 bg-red-50', dot: 'bg-red-700', text: 'text-red-700' },
  warning: { label: 'À traiter', sign: '!', box: 'border-amber-200 bg-amber-50', dot: 'bg-amber-700', text: 'text-amber-800' },
  info: { label: 'À surveiller', sign: 'i', box: 'border-blue-200 bg-blue-50', dot: 'bg-blue-700', text: 'text-blue-800' },
  good: { label: 'Bonne nouvelle', sign: '✓', box: 'border-emerald-200 bg-emerald-50', dot: 'bg-emerald-700', text: 'text-emerald-700' },
}
</script>
