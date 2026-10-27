<template>
  <div class="space-y-3">
    <ActivitySwitch billed />

    <!-- Reste à payer, toutes factures émises confondues -->
    <div v-if="toPay" :class="['relative overflow-hidden rounded-[24px] p-5 text-white', overdue ? 'bg-gradient-to-br from-[#F0587A] to-[#C02A4E]' : 'bg-brand-grad']">
      <span class="pointer-events-none absolute -right-10 -top-12 h-40 w-40 rounded-full border-[24px] border-white/10" aria-hidden="true" />
      <p class="text-sm opacity-85">{{ overdue ? 'Dont en retard : ' + money(overdue) : 'Reste à payer' }}</p>
      <p class="text-3xl font-bold mt-0.5">{{ money(toPay) }}</p>
      <p class="mt-1 text-sm opacity-90">Touchez une facture pour voir comment la régler.</p>
    </div>

    <!-- Suppléments du mois en cours : ils iront sur la prochaine facture -->
    <section v-if="supplements.length" class="m-card p-4 space-y-2">
      <div class="flex justify-between items-baseline">
        <h2 class="font-semibold">Suppléments de {{ supplementsMonth.toLowerCase() }}</h2>
        <strong>{{ money(supplementsTotal) }}</strong>
      </div>
      <p class="text-xs text-slate-500">Demandes exceptionnelles, ajoutées à part sur votre prochaine facture.</p>
      <div v-for="s in supplements" :key="s.id" class="flex justify-between gap-3 text-sm border-t pt-2">
        <span class="min-w-0">
          <span class="block">{{ s.label }}</span>
          <span class="text-xs text-slate-500">{{ date(s.performed_on) }} · {{ s.activity_label }}<template v-if="s.quantity > 1"> · {{ s.quantity }} × {{ money(s.unit_price) }}</template><template v-if="s.order"> · {{ s.order.tracking_code }}</template></span>
        </span>
        <span class="whitespace-nowrap font-medium">{{ money(s.amount) }}</span>
      </div>
    </section>

    <p class="px-1 text-sm text-slate-500">Vos courses de distribution et de MAD sont facturées chaque mois, une facture par activité. Touchez une facture pour la voir ou l'enregistrer en PDF.</p>
    <RouterLink v-for="i in shown" :key="i.id" :to="`/marchand/factures/${i.id}`" class="tap m-card p-4 flex justify-between items-center gap-3 active:scale-[.99] transition">
      <ColorIcon name="facture" :size="44" />
      <span class="min-w-0 flex-1">
        <span class="font-medium">{{ i.period_label }}</span> <span class="text-sm text-slate-500">· {{ i.activity_label }}<template v-if="i.department"> · {{ i.department.label }}</template></span>
        <span class="block text-sm text-slate-500">{{ i.number }} · {{ money(i.total) }}</span>
        <span v-if="i.status === 'issued' && i.due_at" :class="['block text-xs', i.is_overdue ? 'text-red-600 font-medium' : 'text-slate-500']">
          {{ i.is_overdue ? 'Était à payer le' : 'À payer avant le' }} {{ date(i.due_at) }}
        </span>
      </span>
      <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-medium', i.status === 'paid' ? 'bg-emerald-100 text-emerald-800' : i.status === 'void' ? 'bg-slate-100 text-slate-600' : i.is_overdue ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800']">
        {{ i.status === 'issued' ? `Reste ${money(i.balance)}` : i.status_label }}
      </span>
    </RouterLink>
    <EmptyState v-if="loaded && !shown.length" color="facture" title="Aucune facture pour le moment" text="Vos factures du mois arriveront ici." />
  </div>
</template>

<script setup>
import ColorIcon from '../../components/mobile/ColorIcon.vue'
import EmptyState from '../../components/mobile/EmptyState.vue'
import { computed, onMounted, ref } from 'vue'
import http from '../../bootstrap/axios'
import ActivitySwitch from '../../components/client/ActivitySwitch.vue'
import { useClientSpaceStore } from '../../stores/clientSpace'
import { date, money } from '../../utils/format'

const space = useClientSpaceStore()
const invoices = ref([])
const allSupplements = ref([])
const supplementsMonth = ref('')
// Suppléments du mois pas encore facturés, de l'activité choisie
const supplements = computed(() => allSupplements.value.filter((x) => !x.locked && (!space.current || space.current === 'ecommerce' || x.activity === space.current)))
const supplementsTotal = computed(() => supplements.value.reduce((sum, x) => sum + x.amount, 0))
const loaded = ref(false)
// Activité choisie en haut (l'e-commerce n'a pas de facture du mois : toutes)
const shown = computed(() => invoices.value.filter((i) => !space.current || space.current === 'ecommerce' || i.activity === space.current))
const unpaid = computed(() => shown.value.filter((i) => i.status === 'issued'))
const toPay = computed(() => unpaid.value.reduce((sum, i) => sum + i.balance, 0))
const overdue = computed(() => unpaid.value.filter((i) => i.is_overdue).reduce((sum, i) => sum + i.balance, 0))

onMounted(async () => {
  const [i, s] = await Promise.all([http.get('/client-invoices'), http.get('/client-supplements')])
  invoices.value = i.data.data
  allSupplements.value = s.data.data
  supplementsMonth.value = s.data.month_label
  loaded.value = true
})
</script>
