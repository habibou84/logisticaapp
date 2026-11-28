<template>
  <div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold mb-4">Nouvelle course</h1>
    <OrderForm @saved="saved" @saved-again="savedAgain" />
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import OrderForm from '../../components/OrderForm.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'

const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

// Distribution, plusieurs types d'envoi : une course par type
function savedText(order) {
  return order.group?.length > 1
    ? `${order.group.length} courses enregistrées (${order.group.map((o) => `${o.tracking_code} : ${o.shipment_type_label}`).join(', ')}).`
    : `Course ${order.tracking_code} enregistrée.`
}

function saved(order) {
  toasts.success(savedText(order))
  router.push(`${auth.homeRoute}/courses/${order.id}`)
}

// « Enregistrer et nouvelle » : on reste sur le formulaire
function savedAgain(order) {
  toasts.success(`${savedText(order)} Saisissez la suivante.`)
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
</script>
