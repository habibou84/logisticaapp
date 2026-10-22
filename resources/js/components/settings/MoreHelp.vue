<template>
  <!-- Explication détaillée repliée : l'essentiel reste lisible d'un coup d'œil -->
  <details class="text-xs text-gray-500">
    <summary class="cursor-pointer select-none text-blue-600">En savoir plus</summary>
    <div class="mt-1 space-y-1"><slot /></div>
  </details>
</template>
