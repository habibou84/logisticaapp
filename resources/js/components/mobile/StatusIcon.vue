<template>
  <!-- Icône au trait (états, menus, cartes), de la couleur du texte qui l'entoure -->
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
  // Menus et cartes de l'application client
  file: ['M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z', 'M14 2v4a2 2 0 0 0 2 2h4', 'M16 13H8M16 17H8'],
  wallet: ['M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1', 'M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4'],
  chart: ['M3 3v18h18', 'M18 17V9M13 17V5M8 17v-3'],
  phone: ['M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z'],
  pin: ['M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z', circle(12, 10, 3)],
  box: ['M3 3h18v5H3z', 'M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8', 'M10 12h4'],
  message: ['M7.9 20A9 9 0 1 0 4 16.1L2 22z'],
  upload: ['M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'm17 8-5-5-5 5', 'M12 3v12'],
  plug: ['M12 22v-5', 'M9 8V2M15 8V2', 'M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z'],
  bell: ['M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9', 'M10.3 21a1.94 1.94 0 0 0 3.4 0'],
  help: [circle(12, 12, 9), 'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3', 'M12 17h.01'],
  logout: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'm16 17 5-5-5-5', 'M21 12H9'],
  eye: ['M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z', circle(12, 12, 3)],
  'eye-off': ['M9.9 4.2A9 9 0 0 1 12 4c7 0 10 8 10 8a13 13 0 0 1-1.7 2.7', 'M6.6 6.6A13.5 13.5 0 0 0 2 12s3 8 10 8a9.7 9.7 0 0 0 5.4-1.6', 'M14.1 14.1a3 3 0 1 1-4.2-4.2', 'm2 2 20 20'],
  share: [circle(18, 5, 3), circle(6, 12, 3), circle(18, 19, 3), 'm8.6 13.5 6.8 4M15.4 6.5l-6.8 4'],
  plus: ['M12 5v14M5 12h14'],
  right: ['m9 18 6-6-6-6'],
  down: ['m6 9 6 6 6-6'],
  sliders: ['M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3', 'M2 14h4M10 8h4M18 16h4'],
  user: [circle(12, 8, 4), 'M4 21a8 8 0 0 1 16 0'],
  home: ['M3 11l9-7 9 7', 'M5 10v10h14V10', 'M10 20v-6h4v6'],
  truck: ['M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2', 'M15 18H9', 'M19 18h2a1 1 0 0 0 1-1v-3.7a1 1 0 0 0-.2-.6l-3.5-4.3a1 1 0 0 0-.8-.4H14', circle(17, 18, 2), circle(7, 18, 2)],
}
</script>
