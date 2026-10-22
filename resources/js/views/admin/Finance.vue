<template>
  <div class="space-y-4">
    <h1 class="text-xl font-bold">Caisse</h1>

    <div class="flex gap-2 overflow-x-auto">
      <button
        v-for="t in tabs"
        :key="t.value"
        :class="['btn whitespace-nowrap', tab === t.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']"
        @click="setTab(t.value)"
      >
        {{ t.label }}
      </button>
    </div>

    <!-- Argent chez les livreurs -->
    <template v-if="tab === 'cash'">
      <div v-if="cash" class="grid sm:grid-cols-3 gap-3">
        <StatCard title="Argent chez les livreurs" :value="money(cash.totals.cash_in_hands)" icon="🛵" />
        <StatCard title="Encaissé aujourd'hui" :value="money(cash.totals.collected_today)" icon="💵" />
        <StatCard title="Versé à la caisse aujourd'hui" :value="money(cash.totals.received_today)" icon="🏦" />
      </div>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Livreur</th><th class="p-2 text-right" title="Encaissé + avances − frais payés">À verser</th><th class="p-2">Lignes</th><th class="p-2" title="Colis non livrés encore chez le livreur">Colis</th><th class="p-2">Plus ancien</th><th class="p-2 text-right">Gains non payés</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="c in cash?.data || []" :key="c.courier_id">
              <td class="p-2 font-medium">{{ c.name }}<div class="text-xs text-gray-500">{{ c.phone }}</div></td>
              <td data-label="À verser" class="p-2 text-right font-semibold">
                {{ money(c.cash_in_hand) }}
                <div v-if="c.advances || c.expenses" class="text-xs font-normal text-gray-500">
                  <span v-if="c.advances">+{{ money(c.advances) }} avance</span><span v-if="c.advances && c.expenses"> · </span><span v-if="c.expenses">−{{ money(c.expenses) }} frais</span>
                </div>
              </td>
              <td data-label="Lignes" class="p-2">{{ c.pending_collections }}</td>
              <td data-label="Colis" class="p-2"><span v-if="c.parcels_in_hand" class="rounded-full bg-amber-100 text-amber-900 px-2 py-0.5 text-xs font-medium">📦 {{ c.parcels_in_hand }}</span><span v-else class="text-gray-400">—</span></td>
              <td data-label="Plus ancien" class="p-2 text-xs">{{ c.oldest_collected_at ? dateTime(c.oldest_collected_at) : '—' }}</td>
              <td data-label="Gains non payés" :class="['p-2 text-right', signedClass(c.unpaid)]">{{ money(c.unpaid) }}</td>
              <td class="p-2 text-right whitespace-nowrap space-x-1">
                <button v-if="canManage" class="btn-secondary" @click="openAdvance(c)">Donner une avance</button>
                <button v-if="c.pending_collections && canManage" class="btn-primary" @click="openRemit(c)">{{ c.cash_in_hand >= 0 ? 'Recevoir le versement' : 'Rembourser le livreur' }}</button>
                <button v-else-if="c.parcels_in_hand && canManage" class="btn-primary" @click="openRemit(c)">Recevoir les colis</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card overflow-x-auto">
        <h2 class="font-semibold p-4 border-b">Derniers versements</h2>
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Date</th><th class="p-2">Livreur</th><th class="p-2 text-right">Attendu</th><th class="p-2 text-right">Reçu</th><th class="p-2 text-right">Écart</th><th class="p-2">Colis</th><th class="p-2">Reçu par</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="r in remittances" :key="r.id">
              <td class="p-2 text-xs">{{ dateTime(r.received_at) }}</td>
              <td data-label="Livreur" class="p-2">{{ r.courier_name }}</td>
              <td data-label="Attendu" class="p-2 text-right">{{ money(r.amount_expected) }}</td>
              <td data-label="Reçu" class="p-2 text-right">{{ money(r.amount_received) }}</td>
              <td data-label="Écart" :class="['p-2 text-right font-medium', signedClass(r.difference)]">{{ r.difference ? money(r.difference) : '—' }}</td>
              <td data-label="Colis" class="p-2 text-xs">
                <span v-if="r.parcels_returned.length" class="block text-emerald-700" :title="r.parcels_returned.join(', ')">✓ {{ r.parcels_returned.length }} rendu{{ r.parcels_returned.length > 1 ? 's' : '' }}</span>
                <span v-if="r.parcels_kept.length" class="block text-amber-700" :title="r.parcels_kept.join(', ')">⚠ {{ r.parcels_kept.length }} gardé{{ r.parcels_kept.length > 1 ? 's' : '' }} : {{ r.parcels_kept.join(', ') }}</span>
                <span v-if="!r.parcels_returned.length && !r.parcels_kept.length" class="text-gray-400">—</span>
              </td>
              <td data-label="Reçu par" class="p-2 text-xs">{{ r.received_by }}<span v-if="r.notes" class="block text-gray-500">{{ r.notes }}</span></td>
            </tr>
            <tr v-if="!remittances.length"><td colspan="7" class="p-4 text-center text-gray-500">Aucun versement.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Soldes des marchands -->
    <template v-if="tab === 'merchants'">
      <p class="text-sm text-gray-600">
        « Disponible » = argent arrivé en caisse, prêt à être reversé. « En attente » = courses livrées dont l'argent est encore chez un livreur.
      </p>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">E-commerçant</th><th class="p-2 text-right">Disponible</th><th class="p-2 text-right">En attente</th><th class="p-2 text-right">Total non reversé</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="m in merchantBalances" :key="m.merchant_id">
              <td class="p-2 font-medium">{{ m.business_name }}</td>
              <td data-label="Disponible" :class="['p-2 text-right font-semibold', signedClass(m.available)]">{{ money(m.available) }}</td>
              <td data-label="En attente" class="p-2 text-right text-gray-600">{{ money(m.pending_cash) }}</td>
              <td data-label="Total non reversé" class="p-2 text-right">{{ money(m.unpaid_total) }}</td>
              <td class="p-2 text-right whitespace-nowrap">
                <button class="text-blue-600 mr-3" @click="openLedger(m)">Grand livre</button>
                <button v-if="canManage && m.available !== 0" class="btn-primary" @click="preparePayout(m)">Préparer le reversement</button>
              </td>
            </tr>
            <tr v-if="!merchantBalances.length"><td colspan="5" class="p-4 text-center text-gray-500">Aucun montant en attente.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Reversements -->
    <template v-if="tab === 'payouts'">
      <div class="card overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Référence</th><th class="p-2">E-commerçant</th><th class="p-2">Période</th><th class="p-2 text-right">Net</th><th class="p-2">Statut</th><th class="p-2">Payé le</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="p in payouts" :key="p.id" class="hover:bg-slate-50 cursor-pointer" @click="router.push(`/admin/caisse/reversements/${p.id}`)">
              <td class="p-2 font-mono text-xs">{{ p.reference }}</td>
              <td data-label="E-commerçant" class="p-2">{{ p.merchant.business_name }}</td>
              <td data-label="Période" class="p-2 text-xs">{{ date(p.period_start) }} → {{ date(p.period_end) }}</td>
              <td data-label="Net" :class="['p-2 text-right font-semibold', signedClass(p.net_amount)]">{{ money(p.net_amount) }}</td>
              <td data-label="Statut" class="p-2"><PayoutBadge :status="p.status" :label="p.status_label" /></td>
              <td data-label="Payé le" class="p-2 text-xs">{{ p.paid_at ? `${dateTime(p.paid_at)} · ${p.method_label}` : '—' }}</td>
            </tr>
            <tr v-if="!payouts.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucun reversement.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Paie des livreurs -->
    <template v-if="tab === 'payroll'">
      <div class="card overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Livreur</th><th class="p-2 text-right">Gains non payés</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="c in cash?.data || []" :key="c.courier_id">
              <td class="p-2 font-medium">{{ c.name }}</td>
              <td data-label="Gains non payés" :class="['p-2 text-right font-semibold', signedClass(c.unpaid)]">{{ money(c.unpaid) }}</td>
              <td class="p-2 text-right whitespace-nowrap">
                <button v-if="canManage" class="text-blue-600 mr-3" @click="openAdjust(c)">Prime / retenue</button>
                <button v-if="canManage && c.unpaid !== 0" class="btn-primary" @click="prepareCourierPayout(c)">Préparer la paie</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card overflow-x-auto">
        <h2 class="font-semibold p-4 border-b">Fiches de paie</h2>
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Référence</th><th class="p-2">Livreur</th><th class="p-2">Période</th><th class="p-2 text-right">Montant</th><th class="p-2">Statut</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="p in courierPayouts" :key="p.id">
              <td class="p-2 font-mono text-xs">{{ p.reference }}</td>
              <td data-label="Livreur" class="p-2">{{ p.courier_name }}</td>
              <td data-label="Période" class="p-2 whitespace-nowrap text-gray-600">{{ date(p.period_start) }} → {{ date(p.period_end) }}<span v-if="p.automatic" title="Préparée automatiquement en fin de période"> ⏱</span></td>
              <td data-label="Montant" :class="['p-2 text-right font-semibold', signedClass(p.amount)]">{{ money(p.amount) }}</td>
              <td data-label="Statut" class="p-2"><PayoutBadge :status="p.status" :label="p.status_label" /></td>
              <td class="p-2 text-right"><button class="text-blue-600" @click="openCourierPayout(p)">Détail</button></td>
            </tr>
            <tr v-if="!courierPayouts.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucune fiche de paie.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Versement d'un livreur -->
    <Modal :open="remit.open" :title="`${remitHasCash ? 'Versement' : 'Colis'} de ${remit.courier?.name}`" @close="remit.open = false">
      <form class="space-y-3" @submit.prevent="saveRemit">
        <!-- Courses pas encore clôturées : le point attend -->
        <div v-if="remitOnTheRoad.length" class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800 space-y-1">
          <p class="font-semibold">🛵 {{ remitOnTheRoad.length }} course{{ remitOnTheRoad.length > 1 ? 's' : '' }} encore « En chemin »</p>
          <p>Le livreur doit indiquer « livré » ou « échec » dans son application avant le point de caisse.</p>
          <p class="space-x-2">
            <RouterLink v-for="p in remitOnTheRoad" :key="p.id" :to="`/admin/courses/${p.id}`" class="font-mono text-xs underline">{{ p.tracking_code }}</RouterLink>
          </p>
        </div>

        <!-- Colis non livrés à rendre au dépôt -->
        <div v-if="remitParcels.length" class="space-y-1">
          <div class="flex items-center justify-between">
            <p class="text-sm font-semibold">📦 Colis à rendre au dépôt ({{ remitParcels.length }})</p>
            <button type="button" class="text-xs text-blue-700" @click="remit.returned = remit.returned.length === remitParcels.length ? [] : remitParcels.map((p) => p.id)">
              {{ remit.returned.length === remitParcels.length ? 'Tout décocher' : 'Tout cocher' }}
            </button>
          </div>
          <p class="text-xs text-gray-600">Cochez chaque colis que le livreur vous remet. Ceux qui restent décochés sont notés comme gardés par le livreur.</p>
          <ul class="border rounded divide-y max-h-56 overflow-y-auto text-sm">
            <li v-for="p in remitParcels" :key="p.id" :class="['flex items-center gap-2 p-2', remit.returned.includes(p.id) ? 'bg-emerald-50' : '']">
              <input v-model="remit.returned" type="checkbox" :value="p.id" :aria-label="`Colis ${p.tracking_code} rendu`">
              <span class="font-mono text-xs">{{ p.tracking_code }}</span>
              <span class="flex-1 min-w-0 truncate">{{ p.recipient_name || p.recipient_phone }} <span class="text-xs text-gray-500">· {{ p.merchant }}</span></span>
              <span v-if="p.kept_for" class="text-xs text-indigo-700 whitespace-nowrap">🌙 Gardé pour la livraison du {{ date(p.kept_for) }}</span>
              <span v-else class="text-xs text-gray-600 whitespace-nowrap">{{ p.rescheduled_to ? `Reporté au ${date(p.rescheduled_to)}` : (p.incident || p.status_label) }}</span>
              <span :class="['text-xs whitespace-nowrap', !p.kept_for && isOld(p.held_since) ? 'text-red-600 font-semibold' : 'text-gray-500']">{{ heldFor(p.held_since) }}</span>
            </li>
          </ul>
          <p v-if="remitParcels.length - remit.returned.length > 0" class="text-xs text-amber-700">
            ⚠ {{ remitParcels.length - remit.returned.length }} colis resteront chez le livreur<template v-if="remitKept.length"> (dont {{ remitKept.length }} gardé{{ remitKept.length > 1 ? 's' : '' }} pour leur livraison)</template>.
          </p>
        </div>

        <p v-if="remit.collections.length" class="text-sm font-semibold pt-1">💵 Encaissements</p>
        <p v-if="remit.collections.length" class="text-sm text-gray-600">Décochez les encaissements que le livreur ne verse pas aujourd'hui.</p>
        <ul v-if="remit.collections.length" class="border rounded divide-y max-h-64 overflow-y-auto text-sm">
          <li v-for="c in remit.collections" :key="c.id" class="flex items-center gap-2 p-2">
            <input v-model="remit.selected" type="checkbox" :value="c.id" :aria-label="c.tracking_code">
            <span class="font-mono text-xs">{{ c.tracking_code }}</span>
            <span class="flex-1 truncate">{{ c.recipient_name }}</span>
            <span class="text-xs text-gray-500">{{ c.method_label }}</span>
            <span class="font-medium">{{ money(c.amount_collected) }}</span>
          </li>
        </ul>
        <ul v-if="remit.advances.length || remit.expenses.length" class="border rounded divide-y text-sm">
          <li v-for="a in remit.advances" :key="`a${a.id}`" class="flex items-center gap-2 p-2">
            <span class="flex-1">{{ a.pay_kept ? '🧾' : '💵 Avance :' }} {{ a.reason }} <span class="text-xs text-gray-500">{{ dateTime(a.given_at) }}</span></span>
            <span :class="['font-medium', a.amount < 0 ? 'text-emerald-700' : '']">{{ a.amount < 0 ? '−' : '+' }} {{ money(Math.abs(a.amount)) }}</span>
          </li>
          <li v-for="e in remit.expenses" :key="`e${e.id}`" class="flex items-center gap-2 p-2">
            <span class="font-mono text-xs">{{ e.tracking_code }}</span>
            <span class="flex-1">{{ e.description }}</span>
            <span class="font-medium text-emerald-700">− {{ money(e.amount) }}</span>
          </li>
          <li class="p-2 text-xs text-gray-500">Avances, frais payés par le livreur et paie gardée sur l'encaissé : toujours réglés avec ce versement.</li>
        </ul>
        <template v-if="remitHasCash">
        <p class="text-sm">
          <template v-if="remitExpected >= 0">Montant attendu : <strong>{{ money(remitExpected) }}</strong></template>
          <template v-else>Frais payés par le livreur : <strong>la caisse lui doit {{ money(-remitExpected) }}</strong></template>
        </p>
        <div>
          <label class="label" for="received">{{ remitExpected >= 0 ? 'Montant reçu (F) *' : 'Montant remis au livreur (F) *' }}</label>
          <input id="received" v-model.number="remit.amount_received" type="number" min="0" class="input" required>
          <p v-if="remitDifference" :class="['text-sm mt-1', signedClass(remitDifference)]">
            Écart : {{ money(remitDifference) }}{{ remitDifference < 0 ? ' (sera retenu sur la paie du livreur)' : '' }}
          </p>
        </div>
        <div><label class="label" for="rnotes">Note</label><input id="rnotes" v-model="remit.notes" class="input"></div>
        </template>
        <p v-if="remit.error" class="field-error">{{ remit.error }}</p>
        <button v-if="remitHasCash" class="btn-primary w-full" :disabled="remitOnTheRoad.length > 0 || (!remit.selected.length && !remit.advances.length && !remit.expenses.length)">
          Valider le versement{{ remit.returned.length ? ` et ${remit.returned.length} colis` : '' }}
        </button>
        <button v-else class="btn-primary w-full" :disabled="!remit.returned.length">Valider la réception de {{ remit.returned.length }} colis</button>
      </form>
    </Modal>

    <!-- Avance de caisse à un livreur -->
    <Modal :open="advance.open" :title="`Avance à ${advance.courier?.name}`" @close="advance.open = false">
      <form class="space-y-3" @submit.prevent="saveAdvance">
        <p class="text-sm text-gray-600">
          Argent remis au livreur avant sa mission (frais de gare, transport…). Il déclare ensuite les frais payés dans son
          application ; le reste revient à la caisse lors de son versement.
        </p>
        <div>
          <label class="label" for="adv-amount">Montant (F) *</label>
          <input id="adv-amount" v-model.number="advance.amount" type="number" min="1" class="input" required>
        </div>
        <div>
          <label class="label" for="adv-reason">Motif *</label>
          <div class="flex flex-wrap gap-2 mb-2">
            <button v-for="r in ['Frais de gare', 'Transport', 'Emballage']" :key="r" type="button" class="rounded-full px-3 py-1 text-xs ring-1 ring-slate-300" @click="advance.reason = r">{{ r }}</button>
          </div>
          <input id="adv-reason" v-model="advance.reason" class="input" required>
        </div>
        <p v-if="advance.error" class="field-error">{{ advance.error }}</p>
        <button class="btn-primary w-full" :disabled="!(advance.amount > 0) || !advance.reason">Remettre l'avance</button>
      </form>
    </Modal>

    <!-- Grand livre d'un marchand -->
    <Modal :open="ledger.open" :title="`Grand livre · ${ledger.merchant?.business_name}`" @close="ledger.open = false">
      <LedgerTable :entries="ledger.entries" />
      <form v-if="canManage" class="border-t mt-4 pt-3 space-y-2" @submit.prevent="saveMerchantAdjust">
        <p class="text-sm font-medium">Ajustement (+ en faveur du marchand, − à sa charge)</p>
        <div class="flex gap-2">
          <input v-model.number="ledger.adjust.amount" type="number" class="input w-32" placeholder="Montant" required>
          <input v-model="ledger.adjust.description" class="input" placeholder="Motif" required>
          <button class="btn-secondary">Ajouter</button>
        </div>
        <p v-if="ledger.error" class="field-error">{{ ledger.error }}</p>
      </form>
    </Modal>

    <!-- Prime / retenue livreur -->
    <Modal :open="adjust.open" :title="`Prime ou retenue · ${adjust.courier?.name}`" @close="adjust.open = false">
      <form class="space-y-3" @submit.prevent="saveCourierAdjust">
        <div><label class="label">Montant (F, négatif pour une retenue)</label><input v-model.number="adjust.amount" type="number" class="input" required></div>
        <div><label class="label">Motif</label><input v-model="adjust.description" class="input" required></div>
        <p v-if="adjust.error" class="field-error">{{ adjust.error }}</p>
        <button class="btn-primary w-full">Enregistrer</button>
      </form>
    </Modal>

    <!-- Fiche de paie -->
    <Modal :open="courierPayout.open" :title="`Paie ${courierPayout.data?.reference || ''}`" @close="courierPayout.open = false">
      <div v-if="courierPayout.data" class="space-y-3 text-sm">
        <p>
          {{ courierPayout.data.courier_name }} · {{ date(courierPayout.data.period_start) }} → {{ date(courierPayout.data.period_end) }}
          <span v-if="courierPayout.data.automatic" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-gray-600">fin de période</span>
        </p>
        <ul class="border rounded divide-y max-h-64 overflow-y-auto">
          <li v-for="e in courierPayout.data.earnings" :key="e.id" class="flex justify-between gap-2 p-2">
            <span>
              {{ e.type_label }} <span class="text-gray-500">{{ e.tracking_code || e.description }}</span>
              <span v-if="e.detail" class="block text-xs text-gray-500">{{ e.detail }}</span>
            </span>
            <span :class="['whitespace-nowrap', signedClass(e.amount)]">{{ money(e.amount) }}</span>
          </li>
        </ul>
        <div class="space-y-0.5">
          <p class="flex justify-between text-gray-600"><span>Gains (courses, salaire, primes)</span><span>{{ money(payslipTotals.gains) }}</span></p>
          <p v-if="payslipTotals.deductions" class="flex justify-between text-gray-600"><span>Retenues</span><span class="text-red-600">− {{ money(payslipTotals.deductions) }}</span></p>
          <p class="text-lg flex justify-between"><span>Total à payer</span><strong>{{ money(courierPayout.data.amount) }}</strong></p>
        </div>
        <PayForm
          v-if="courierPayout.data.status === 'draft' && canManage"
          compensation
          @pay="(p) => payCourierPayout(p)"
          @cancel="cancelCourierPayout"
        />
        <p v-else>{{ courierPayout.data.status_label }} {{ courierPayout.data.paid_at ? `le ${dateTime(courierPayout.data.paid_at)} (${courierPayout.data.method_label})` : '' }}</p>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import StatCard from '../../components/StatCard.vue'
import LedgerTable from '../../components/LedgerTable.vue'
import PayoutBadge from '../../components/PayoutBadge.vue'
import PayForm from '../../components/PayForm.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime, money, signedClass } from '../../utils/format'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const route = useRoute()
const canManage = computed(() => auth.can('finance.manage'))

const tabs = [
  { value: 'cash', label: 'Argent chez les livreurs' },
  { value: 'merchants', label: 'Soldes marchands' },
  { value: 'payouts', label: 'Reversements' },
  { value: 'payroll', label: 'Paie des livreurs' },
]
const tab = ref(route.query.tab || 'cash')

const cash = ref(null)
const remittances = ref([])
const merchantBalances = ref([])
const payouts = ref([])
const courierPayouts = ref([])

const remit = reactive({ open: false, courier: null, collections: [], advances: [], expenses: [], parcels: [], returned: [], selected: [], amount_received: 0, notes: '', error: '' })
const remitHasCash = computed(() => remit.collections.length > 0 || remit.advances.length > 0 || remit.expenses.length > 0)
const remitOnTheRoad = computed(() => remit.parcels.filter((p) => p.on_the_road))
const remitParcels = computed(() => remit.parcels.filter((p) => !p.on_the_road))
// Gardés par le livreur pour leur livraison (mission ramassage) : normalement pas rendus au point
const remitKept = computed(() => remitParcels.value.filter((p) => p.kept_for && !remit.returned.includes(p.id)))
const isOld = (since) => since && Date.now() - new Date(since).getTime() > 24 * 3600000
function heldFor(since) {
  const hours = Math.floor((Date.now() - new Date(since).getTime()) / 3600000)
  if (hours < 1) return "depuis moins d'1 h"
  return hours >= 24 ? `depuis ${Math.floor(hours / 24)} j` : `depuis ${hours} h`
}
const advance = reactive({ open: false, courier: null, amount: null, reason: 'Frais de gare', error: '' })
const ledger = reactive({ open: false, merchant: null, entries: [], adjust: { amount: null, description: '' }, error: '' })
const adjust = reactive({ open: false, courier: null, amount: null, description: '', error: '' })
const courierPayout = reactive({ open: false, data: null })
// Gains et retenues de la fiche ; un report de retenue réduit les retenues
const payslipTotals = computed(() => {
  const lines = courierPayout.data?.earnings || []
  const carried = lines.filter((e) => e.type === 'carryover').reduce((s, e) => s + e.amount, 0)
  const others = lines.filter((e) => e.type !== 'carryover')
  return {
    gains: others.filter((e) => e.amount > 0).reduce((s, e) => s + e.amount, 0),
    deductions: -others.filter((e) => e.amount < 0).reduce((s, e) => s + e.amount, 0) - carried,
  }
})

// Montant dû par le livreur, frais d'expédition avancés déduits ; négatif : la caisse lui doit de l'argent
// Encaissements cochés + avances reçues − frais payés ; négatif : la caisse doit de l'argent au livreur
const remitExpected = computed(() => remit.collections.filter((c) => remit.selected.includes(c.id)).reduce((s, c) => s + c.amount_collected, 0)
  + remit.advances.reduce((s, a) => s + a.amount, 0)
  - remit.expenses.reduce((s, e) => s + e.amount, 0))
// Le champ est toujours saisi en positif : reçu du livreur, ou remis au livreur quand la caisse lui doit
const remitSigned = computed(() => (remitExpected.value < 0 ? -1 : 1) * (remit.amount_received ?? 0))
const remitDifference = computed(() => remitSigned.value - remitExpected.value)

function setTab(value) {
  tab.value = value
  router.replace({ query: { tab: value } })
  load()
}

async function load() {
  if (tab.value === 'cash' || tab.value === 'payroll') {
    cash.value = (await http.get('/finance/cash')).data
  }
  if (tab.value === 'cash') remittances.value = (await http.get('/finance/remittances')).data.data
  if (tab.value === 'merchants') merchantBalances.value = (await http.get('/finance/merchants')).data.data
  if (tab.value === 'payouts') payouts.value = (await http.get('/finance/payouts')).data.data
  if (tab.value === 'payroll') courierPayouts.value = (await http.get('/finance/courier-payouts')).data.data
}

async function openRemit(courier) {
  const { data } = await http.get(`/finance/couriers/${courier.courier_id}/collections`)
  Object.assign(remit, {
    open: true,
    courier,
    collections: data.data,
    advances: data.advances || [],
    expenses: data.expenses || [],
    parcels: data.parcels || [],
    returned: [],
    selected: data.data.map((c) => c.id),
    amount_received: Math.abs(courier.cash_in_hand),
    notes: '',
    error: '',
  })
}

async function saveRemit() {
  remit.error = ''
  try {
    if (!remitHasCash.value) {
      await http.post(`/couriers/${remit.courier.courier_id}/parcels/receive`, { order_ids: remit.returned })
      remit.open = false
      toasts.success(`${remit.returned.length} colis reçu(s) au dépôt.`)
      load()
      return
    }
    const allSelected = remit.selected.length === remit.collections.length
    const kept = remitParcels.value.length - remit.returned.length
    await http.post('/finance/remittances', {
      courier_id: remit.courier.courier_id,
      amount_received: remitSigned.value,
      collection_ids: allSelected ? undefined : remit.selected,
      returned_order_ids: remit.returned,
      notes: remit.notes || undefined,
    })
    remit.open = false
    if (kept > 0) toasts.push(`${kept} colis reste(nt) chez ${remit.courier.name}.`, 'info', { title: 'Versement enregistré' })
    else toasts.success('Versement enregistré.')
    load()
  } catch (e) {
    remit.error = apiErrorMessage(e)
  }
}

function openAdvance(courier) {
  Object.assign(advance, { open: true, courier, amount: null, reason: 'Frais de gare', error: '' })
}

async function saveAdvance() {
  advance.error = ''
  try {
    await http.post(`/finance/couriers/${advance.courier.courier_id}/advances`, { amount: advance.amount, reason: advance.reason })
    advance.open = false
    toasts.success('Avance enregistrée.')
    load()
  } catch (e) {
    advance.error = apiErrorMessage(e)
  }
}

async function openLedger(merchant) {
  ledger.merchant = merchant
  ledger.error = ''
  ledger.adjust = { amount: null, description: '' }
  ledger.entries = (await http.get(`/finance/merchants/${merchant.merchant_id}/ledger`)).data.data
  ledger.open = true
}

async function saveMerchantAdjust() {
  ledger.error = ''
  try {
    await http.post(`/finance/merchants/${ledger.merchant.merchant_id}/adjustments`, ledger.adjust)
    await openLedger(ledger.merchant)
    load()
  } catch (e) {
    ledger.error = apiErrorMessage(e)
  }
}

async function preparePayout(merchant) {
  try {
    const { data } = await http.post('/finance/payouts', { merchant_id: merchant.merchant_id })
    router.push(`/admin/caisse/reversements/${data.data.id}`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function openAdjust(courier) {
  Object.assign(adjust, { open: true, courier, amount: null, description: '', error: '' })
}

async function saveCourierAdjust() {
  adjust.error = ''
  try {
    await http.post(`/finance/couriers/${adjust.courier.courier_id}/adjustments`, { amount: adjust.amount, description: adjust.description })
    adjust.open = false
    load()
  } catch (e) {
    adjust.error = apiErrorMessage(e)
  }
}

async function prepareCourierPayout(courier) {
  try {
    const { data } = await http.post('/finance/courier-payouts', { courier_id: courier.courier_id })
    courierPayout.data = data.data
    courierPayout.open = true
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function openCourierPayout(payout) {
  courierPayout.data = (await http.get(`/finance/courier-payouts/${payout.id}`)).data.data
  courierPayout.open = true
}

async function payCourierPayout(payment) {
  try {
    courierPayout.data = (await http.post(`/finance/courier-payouts/${courierPayout.data.id}/pay`, payment)).data.data
    toasts.success('Paie enregistrée.')
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function cancelCourierPayout() {
  await http.post(`/finance/courier-payouts/${courierPayout.data.id}/cancel`)
  courierPayout.open = false
  load()
}

onMounted(load)
</script>
