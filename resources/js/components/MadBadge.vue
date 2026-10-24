<template>
  <!-- Livreur en mise à disposition (MAD) chez un client aujourd'hui -->
  <span
    v-if="mad"
    :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', mad.reserved_now && mad.exclusive ? 'bg-violet-100 text-violet-800' : 'bg-violet-50 text-violet-700']"
    :title="madTitle(mad)"
  >MAD · {{ mad.merchant }}</span>
</template>

<script setup>
import { madTitle } from '../utils/mad'

defineProps({ mad: { type: Object, default: null } })
</script>
