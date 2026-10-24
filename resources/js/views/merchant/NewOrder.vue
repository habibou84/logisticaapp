<template>
  <!-- Course envoyée : c'est fait, le n° de suivi, et l'action suivante la plus probable -->
  <div v-if="created" class="mx-auto max-w-md space-y-6 pt-2 text-center">
    <span class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-emerald-100 text-emerald-700 ring-8 ring-emerald-50" aria-hidden="true"><StatusIcon name="check" class="h-10 w-10" /></span>
    <div>
      <p class="text-2xl font-bold">Course envoyée</p>
      <p class="mt-1 text-slate-600">{{ created.from_warehouse ? 'L\'agence prépare votre commande à l\'entrepôt.' : 'Nous allons envoyer un livreur récupérer le colis.' }}</p>
      <p v-if="created.delivery?.scheduled_date && created.delivery.scheduled_date > today()" class="mt-1 text-sm text-slate-600">Elle est dans « Prévues plus tard » et passera dans « Aujourd'hui » le jour de la livraison.</p>
    </div>
    <div class="m-card divide-y divide-slate-100 text-left">
      <div class="flex items-center justify-between gap-3 p-4"><span class="text-slate-600">N° de suivi</span><strong class="font-mono tracking-wider">{{ created.tracking_code }}</strong></div>
      <div class="flex items-center justify-between gap-3 p-4">
        <span class="text-slate-600">Code de livraison<span class="block text-xs">à donner au destinataire</span></span>
        <strong class="font-mono text-xl tracking-[0.25em]">{{ created.delivery_code }}</strong>
      </div>
      <div v-if="created.delivery?.scheduled_date" class="flex items-center justify-between gap-3 p-4"><span class="text-slate-600">Livraison</span><strong class="first-letter:uppercase">{{ longDay(created.delivery.scheduled_date) }}</strong></div>
      <div v-if="created.amounts?.cod_amount" class="flex items-center justify-between gap-3 p-4"><span class="text-slate-600">À encaisser</span><strong>{{ money(created.amounts.cod_amount) }}</strong></div>
      <div v-if="created.amounts?.total_fees" class="flex items-center justify-between gap-3 p-4"><span class="text-slate-600">Frais de livraison</span><strong>{{ money(created.amounts.total_fees) }}</strong></div>
    </div>
    <div class="space-y-3">
      <button class="m-btn-primary" @click="reset"><StatusIcon name="plus" class="h-5 w-5" />Envoyer une autre course</button>
      <div class="grid grid-cols-2 gap-3">
        <a :href="shareLink" target="_blank" class="m-btn-secondary whitespace-nowrap px-3 py-3.5"><StatusIcon name="share" class="h-4 w-4 shrink-0" />Partager</a>
        <RouterLink :to="`/marchand/courses/${created.id}`" class="m-btn-secondary whitespace-nowrap px-3 py-3.5">Voir la course</RouterLink>
      </div>
    </div>
  </div>

  <form v-else class="space-y-5 pb-60" :style="lifted ? { paddingBottom: '100vh' } : {}" novalidate autocomplete="off" @submit.prevent="submit" @focusin="liftField" @focusout="lifted = false">
    <div v-if="error" class="rounded-xl bg-red-50 text-red-700 px-4 py-3 text-sm" role="alert">{{ error }}</div>
    <p v-if="fromMessage" class="rounded-xl bg-amber-50 text-amber-900 px-4 py-3 text-sm">
      ✨ Course remplie d'après le message : vérifiez les champs avant d'envoyer.
    </p>

    <!-- Client à plusieurs activités : la course en choisit une (facturation à part) -->
    <section v-if="activityChoices.length > 1" class="m-card p-4 space-y-2" aria-labelledby="step-activity">
      <h2 id="step-activity" class="m-step">Pour quelle activité ?</h2>
      <ChoiceChips v-model="form.activity" :options="activityChoices" :columns="activityChoices.length === 2 ? 2 : 1" label="Activité de la course" />
    </section>

    <PasteMessage mobile :start-open="route.query.coller === '1'" @parsed="applyParsed" />

    <!-- 1. Le destinataire -->
    <section class="m-card p-4 space-y-4" aria-labelledby="step-client">
      <h2 id="step-client" class="m-step"><span class="m-step-num">1</span>Le destinataire</h2>
      <div class="relative">
        <label class="m-label" for="phone">Téléphone <span class="text-red-600">*</span></label>
        <input id="phone" v-model="form.recipient_phone" :class="['m-input text-lg', missing.phone ? 'ring-2 ring-red-500' : '']" inputmode="tel" autocomplete="jibiat-off" placeholder="07 00 00 00 00" @input="searchRecipients" @blur="checkDuplicates">
      </div>
      <!-- Client déjà livré : nom et adresse en un toucher -->
      <div v-for="r in suggestions" :key="r.id" class="rounded-xl bg-emerald-50 ring-1 ring-emerald-200 px-3 py-2.5 flex items-center gap-3 text-sm">
        <span class="text-xl">👤</span>
        <span class="min-w-0 flex-1">
          <strong>{{ r.name || 'Destinataire' }}</strong> · {{ localPhone(r.phone) }}
          <span class="block truncate text-slate-600">
            {{ r.deliveries_count ? `Déjà livré ${r.deliveries_count} fois` : 'Déjà enregistré' }}<template v-if="zoneOf(r)"> · {{ zoneOf(r) }}</template>
          </span>
          <span v-if="r.failed_count" class="block text-xs text-red-600">{{ r.failed_count }} livraison(s) échouée(s)</span>
        </span>
        <button type="button" class="tap shrink-0 rounded-lg px-3 py-2 text-xs font-semibold text-white" :style="{ backgroundColor: 'var(--app-color)' }" @click="pickRecipient(r)">Utiliser</button>
      </div>
      <div v-if="duplicates.length" class="rounded-xl bg-amber-50 text-amber-900 px-4 py-3 text-sm">
        ⚠️ Vous avez déjà envoyé {{ duplicates.length > 1 ? `${duplicates.length} courses` : 'une course' }} à ce destinataire ces dernières 24 h :
        <RouterLink v-for="d in duplicates" :key="d.id" :to="`/marchand/courses/${d.id}`" class="underline ml-1">{{ d.tracking_code }} ({{ d.status_label }})</RouterLink>
      </div>
      <div>
        <label class="m-label" for="name">Nom <span class="font-normal text-slate-500">(facultatif)</span></label>
        <input id="name" v-model="form.recipient_name" class="m-input" autocomplete="jibiat-off" placeholder="Ex. : Jean Kouadio">
      </div>
    </section>

    <!-- 2. Où livrer -->
    <section class="m-card p-4 space-y-4" aria-labelledby="step-where">
      <h2 id="step-where" class="m-step"><span class="m-step-num">2</span>Où livrer</h2>
      <div id="zone-field">
        <label class="m-label" for="zone">Commune ou quartier <span class="text-red-600">*</span></label>
        <p v-if="zoneChoices.length > 1" class="text-sm text-slate-600 mb-2">
          D'après le message :
          <button v-for="z in zoneChoices" :key="z.id" type="button" :class="['tap mr-1 mt-1 rounded-full px-3 py-1 text-sm ring-1', form.delivery_zone_id === z.id ? 'text-white ring-transparent' : 'bg-white ring-slate-200']" :style="form.delivery_zone_id === z.id ? { backgroundColor: 'var(--app-color)' } : {}" @click="form.delivery_zone_id = z.id">{{ z.full_name }}</button>
        </p>
        <ZoneSelect id="zone" v-model="form.delivery_zone_id" :zones="zones" mobile :input-class="['m-input', missing.zone ? 'ring-2 ring-red-500' : '']" />
        <p v-if="selectedZone?.is_shipping && !billed" class="mt-2 rounded-xl bg-indigo-50 text-indigo-900 text-sm p-3">
          🚌 <strong>Expédition</strong> : notre livreur dépose le colis à la gare et paie l'envoi
          <template v-if="selectedZone.shipping_fee_estimate">(environ {{ money(selectedZone.shipping_fee_estimate) }})</template>.
          Ces frais vous sont facturés au réel et déduits de votre point.
        </p>
      </div>
      <div>
        <label class="m-label" for="address">Adresse et repère</label>
        <input id="address" autocomplete="jibiat-off" v-model="form.delivery_address" class="m-input" placeholder="Ex. : Siporex, résidence Les Palmiers, face pharmacie">
      </div>
    </section>

    <!-- 3. Distribution, MAD : l'envoi (rien à encaisser, facture du mois) -->
    <section v-if="billed" class="m-card p-4 space-y-4" aria-labelledby="step-shipment">
      <h2 id="step-shipment" class="m-step"><span class="m-step-num">3</span>L'envoi</h2>
      <p class="text-sm text-slate-600">
        <template v-if="plan">{{ plan.lot_name || plan.formula_label }} : rien à encaisser, la course passe sur votre facture du mois.</template>
        <template v-else>Aucune formule {{ activityLabel(form.activity) }} en cours : contactez l'agence.</template>
      </p>
      <!-- Lots de colis en cours : la course en fait partie, ou relève du contrat du mois -->
      <div v-if="lots.length">
        <p class="m-label">Lot</p>
        <ChoiceChips v-model="form.billing_plan_id" :options="lotChoices" :columns="1" label="Lot de la course" />
      </div>
      <div v-if="form.activity === 'distribution'">
        <p class="m-label">Type d'envoi</p>
        <ChoiceChips v-model="form.shipment_type" :options="SHIPMENT_TYPES" :columns="3" label="Type d'envoi" />
      </div>
      <div>
        <label class="m-label" for="weight">Poids <span class="font-normal text-slate-500">(facultatif, kg)</span></label>
        <input id="weight" v-model.number="form.weight_kg" type="number" min="0" step="0.1" inputmode="decimal" class="m-input" placeholder="Ex. : 2,5">
        <p class="text-xs text-slate-500 mt-1">Au-delà de 5 kg, chaque kilogramme en plus est facturé.</p>
      </div>
      <div v-if="plan && !plan.has_forfait">
        <p class="m-label">Délai de livraison</p>
        <ChoiceChips v-model="form.delivery_delay" :options="DELAYS" :columns="3" label="Délai de livraison" />
      </div>
      <p v-else-if="plan" class="text-sm text-slate-600">⏱️ Délai du contrat : <strong>{{ plan.delivery_delay.replace('h', ' h') }}</strong></p>
      <div v-if="form.activity === 'mad' && space.space?.couriers?.length" class="rounded-xl bg-slate-50 p-3 text-sm">
        <p class="font-medium">🛵 Vos livreurs attitrés</p>
        <p class="text-slate-600">{{ space.space.couriers.map((c) => `${c.name} (${c.status_label.toLowerCase()})`).join(', ') }}</p>
      </div>
    </section>

    <!-- 3. L'argent (et les articles du stock) -->
    <section v-else class="m-card p-4 space-y-4" aria-labelledby="step-money">
      <h2 id="step-money" class="m-step"><span class="m-step-num">3</span>L'argent</h2>
      <button v-if="products.length && !showStock" type="button" class="tap w-full rounded-xl ring-1 ring-slate-200 px-4 py-3 flex items-center justify-between text-sm font-semibold active:bg-slate-50" @click="showStock = true">
        <span>📦 Prendre des articles de mon stock</span><span class="text-slate-400">›</span>
      </button>
      <div v-if="products.length && showStock" class="space-y-3">
        <div v-if="stockHubs.length">
          <p class="m-label">D'où part le colis ?</p>
          <ChoiceChips v-model="source" :options="sourceOptions" :columns="1" label="Provenance du colis" />
        </div>
        <div>
          <p class="m-label">Articles de mon stock <span class="font-normal text-slate-400">(facultatif)</span></p>
          <div class="m-card divide-y">
            <div v-for="p in sourceProducts" :key="p.id" class="p-3 flex items-center gap-3">
              <div class="min-w-0 flex-1">
                <p class="font-medium truncate">{{ p.name }}</p>
                <p class="text-xs text-slate-500">{{ money(p.price) }} · {{ availableAt(p) }} dispo</p>
              </div>
              <div class="flex items-center gap-2">
                <button type="button" class="tap h-9 w-9 rounded-full bg-slate-100 text-lg font-bold disabled:opacity-40" :disabled="!picked[p.id]" :aria-label="`Retirer un ${p.name}`" @click="pick(p, -1)">−</button>
                <span class="w-6 text-center font-semibold">{{ picked[p.id] || 0 }}</span>
                <button type="button" class="tap h-9 w-9 rounded-full text-white text-lg font-bold disabled:opacity-40" :style="{ backgroundColor: 'var(--app-color)' }" :disabled="(picked[p.id] || 0) >= availableAt(p)" :aria-label="`Ajouter un ${p.name}`" @click="pick(p, 1)">+</button>
              </div>
            </div>
            <p v-if="!sourceProducts.length" class="p-3 text-sm text-slate-500">Aucun produit disponible {{ source ? 'à cet entrepôt' : 'chez vous' }}.</p>
          </div>
        </div>
      </div>

      <div>
        <label class="m-label" for="amount">Montant à encaisser chez le destinataire</label>
        <div class="relative">
          <input id="amount" autocomplete="jibiat-off" v-model.number="form.items_amount" type="number" min="0" step="50" inputmode="numeric" class="m-input text-2xl font-bold pr-12">
          <span class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 font-semibold">F</span>
        </div>
        <p class="text-xs text-slate-500 mt-1">Prix des articles, sans la livraison. Mettez 0 si le destinataire a déjà payé.</p>
      </div>
      <div id="fee-payer" :class="['rounded-2xl', missing.feePayer ? 'ring-2 ring-red-500 p-2 -mx-2' : '']">
        <p class="m-label">Qui paie la livraison ? <span class="text-red-600">*</span></p>
        <ChoiceChips
          v-model="form.fee_payer"
          :columns="2"
          label="Payeur des frais"
          :options="[
            { value: 'recipient', label: 'Le destinataire', icon: '🧍', description: 'ajoutée au montant' },
            { value: 'merchant', label: 'Moi', icon: '🏪', description: 'déduite de mon paiement' },
          ]"
        />
        <p v-if="missing.feePayer" class="mt-2 text-sm font-medium text-red-600" role="alert">Choisissez qui paie la livraison : le destinataire ou vous.</p>
      </div>
    </section>

    <!-- 4. Quand livrer -->
    <section class="m-card p-4 space-y-4" aria-labelledby="step-when">
      <h2 id="step-when" class="m-step"><span class="m-step-num">4</span><span>Quand livrer <span class="text-red-600">*</span></span></h2>
      <div id="when-field" :class="['rounded-2xl', missing.date ? 'ring-2 ring-red-500 p-2 -mx-2' : '']">
        <ChoiceChips v-model="when" :options="deliveryOptions" :columns="3" label="Jour de livraison" />
        <p v-if="closed" class="mt-2 text-xs text-slate-500">🌙 {{ closedReason }} : le ramassage peut quand même se faire aujourd'hui.</p>
        <input v-if="when === 'other'" id="date" autocomplete="jibiat-off" v-model="form.delivery_scheduled_date" type="date" :min="minDate" class="m-input mt-2" aria-label="Jour de livraison">
        <p v-if="dateSummary" class="mt-2 text-sm font-semibold text-slate-700">{{ dateSummary }}</p>
        <p v-if="missing.date" class="mt-2 text-sm font-medium text-red-600" role="alert">Choisissez quand livrer la course.</p>
      </div>
    </section>

    <!-- Le reste, seulement si besoin -->
    <section class="m-card px-4 py-1">
      <button type="button" class="tap w-full flex items-center justify-between rounded-xl py-3 text-[15px] font-semibold text-slate-800" :aria-expanded="showMore" @click="showMore = !showMore">
        <span>{{ showMore ? '−' : '＋' }} Contenu du colis, message au livreur…</span>
        <span v-if="!showMore && extrasSummary" class="truncate pl-2 text-xs font-normal text-slate-500">{{ extrasSummary }}</span>
      </button>
      <div v-show="showMore" class="space-y-4 pt-1 pb-4">
        <div>
          <label class="m-label" for="desc">Que contient le colis ?</label>
          <input id="desc" autocomplete="jibiat-off" v-model="form.description" class="m-input" placeholder="Ex. : 2 robes, 1 sac">
        </div>
        <div v-if="!billed" class="grid grid-cols-2 gap-2">
          <label :class="['tap flex items-center gap-2 rounded-xl px-4 py-3 ring-1', form.is_express ? 'ring-2 ring-[var(--app-color)]' : 'ring-slate-200 bg-white']">
            <input v-model="form.is_express" type="checkbox" class="h-5 w-5 accent-[var(--app-color)]"> ⚡ Express
          </label>
          <label :class="['tap flex items-center gap-2 rounded-xl px-4 py-3 ring-1', form.is_fragile ? 'ring-2 ring-[var(--app-color)]' : 'ring-slate-200 bg-white']">
            <input v-model="form.is_fragile" type="checkbox" class="h-5 w-5 accent-[var(--app-color)]"> 🥚 Fragile
          </label>
        </div>
        <div>
          <label class="m-label" for="note">Message pour le livreur</label>
          <textarea id="note" autocomplete="jibiat-off" v-model="form.merchant_note" rows="2" class="m-input" placeholder="Ex. : appeler avant d'arriver" />
        </div>
        <div>
          <label class="m-label" for="phone2">Autre numéro du destinataire</label>
          <input id="phone2" autocomplete="jibiat-off" v-model="form.recipient_phone2" class="m-input" inputmode="tel" placeholder="Si le premier ne répond pas">
        </div>
      </div>
    </section>

    <!-- Prix en direct et envoi -->
    <div v-show="!keyboardOpen" class="fixed bottom-16 inset-x-0 z-20 bg-white/95 backdrop-blur border-t border-slate-200 pb-safe shadow-[0_-6px_18px_rgba(15,23,42,0.06)]">
      <div class="mx-auto max-w-lg px-4 pt-3 pb-3 space-y-2">
        <!-- Distribution, MAD : comment la course sera facturée, et le forfait du mois -->
        <div v-if="billed && quote?.billing" class="text-sm space-y-0.5">
          <div class="flex justify-between gap-3">
            <span class="text-slate-500">{{ quote.billing.label }}</span>
            <strong class="whitespace-nowrap">{{ quote.billing.basis === 'forfait' && !quote.billing.amount ? 'forfait' : money(quote.billing.amount) }}</strong>
          </div>
          <div v-if="usage?.included" class="flex justify-between text-slate-500">
            <span>Forfait de {{ usage.month_label.toLowerCase() }}</span>
            <span>{{ usage.used }} / {{ usage.included }} utilisés</span>
          </div>
          <p class="text-slate-500">Rien à encaisser · facture du mois</p>
        </div>
        <div v-else-if="quote && !billed" class="text-sm space-y-0.5">
          <div class="flex justify-between">
            <span class="text-slate-500">Livraison {{ zoneName }} ({{ form.fee_payer === 'merchant' ? 'à ma charge' : form.fee_payer === 'recipient' ? 'payée par le destinataire' : 'qui paie ?' }})</span>
            <strong>{{ money(quote.total) }}</strong>
          </div>
          <div v-if="quote.is_shipping" class="flex justify-between"><span class="text-slate-500">🚌 Expédition (au réel)</span><strong>≈ {{ money(shippingEstimate) }}</strong></div>
          <div class="flex justify-between"><span class="text-slate-500">Le livreur encaisse</span><strong>{{ money(codAmount) }}</strong></div>
          <div :class="['flex justify-between font-semibold', merchantNet < 0 ? 'text-slate-700' : 'text-emerald-700']">
            <span>{{ merchantNet < 0 ? 'À votre charge' : 'Vous recevrez' }}</span><span>{{ quote.is_shipping ? '≈ ' : '' }}{{ money(Math.abs(merchantNet)) }}</span>
          </div>
        </div>
        <p v-else-if="quoteError" class="text-sm text-red-600">{{ quoteError }}</p>
        <p v-else class="text-sm text-slate-500">{{ form.delivery_zone_id ? 'Calcul du prix…' : 'Choisissez la commune pour voir le prix de la livraison.' }}</p>
        <button type="submit" class="m-btn-primary" :disabled="saving">{{ saving ? 'Envoi…' : 'Envoyer la course' }}</button>
      </div>
    </div>
  </form>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import ChoiceChips from '../../components/mobile/ChoiceChips.vue'
import StatusIcon from '../../components/mobile/StatusIcon.vue'
import PasteMessage from '../../components/PasteMessage.vue'
import ZoneSelect from '../../components/ZoneSelect.vue'
import { keyboardOpen } from '../../composables/useKeyboard'
import { longDay, useDeliveryDate } from '../../composables/useDeliveryDate'
import { useAuthStore } from '../../stores/auth'
import { localPhone, money, today } from '../../utils/format'
import { activityLabel } from '../../utils/activities'
import { useClientSpaceStore } from '../../stores/clientSpace'
import { siteOrigin } from '../../native'

const route = useRoute()

const auth = useAuthStore()
const space = useClientSpaceStore()

// Activités du client : l'e-commerce encaisse ; distribution et MAD passent sur la facture du mois
const activityChoices = computed(() => space.activities.map((a) => ({
  value: a.value,
  label: a.value === 'mad' ? 'MAD' : a.label,
  icon: { ecommerce: '🛍️', distribution: '✉️', mad: '🛵' }[a.value],
  description: { ecommerce: 'montant encaissé', distribution: 'plis, colis, chèques', mad: 'mes livreurs attitrés' }[a.value],
})))
const defaultActivity = () => space.current || (space.hasEcommerce ? 'ecommerce' : space.activities[0]?.value) || 'ecommerce'
const SHIPMENT_TYPES = [
  { value: 'pli', label: 'Pli', icon: '✉️', description: '0-500 g' },
  { value: 'colis', label: 'Colis', icon: '📦', description: '0-5 kg' },
  { value: 'cheque', label: 'Chèque', icon: '🧾', description: 'à livrer' },
]
const DELAYS = [
  { value: '24h', label: '24 h' },
  { value: '48h', label: '48 h' },
  { value: '72h', label: '72 h' },
]

const blank = () => ({
  activity: defaultActivity(),
  // Lot de colis (distribution) ; vide : contrat du mois
  billing_plan_id: null,
  shipment_type: null,
  weight_kg: null,
  delivery_delay: null,
  recipient_phone: '',
  recipient_name: '',
  recipient_phone2: '',
  delivery_zone_id: null,
  delivery_address: '',
  delivery_scheduled_date: null,
  items_amount: 0,
  // Aucun choix par défaut : le client doit indiquer qui paie la livraison
  fee_payer: null,
  description: '',
  is_express: false,
  is_fragile: false,
  merchant_note: '',
})

// Un seul écran : l'essentiel visible, le reste derrière « Date, contenu du colis… »
const form = reactive(blank())
const billed = computed(() => form.activity !== 'ecommerce')
// Formule de l'activité choisie (auth : sans réseau ; space : consommation du mois)
const contractPlan = computed(() => (auth.user?.merchant?.billing_plans || []).find((p) => p.activity === form.activity) || null)
// Lots de colis en cours (distribution) ; sans contrat, la course va forcément dans un lot
const lots = computed(() => (form.activity === 'distribution' ? auth.user?.merchant?.lots || [] : []))
const lotChoices = computed(() => [
  ...(contractPlan.value ? [{ value: null, label: contractPlan.value.formula_label, icon: '📄', description: 'contrat du mois' }] : []),
  ...lots.value.map((l) => ({ value: l.id, label: l.lot_name, icon: '📦', description: `${l.lot_quantity} colis` })),
])
watch([lots, contractPlan], () => {
  if (!lots.value.some((l) => l.id === form.billing_plan_id)) {
    form.billing_plan_id = contractPlan.value || !lots.value.length ? null : lots.value[0].id
  }
}, { immediate: true })
const plan = computed(() => lots.value.find((l) => l.id === form.billing_plan_id) || contractPlan.value)
// Consommation du forfait du mois (pas pour un lot)
const usage = computed(() => (form.billing_plan_id ? null : space.planFor(form.activity)))
// Valeurs par défaut de l'envoi selon l'activité
watch([() => form.activity, plan], (current, old) => {
  if (!billed.value) {
    // Retour à l'e-commerce : « qui paie » redevient un choix explicite
    if (old && old[0] !== 'ecommerce') form.fee_payer = null
    Object.assign(form, { shipment_type: null, weight_kg: null, delivery_delay: null })
    return
  }
  form.fee_payer = 'merchant'
  form.items_amount = 0
  form.shipment_type = form.activity === 'distribution' ? (form.shipment_type || 'pli') : null
  form.delivery_delay = plan.value && !plan.value.has_forfait ? (form.delivery_delay || plan.value.delivery_delay) : null
}, { immediate: true })
const showMore = ref(false)
const showStock = ref(false)
// Champs obligatoires oubliés, encadrés en rouge jusqu'à leur saisie
const missing = reactive({ phone: false, zone: false, feePayer: false, date: false })
const zones = ref([])
const suggestions = ref([])
const quote = ref(null)
const quoteError = ref('')
const error = ref('')
const saving = ref(false)
const created = ref(null)
const products = ref([])
const hubs = ref([])
const source = ref(null) // null = chez moi, sinon identifiant de l'entrepôt
const picked = reactive({})

// Jour de livraison : choix obligatoire, « Aujourd'hui » impossible quand l'agence a fermé la journée
const { when, options: deliveryOptions, closed, closedReason, minDate, summary: dateSummary, isChosen: dateChosen, load: loadDeliveryDay, reset: resetDate } = useDeliveryDate(form)

// Entrepôts où le client a du stock disponible
const stockHubs = computed(() => hubs.value.filter((h) => products.value.some((p) => p.levels.some((l) => l.hub_id === h.id && l.available > 0))))
const sourceOptions = computed(() => [
  { value: null, label: 'De chez moi', icon: '🏠', description: 'un livreur passe le récupérer' },
  ...stockHubs.value.map((h) => ({ value: h.id, label: h.name, icon: '🏬', description: 'préparé à l\'entrepôt, sans ramassage' })),
])
const sourceName = computed(() => hubs.value.find((h) => h.id === source.value)?.name)
const sourceZoneId = computed(() => hubs.value.find((h) => h.id === source.value)?.zone_id)

function availableAt(product) {
  return product.levels.find((l) => (l.hub_id ?? null) === source.value)?.available ?? 0
}
const sourceProducts = computed(() => products.value.filter((p) => availableAt(p) > 0 || picked[p.id]))
const pickedItems = computed(() => products.value.filter((p) => picked[p.id] > 0).map((p) => ({ product_id: p.id, name: p.name, quantity: picked[p.id], unit_price: p.price })))

function pick(product, delta) {
  picked[product.id] = Math.max(0, Math.min(availableAt(product), (picked[product.id] || 0) + delta))
  // Le montant à encaisser suit les articles choisis (modifiable ensuite)
  form.items_amount = pickedItems.value.reduce((sum, i) => sum + i.quantity * i.unit_price, 0)
  fillDescription()
}

// « Que contient le colis ? » reprend les articles choisis, sauf si le client y a écrit autre chose
let autoDescription = ''
function fillDescription() {
  if (form.description && form.description !== autoDescription) return
  autoDescription = pickedItems.value.map((i) => `${i.quantity} × ${i.name}`).join(', ')
  form.description = autoDescription
}

watch(source, () => {
  Object.keys(picked).forEach((id) => delete picked[id])
  fillDescription()
  fetchQuote()
})

const selectedZone = computed(() => zones.value.find((z) => z.id === form.delivery_zone_id))
const zoneName = computed(() => selectedZone.value?.full_name || '—')
const zoneOf = (recipient) => zones.value.find((z) => z.id === recipient.zone_id)?.full_name
// Options remplies, rappelées quand la section est repliée
const extrasSummary = computed(() => [
  form.description,
  form.is_express ? 'Express' : null,
  form.is_fragile ? 'Fragile' : null,
  form.merchant_note ? 'message au livreur' : null,
].filter(Boolean).join(' · '))
const shippingEstimate = computed(() => (quote.value?.is_shipping ? quote.value.shipping_fee_estimate || 0 : 0))
// Ce que le client touche (ou paie) pour cette course, frais d'expédition estimés compris
const merchantNet = computed(() => (form.items_amount || 0) - (form.fee_payer === 'merchant' ? (quote.value?.total || 0) : 0) - shippingEstimate.value)
const codAmount = computed(() => (form.items_amount || 0) + (form.fee_payer === 'recipient' && quote.value ? quote.value.total : 0))

const shareLink = computed(() => {
  if (!created.value) return '#'
  const url = `${siteOrigin()}/suivi/${created.value.tracking_code}`
  const text = `Bonjour, votre commande ${auth.user?.merchant?.business_name || ''} est en préparation pour la livraison.\nSuivi : ${url}\nCode de livraison à donner au livreur : ${created.value.delivery_code}`
  const phone = (created.value.recipient.phone || '').replace(/\D/g, '')
  return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`
})

// Tarif recalculé à chaque changement utile
watch(() => [form.delivery_zone_id, form.is_express, form.is_fragile, form.activity, form.billing_plan_id, form.shipment_type, form.delivery_delay, form.weight_kg], fetchQuote)

async function fetchQuote() {
  quote.value = null
  quoteError.value = ''
  if (!form.delivery_zone_id) return
  try {
    const { data } = await http.post('/quotes', {
      activity: form.activity,
      billing_plan_id: form.billing_plan_id || undefined,
      shipment_type: form.shipment_type || undefined,
      delivery_delay: form.delivery_delay || undefined,
      weight_kg: form.weight_kg || undefined,
      delivery_zone_id: form.delivery_zone_id,
      pickup_zone_id: sourceZoneId.value || undefined,
      is_express: form.is_express,
      is_fragile: form.is_fragile,
    })
    quote.value = data.data
  } catch (e) {
    quoteError.value = apiErrorMessage(e)
  }
}

let searchTimer
function searchRecipients() {
  clearTimeout(searchTimer)
  const term = form.recipient_phone.replace(/\D/g, '')
  if (term.length < 4) {
    suggestions.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    // Trois clients au plus : ceux dont le numéro correspond le mieux
    suggestions.value = (await http.get('/recipients', { params: { search: term.slice(-8) } })).data.data.slice(0, 3)
  }, 250)
}

function pickRecipient(r) {
  Object.assign(form, {
    // Numéros affichés comme on les tape (sans l'indicatif)
    recipient_phone: (r.phone || '').replace(/^\+225/, ''),
    recipient_name: r.name || '',
    recipient_phone2: (r.phone2 || '').replace(/^\+225/, ''),
    delivery_zone_id: r.zone_id,
    delivery_address: joinAddress(r.address, r.landmark),
  })
  if (r.phone2) showMore.value = true
  suggestions.value = []
  checkDuplicates()
}

// Adresse et repère réunis en un seul champ
const joinAddress = (...parts) => parts.map((p) => (p || '').trim()).filter(Boolean).join(', ')

// Message collé (WhatsApp, SMS) : champs remplis, à vérifier étape par étape
const fromMessage = ref(false)
const zoneChoices = ref([])
const duplicates = ref([])

function applyParsed(data) {
  const set = (key, value) => { if (value !== undefined && value !== null && value !== '') form[key] = value }
  set('recipient_name', data.recipient_name)
  set('recipient_phone', (data.recipient_phone || '').replace(/^\+225/, ''))
  set('recipient_phone2', (data.recipient_phone2 || '').replace(/^\+225/, ''))
  set('delivery_address', joinAddress(data.delivery_address, data.delivery_landmark))
  if (!billed.value) {
    set('items_amount', data.items_amount)
    set('fee_payer', data.fee_payer)
  }
  set('description', data.description)
  set('merchant_note', data.merchant_note)
  zoneChoices.value = data.zones || []
  // Commune la plus probable choisie, les autres proposées au-dessus du champ
  if (zoneChoices.value.length) form.delivery_zone_id = zoneChoices.value[0].id
  if (data.description || data.merchant_note || data.recipient_phone2) showMore.value = true
  fromMessage.value = true
  checkDuplicates()
}

// Même client ces dernières 24 h : risque de doublon
async function checkDuplicates() {
  duplicates.value = []
  if (form.recipient_phone.replace(/\D/g, '').length < 8) return
  try {
    duplicates.value = (await http.get('/orders/duplicates', { params: { recipient_phone: form.recipient_phone } })).data.data
  } catch {
    // Simple alerte : sans réponse, on n'affiche rien
  }
}

// Champs obligatoires : le premier oublié est montré à l'écran
function validate() {
  missing.phone = form.recipient_phone.replace(/\D/g, '').length < 8
  if (billed.value) form.fee_payer = 'merchant'
  missing.zone = !form.delivery_zone_id
  missing.feePayer = !form.fee_payer
  missing.date = !dateChosen.value
  const first = [
    // Distribution, MAD sans formule en cours : la course ne peut pas être facturée
    [billed.value && !plan.value, 'step-shipment', `Aucune formule ${activityLabel(form.activity)} en cours : contactez l'agence pour l'ouvrir.`],
    [missing.phone, 'phone', 'Indiquez le numéro de téléphone du destinataire.'],
    [missing.zone, 'zone-field', 'Choisissez la commune ou le quartier de livraison.'],
    [source.value && !pickedItems.value.length, 'amount', 'Choisissez les articles à prendre à l\'entrepôt.'],
    [missing.feePayer, 'fee-payer', 'Choisissez qui paie la livraison : le destinataire ou vous.'],
    [missing.date, 'when-field', 'Choisissez quand livrer la course.'],
  ].find(([bad]) => bad)
  if (!first) return true
  error.value = first[2]
  setTimeout(() => document.getElementById(first[1])?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
  return false
}

// Champ touché remonté en haut de l'écran (sous l'en-tête) : le clavier et la liste des communes ont la place en dessous.
// La marge du bas s'agrandit le temps de la saisie pour que même les derniers champs puissent remonter.
const lifted = ref(false)
function liftField(event) {
  const field = event.target
  if (!field.matches('input:not([type=checkbox]):not([type=radio]), textarea, select')) return
  lifted.value = true
  setTimeout(() => {
    const header = document.querySelector('header')?.offsetHeight || 56
    const label = field.id && document.querySelector(`label[for="${field.id}"]`)
    const top = (label || field).getBoundingClientRect().top
    window.scrollTo({ top: window.scrollY + top - header - 12, behavior: 'smooth' })
  }, 250)
}

async function submit() {
  error.value = ''
  suggestions.value = []
  if (!validate()) return
  saving.value = true
  try {
    const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
    if (billed.value) delete payload.items_amount
    if (pickedItems.value.length && !billed.value) payload.items = pickedItems.value.map(({ product_id, quantity, unit_price }) => ({ product_id, quantity, unit_price }))
    if (source.value) payload.pickup_hub_id = source.value
    created.value = (await http.post('/orders', payload)).data.data
    loadStock()
  } catch (e) {
    error.value = apiErrorMessage(e)
    const field = Object.keys(e.response?.data?.errors || {})[0] || ''
    if (field === 'fee_payer') missing.feePayer = true
    if (field === 'recipient_phone') missing.phone = true
    if (field === 'delivery_zone_id') missing.zone = true
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } finally {
    saving.value = false
  }
}

function reset() {
  // Activité gardée d'une course à l'autre
  Object.assign(form, { ...blank(), activity: form.activity, billing_plan_id: form.billing_plan_id })
  Object.keys(missing).forEach((key) => { missing[key] = false })
  showMore.value = false
  showStock.value = false
  created.value = null
  quote.value = null
  source.value = null
  Object.keys(picked).forEach((id) => delete picked[id])
  resetDate()
  fromMessage.value = false
  zoneChoices.value = []
  duplicates.value = []
}

// Champ oublié corrigé : plus de cadre rouge, et le message disparaît quand tout est rempli
watch(() => form.fee_payer, (value) => { if (value) missing.feePayer = false })
watch(() => form.delivery_zone_id, (value) => { if (value) missing.zone = false })
watch(() => form.recipient_phone, (value) => { if (value.replace(/\D/g, '').length >= 8) missing.phone = false })
watch(() => form.delivery_scheduled_date, (value) => { if (value) missing.date = false })
watch(missing, (value) => { if (!Object.values(value).some(Boolean)) error.value = '' })

async function loadStock() {
  const [p, h] = await Promise.all([
    http.get('/products', { params: { per_page: 200 } }).catch(() => null),
    http.get('/hubs').catch(() => null),
  ])
  products.value = p?.data.data || []
  hubs.value = h?.data.data || []
}

onMounted(async () => {
  loadDeliveryDay()
  space.load()
  zones.value = (await http.get('/zones')).data.data
  loadStock()
})
</script>
