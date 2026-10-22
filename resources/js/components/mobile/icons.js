import { h } from 'vue'

// Icônes de la barre d'onglets (traits SVG, héritent de la couleur du texte)
const icon = (paths) => (props) => h('svg', { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', ...props },
  paths.map((d) => h('path', { d })))

export const HomeIcon = icon(['M3 11l9-7 9 7', 'M5 10v10h14V10', 'M10 20v-6h4v6'])
export const BoxIcon = icon(['M12 3l8 4.5v9L12 21l-8-4.5v-9z', 'M4 7.5l8 4.5 8-4.5', 'M12 12v9'])
export const WalletIcon = icon(['M3 7h16a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2z', 'M3 7l13-4v4', 'M16 13.5h.01'])
export const UserIcon = icon(['M12 12a4 4 0 100-8 4 4 0 000 8z', 'M4 21a8 8 0 0116 0'])
export const RouteIcon = icon(['M6 19a2 2 0 100-4 2 2 0 000 4z', 'M18 9a2 2 0 100-4 2 2 0 000 4z', 'M8 17h7a3 3 0 000-6H9a3 3 0 010-6h7'])
