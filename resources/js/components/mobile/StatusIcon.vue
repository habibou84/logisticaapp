<template>
  <!-- Petite icône d'état, au trait, de la couleur du texte qui l'entoure -->
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path v-for="(d, i) in ICONS[name] || ICONS.package" :key="i" :d="d" />
  </svg>
</template>

<script setup>
defineProps({ name: { type: String, required: true } })

// Tracés 24×24 (cercles et rectangles écrits en chemins)
const circle = (cx, cy, r) => `M${cx - r} ${cy}a${r} ${r} 0 1 0 ${2 * r} 0a${r} ${r} 0 1 0 ${-2 * r} 0`
const ICONS = {
  package: ['M11 21.7a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7z', 'M12 22V12M3.3 7 12 12l8.7-5'],
  clock: [circle(12, 12, 9), 'M12 7v5l3 2'],
  clipboard: ['M9 2h6v4H9z', 'M15 4h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3', 'm9 14 2 2 4-4'],
  store: ['M3 9l1.5-5h15L21 9z', 'M5 9v12h14V9', 'M10 21v-5h4v5'],
  moto: [circle(5, 17, 3.5), circle(19, 17, 3.5), 'M8.5 17h5.5l3-7', 'M14 5h3l2.2 8.5', 'M3 11h7l2.5 3.5'],
  check: ['M20 6 9 17l-5-5'],
  alert: [circle(12, 12, 9), 'M12 8v5M12 16.5h.01'],
  calendar: ['M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z', 'M16 2v4M8 2v4M3 10h18'],
  back: ['M9 14 4 9l5-5', 'M4 9h10.5a5.5 5.5 0 0 1 0 11H11'],
  x: ['M18 6 6 18M6 6l12 12'],
  ban: [circle(12, 12, 9), 'm5.6 5.6 12.8 12.8'],
  search: [circle(11, 11, 7), 'm20 20-3.5-3.5'],
}
</script>
