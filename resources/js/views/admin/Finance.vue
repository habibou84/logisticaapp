<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
      <h1 class="flex-1 text-xl font-bold">Caisse</h1>
      <RouterLink to="/admin/caisse/cloture" class="btn-secondary">Clôture du jour</RouterLink>
      <RouterLink to="/admin/caisse/journal" class="btn-secondary">Journal de la caisse</RouterLink>
    </div>

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

    <!-- Date de livraison des courses : même règle que la page Courses -->
    <div v-if="tab === 'cash' || tab === 'merchants'" class="card p-3 flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Date de livraison des courses">
      <span class="font-semibold">📅 Date de livraison des courses :</span>
      <button
        v-for="p in PERIODS" :key="p.key" type="button"
        :class="['rounded-full px-3 py-1 ring-1', period.key === p.key ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        :aria-pressed="period.key === p.key" @click="choosePeriod(p.key)"
      >
        {{ p.label }}
      </button>
      <template v-if="period.key === 'custom'">
        <input v-model="period.from" type="date" class="input w-auto py-1" aria-label="Du" @change="periodChanged">
        <input v-model="period.to" type="date" class="input w-auto py-1" :min="period.from" aria-label="Au" @change="periodChanged">
      </template>
      <span class="ml-auto text-xs text-gray-500">{{ periodLabel }}</span>
    </div>

    <!-- Argent chez les livreurs -->
    <template v-if="tab === 'cash'">
      <p v-if="cash?.whatsapp_templates === 'pending'" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900" role="status">
        Reçu de versement et rappel aux livreurs par WhatsApp : messages envoyés à Meta pour acceptation (de quelques minutes à 24 h). Ils partiront tout seuls ensuite.
      </p>
      <p v-else-if="cash?.whatsapp_templates === 'rejected'" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900" role="alert">
        Meta a refusé un message WhatsApp de la caisse (reçu ou rappel). Voir la page <RouterLink to="/admin/whatsapp" class="underline font-medium">WhatsApp</RouterLink>.
      </p>
      <div v-if="cash" class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard title="Argent chez les livreurs" :value="money(cash.totals.cash_in_hands)" icon="🛵" :subtitle="cash.totals.owed_to_couriers ? `La caisse leur doit ${money(cash.totals.owed_to_couriers)} (à part)` : ''" />
        <StatCard title="Encaissé aujourd'hui" :value="money(cash.totals.collected_today)" icon="💵" />
        <StatCard title="Reçu des livreurs aujourd'hui" :value="money(cash.totals.received_today)" icon="🏦" />
        <button type="button" :class="['card p-4 text-left', cash.totals.watched ? 'border-orange-300 bg-orange-50' : '']" @click="filters.watchOnly = !filters.watchOnly">
          <p :class="['text-sm', cash.totals.watched ? 'text-orange-800' : 'text-gray-600']">À surveiller</p>
          <p :class="['text-2xl font-bold', cash.totals.watched ? 'text-orange-800' : '']">{{ cash.totals.watched }} livreur{{ cash.totals.watched > 1 ? 's' : '' }}</p>
          <p class="text-xs text-gray-600">{{ alertText }}</p>
        </button>
      </div>
      <div v-if="days" class="card overflow-x-auto">
        <div class="flex flex-wrap items-center gap-2 p-4 border-b">
          <h2 class="flex-1 font-semibold">📊 Point par date de livraison</h2>
          <span class="text-xs text-gray-500">{{ period.key === 'all' ? '31 derniers jours · ' : '' }}un toucher sur une date : toute la caisse sur ce jour</span>
          <button type="button" class="btn-secondary" @click="exportDays">Exporter</button>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 p-3">
          <StatCard title="Encaissé chez les clients" :value="money(days.totals.collected)" icon="💵" :subtitle="`${days.totals.delivered} course${days.totals.delivered > 1 ? 's' : ''} livrée${days.totals.delivered > 1 ? 's' : ''}`" />
          <StatCard title="Déjà à la caisse" :value="money(days.totals.at_desk)" icon="🏦" subtitle="reçu des livreurs" />
          <StatCard title="Encore chez les livreurs" :value="money(days.totals.with_couriers)" icon="🛵" :subtitle="days.totals.with_couriers ? 'à recevoir' : '✓ tout est arrivé'" />
          <StatCard title="À reverser aux clients" :value="money(days.totals.net_to_merchants)" icon="🏪" :subtitle="`encaissé moins les frais (${money(days.totals.fees)})`" />
        </div>
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Date de livraison</th><th class="p-2 text-right">Livrées</th><th class="p-2 text-right">Encaissé</th><th class="p-2 text-right">Déjà à la caisse</th><th class="p-2 text-right">Chez les livreurs</th><th class="p-2 text-right">Frais de livraison</th><th class="p-2 text-right">À reverser</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="d in days.days" :key="d.day" :class="d.with_couriers ? 'bg-amber-50/60' : ''">
              <td class="p-2"><button type="button" class="font-medium text-blue-700 hover:underline first-letter:uppercase" @click="chooseDay(d.day)">{{ dayLabel(d.day) }}</button></td>
              <td data-label="Livrées" class="p-2 text-right">{{ d.delivered }}</td>
              <td data-label="Encaissé" class="p-2 text-right">{{ money(d.collected) }}</td>
              <td data-label="Déjà à la caisse" class="p-2 text-right">{{ money(d.at_desk) }}</td>
              <td data-label="Chez les livreurs" :class="['p-2 text-right', d.with_couriers ? 'font-semibold text-amber-700' : 'text-emerald-700']">{{ d.with_couriers ? money(d.with_couriers) : '✓ 0' }}</td>
              <td data-label="Frais" class="p-2 text-right">{{ money(d.fees) }}</td>
              <td data-label="À reverser" class="p-2 text-right">{{ money(d.net_to_merchants) }}</td>
            </tr>
            <tr v-if="!days.days.length"><td colspan="7" class="p-4 text-center text-gray-500">Aucune course livrée à ces dates.</td></tr>
            <tr v-else class="bg-slate-100 font-bold">
              <td class="p-2">Total</td><td data-label="Livrées" class="p-2 text-right">{{ days.totals.delivered }}</td><td data-label="Encaissé" class="p-2 text-right">{{ money(days.totals.collected) }}</td>
              <td data-label="Déjà à la caisse" class="p-2 text-right">{{ money(days.totals.at_desk) }}</td><td data-label="Chez les livreurs" class="p-2 text-right text-amber-700">{{ money(days.totals.with_couriers) }}</td>
              <td data-label="Frais" class="p-2 text-right">{{ money(days.totals.fees) }}</td><td data-label="À reverser" class="p-2 text-right">{{ money(days.totals.net_to_merchants) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card p-3 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[12rem]">
          <label class="label" for="cash-search">Chercher un livreur</label>
          <input id="cash-search" v-model="filters.search" type="search" class="input" placeholder="Nom ou téléphone">
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="filters.withMoney" type="checkbox"> Seulement ceux qui ont de l'argent ou des colis</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="filters.watchOnly" type="checkbox"> Seulement « à surveiller »</label>
        <button type="button" class="btn-secondary" @click="exportCash">Exporter</button>
      </div>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Livreur</th><th class="p-2">Par date de livraison</th><th class="p-2 text-right" title="Encaissé + avances − frais payés − paie gardée sur l'encaissé">À remettre à la caisse</th><th class="p-2" title="Colis non livrés encore chez le livreur">Colis</th><th class="p-2">Depuis</th><th class="p-2 text-right">Gains non payés</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="c in visibleCouriers" :key="c.courier_id" :class="c.watch ? 'bg-orange-50' : ''">
              <td class="p-2 font-medium">{{ c.name }}<div class="text-xs text-gray-500">{{ c.phone }}</div><div v-if="c.watch" class="text-xs font-semibold text-orange-800">{{ c.watch }}</div></td>
              <td data-label="Par date" class="p-2">
                <div v-if="c.days.length || c.advances || c.expenses" class="flex flex-wrap gap-1 text-xs">
                  <button
                    v-for="d in c.days" :key="d.day" type="button"
                    :class="['rounded-full px-2 py-0.5', inPeriod(d.day) ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-400']"
                    :title="`${d.count} course${d.count > 1 ? 's' : ''}`" @click="chooseDay(d.day)"
                  >
                    {{ shortDay(d.day) }} : {{ money(d.amount) }}
                  </button>
                  <span v-if="c.advances || c.expenses" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">sans date : {{ money(c.advances - c.expenses) }}</span>
                </div>
                <span v-else class="text-gray-400">—</span>
              </td>
              <td data-label="À remettre" class="p-2 text-right font-semibold">
                <span v-if="c.cash_in_hand < 0" class="text-blue-700">La caisse lui doit {{ money(-c.cash_in_hand) }}</span>
                <template v-else>{{ money(c.cash_in_hand) }}</template>
                <div v-if="c.pending_collections || c.advances || c.expenses" class="text-xs font-normal text-gray-500">
                  {{ [c.collected ? `${money(c.collected)} encaissés` : '', c.advances ? `avance ${money(c.advances)}` : '', c.expenses ? `frais −${money(c.expenses)}` : ''].filter(Boolean).join(' · ') }}
                </div>
                <div v-if="period.key !== 'all' && c.days.length" class="text-xs font-normal text-amber-800">dont courses de la période : {{ money(periodAmount(c)) }}</div>
              </td>
              <td data-label="Colis" class="p-2"><span v-if="c.parcels_in_hand" class="rounded-full bg-amber-100 text-amber-900 px-2 py-0.5 text-xs font-medium">📦 {{ c.parcels_in_hand }}</span><span v-else class="text-gray-400">—</span></td>
              <td data-label="Depuis" class="p-2 text-xs">{{ c.oldest_collected_at ? dateTime(c.oldest_collected_at) : '—' }}</td>
              <td data-label="Gains non payés" :class="['p-2 text-right', signedClass(c.unpaid)]">{{ money(c.unpaid) }}</td>
              <td class="p-2 text-right whitespace-nowrap space-x-1">
                <button v-if="canManage" class="btn-secondary" @click="openAdvance(c)">Donner une avance</button>
                <button v-if="(c.pending_collections || c.advances || c.expenses) && canManage" class="btn-primary" @click="openRemit(c)">{{ c.cash_in_hand >= 0 ? 'Recevoir l\'argent' : 'Rembourser le livreur' }}</button>
                <button v-else-if="c.parcels_in_hand && canManage" class="btn-primary" @click="openRemit(c)">Recevoir les colis</button>
              </td>
            </tr>
            <tr v-if="cash && !visibleCouriers.length"><td colspan="7" class="p-4 text-center text-gray-500">Aucun livreur à afficher.</td></tr>
          </tbody>
        </table>
      </div>

      <div class="card overflow-x-auto">
        <div class="flex flex-wrap items-end gap-2 p-4 border-b">
          <h2 class="flex-1 font-semibold">Argent reçu des livreurs</h2>
          <select v-model="remitFilter.basis" class="input w-auto py-1 text-xs" aria-label="Dates selon" @change="loadRemittances">
            <option value="received">Jour du versement</option>
            <option value="delivery">Date de livraison des courses</option>
          </select>
          <label class="text-xs text-gray-600">Du <input v-model="remitFilter.from" type="date" class="input w-auto py-1" @change="loadRemittances"></label>
          <label class="text-xs text-gray-600">au <input v-model="remitFilter.to" type="date" class="input w-auto py-1" @change="loadRemittances"></label>
          <button type="button" class="btn-secondary" @click="exportRemittances">Exporter</button>
        </div>
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Date</th><th class="p-2">Livreur</th><th class="p-2 text-right">Attendu</th><th class="p-2 text-right">Reçu</th><th class="p-2 text-right">Écart</th><th class="p-2">Colis</th><th class="p-2">Reçu par</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="r in remittances" :key="r.id" :class="r.cancelled_at ? 'text-gray-400 line-through' : ''">
              <td class="p-2 text-xs">{{ dateTime(r.received_at) }}<span v-if="r.cancelled_at" class="block no-underline font-semibold text-red-700">Annulé</span></td>
              <td data-label="Livreur" class="p-2">{{ r.courier_name }}<span v-if="r.days?.length" class="block text-xs text-gray-500">courses du {{ r.days.map((d) => d.day ? shortDay(d.day) : 'sans date').join(', ') }}</span></td>
              <td data-label="Attendu" class="p-2 text-right">{{ money(r.amount_expected) }}</td>
              <td data-label="Reçu" class="p-2 text-right">{{ money(r.amount_received) }}</td>
              <td data-label="Écart" :class="['p-2 text-right font-medium', signedClass(r.difference)]">{{ r.difference ? money(r.difference) : '—' }}</td>
              <td data-label="Colis" class="p-2 text-xs">
                <span v-if="r.parcels_returned.length" class="block text-emerald-700" :title="r.parcels_returned.join(', ')">✓ {{ r.parcels_returned.length }} rendu{{ r.parcels_returned.length > 1 ? 's' : '' }}</span>
                <span v-if="r.parcels_kept.length" class="block text-amber-700" :title="r.parcels_kept.join(', ')">⚠ {{ r.parcels_kept.length }} gardé{{ r.parcels_kept.length > 1 ? 's' : '' }} : {{ r.parcels_kept.join(', ') }}</span>
                <span v-if="!r.parcels_returned.length && !r.parcels_kept.length" class="text-gray-400">—</span>
              </td>
              <td data-label="Reçu par" class="p-2 text-xs">{{ r.received_by }}<span v-if="r.notes" class="block text-gray-500">{{ r.notes }}</span></td>
              <td class="p-2 text-right"><RouterLink :to="`/admin/caisse/versements/${r.id}`" class="text-blue-600 no-underline">Reçu ›</RouterLink></td>
            </tr>
            <tr v-if="!remittances.length"><td colspan="8" class="p-4 text-center text-gray-500">Aucun versement sur cette période.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Soldes des clients -->
    <template v-if="tab === 'merchants'">
      <p v-if="period.key !== 'all'" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">📅 Seulement l'argent des courses {{ periodLabel }} : « Préparer le paiement » ne paie que ces courses.</p>
      <p class="text-sm text-gray-600">
        « Disponible » = argent arrivé en caisse, prêt à être payé à le client. « En attente » = courses livrées dont l'argent est encore chez un livreur.
      </p>
      <div class="card p-3 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[12rem]">
          <label class="label" for="merchant-search">Chercher un client</label>
          <input id="merchant-search" v-model="filters.merchant" type="search" class="input">
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="filters.allMerchants" type="checkbox" @change="load"> Afficher aussi les soldes à zéro</label>
        <button type="button" class="btn-secondary" @click="exportMerchants">Exporter</button>
      </div>
      <div v-if="canManage && payableMerchants.length" class="card p-3 flex flex-wrap items-center gap-3">
        <p class="flex-1 min-w-[14rem] text-sm text-gray-700">Cochez les clients, puis préparez leurs paiements d'un coup. Ceux dont le moyen de paiement n'est pas vérifié sont grisés.</p>
        <button type="button" class="btn-primary" :disabled="!batch.merchants.length || batch.busy" @click="prepareSelected">
          Préparer {{ batch.merchants.length }} paiement{{ batch.merchants.length > 1 ? 's' : '' }} ({{ money(selectedMerchantsTotal) }})
        </button>
      </div>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th v-if="canManage" class="p-2 w-8"><input type="checkbox" aria-label="Tout cocher" :checked="payableMerchants.length > 0 && batch.merchants.length === payableMerchants.length" @change="toggleAllMerchants($event.target.checked)"></th><th class="p-2">Client</th><th class="p-2 text-right">Disponible</th><th class="p-2 text-right">En attente</th><th class="p-2 text-right">Total à payer</th><th class="p-2">Payer avec</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="m in visibleMerchants" :key="m.merchant_id" :class="canManage && m.available > 0 && !merchantPayable(m) ? 'bg-slate-50 text-gray-600' : ''">
              <td v-if="canManage" class="p-2"><input v-model="batch.merchants" type="checkbox" :value="m.merchant_id" :disabled="!merchantPayable(m)" :aria-label="`Choisir ${m.business_name}`"></td>
              <td class="p-2 font-medium">{{ m.business_name }}</td>
              <td data-label="Disponible" :class="['p-2 text-right font-semibold', signedClass(m.available)]">{{ money(m.available) }}</td>
              <td data-label="En attente" class="p-2 text-right text-gray-600">{{ money(m.pending_cash) }}</td>
              <td data-label="Total à payer" class="p-2 text-right">{{ money(m.unpaid_total) }}</td>
              <!-- Moyen préféré du client ; rien de donné : à demander ou à saisir -->
              <td data-label="Payer avec" :class="['p-2 text-xs', m.payment_method ? '' : 'text-red-700']">
                <RouterLink :to="{ path: '/admin/marchands', query: { id: m.merchant_id, onglet: 'paiement' } }" class="hover:underline">
                  <template v-if="m.payment_method">
                    {{ m.payment_method.summary }}
                    <span :class="m.payment_method.status === 'verified' ? 'text-emerald-700' : 'text-amber-700'">{{ m.payment_method.status === 'verified' ? '✔' : '⏳' }}</span>
                  </template>
                  <template v-else>⚠️ Aucun moyen de paiement</template>
                  <span v-if="m.payment_methods_pending" class="ml-1 whitespace-nowrap rounded-full bg-amber-100 px-2 text-amber-800">{{ m.payment_methods_pending }} à vérifier</span>
                </RouterLink>
              </td>
              <td class="p-2 text-right whitespace-nowrap">
                <button class="text-blue-600 mr-3" @click="openLedger(m)">Grand livre</button>
                <button v-if="canManage && m.available !== 0" class="btn-primary" @click="preparePayout(m)">{{ period.key === 'all' ? 'Préparer le paiement' : `Payer ${payLabel}` }}</button>
              </td>
            </tr>
            <tr v-if="!visibleMerchants.length"><td :colspan="canManage ? 7 : 6" class="p-4 text-center text-gray-500">Aucun montant à payer.</td></tr>
            <tr v-else class="bg-slate-100 font-bold">
              <td v-if="canManage" /><td class="p-2">Total{{ period.key === 'all' ? '' : ` ${periodLabel}` }}</td>
              <td data-label="Disponible" class="p-2 text-right">{{ money(visibleMerchants.reduce((n, m) => n + m.available, 0)) }}</td>
              <td data-label="En attente" class="p-2 text-right">{{ money(visibleMerchants.reduce((n, m) => n + m.pending_cash, 0)) }}</td>
              <td data-label="Total à payer" class="p-2 text-right">{{ money(visibleMerchants.reduce((n, m) => n + m.unpaid_total, 0)) }}</td>
              <td colspan="2" />
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Reversements -->
    <template v-if="tab === 'payouts'">
      <div class="card p-3 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[12rem]">
          <label class="label" for="payout-search">Chercher un client ou une référence</label>
          <input id="payout-search" v-model="filters.payout" type="search" class="input">
        </div>
        <div>
          <label class="label" for="payout-status">Statut</label>
          <select id="payout-status" v-model="filters.payoutStatus" class="input w-auto" @change="load">
            <option value="">Tous</option><option value="draft">À payer</option><option value="paid">Payés</option><option value="cancelled">Annulés</option>
          </select>
        </div>
        <button type="button" class="btn-secondary" @click="exportPayouts">Exporter</button>
        <button v-if="canManage && draftPayouts.length" type="button" class="btn-primary" @click="openBatchPay">Marquer plusieurs comme payés ({{ draftPayouts.length }})</button>
      </div>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Référence</th><th class="p-2">Client</th><th class="p-2">Période</th><th class="p-2 text-right">Net</th><th class="p-2">Statut</th><th class="p-2">Payé le</th></tr>
          </thead>
          <tbody class="divide-y">
 <tr v-for="p in visiblePayouts" :key="p.id" class="hover:bg-slate-50 cursor-pointer" @click="router.push(`/admin/caisse/reversements/${p.id}`)">
              <td class="p-2 font-mono text-xs">{{ p.reference }}</td>
              <td data-label="Client" class="p-2">{{ p.merchant.business_name }}</td>
              <td data-label="Période" class="p-2 text-xs">{{ date(p.period_start) }} → {{ date(p.period_end) }}</td>
              <td data-label="Net" :class="['p-2 text-right font-semibold', signedClass(p.net_amount)]">{{ money(p.net_amount) }}</td>
              <td data-label="Statut" class="p-2"><PayoutBadge :status="p.status" :label="p.status_label" /></td>
              <td data-label="Payé le" class="p-2 text-xs">{{ p.paid_at ? `${dateTime(p.paid_at)} · ${p.method_label}` : '—' }}</td>
            </tr>
            <tr v-if="!visiblePayouts.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucun paiement.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Paiements des factures (distribution, MAD) : argent reçu en dehors des livreurs -->
    <template v-if="tab === 'invoices'">
      <div class="flex flex-wrap items-end gap-3">
        <div><label class="label" for="ip-month">Mois</label><input id="ip-month" v-model="invoicePayments.month" type="month" class="input w-auto" @change="load"></div>
        <RouterLink to="/admin/factures" class="text-sm text-blue-600 pb-2">Factures des clients →</RouterLink>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <StatCard title="Reçu ce mois-ci" :value="money(invoicePayments.total)" icon="💶" />
        <StatCard v-for="(amount, label) in invoicePayments.by_method" :key="label" :title="label" :value="money(amount)" icon="•" />
      </div>
      <div class="card overflow-x-auto">
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th class="p-2">Date</th><th class="p-2">Client</th><th class="p-2">Facture</th><th class="p-2">Moyen</th><th class="p-2 text-right">Montant</th><th class="p-2">Saisi par</th></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="p in invoicePayments.data" :key="p.id" class="hover:bg-slate-50 cursor-pointer" @click="router.push(`/admin/factures/${p.invoice.id}`)">
              <td class="p-2 whitespace-nowrap">{{ date(p.paid_on) }}</td>
              <td data-label="Client" class="p-2">{{ p.invoice.merchant }} <span class="text-xs text-gray-500">· {{ p.invoice.activity_label }}</span></td>
              <td data-label="Facture" class="p-2 font-mono text-xs">{{ p.invoice.number }}</td>
              <td data-label="Moyen" class="p-2">{{ p.method_label }}<span v-if="p.reference" class="block text-xs text-gray-500">{{ p.reference }}</span></td>
              <td data-label="Montant" class="p-2 text-right font-semibold">{{ money(p.amount) }}</td>
              <td data-label="Saisi par" class="p-2 text-xs">{{ p.recorded_by || '—' }}</td>
            </tr>
            <tr v-if="!invoicePayments.data.length"><td colspan="6" class="p-4 text-center text-gray-500">Aucun paiement de facture ce mois-ci.</td></tr>
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
            <tr v-for="c in (cash?.data || []).filter((c) => c.active || c.unpaid !== 0)" :key="c.courier_id">
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
        <div class="flex flex-wrap items-end gap-3 p-4 border-b">
          <h2 class="flex-1 font-semibold">Fiches de paie</h2>
          <template v-if="canManage && draftPayslips.length">
            <div>
              <label class="label" for="payslips-method">Payé en</label>
              <select id="payslips-method" v-model="batch.payslipMethod" class="input w-auto">
                <option v-for="m in PAY_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
            </div>
            <button type="button" class="btn-primary" :disabled="!batch.payslips.length || batch.busy" @click="paySelectedPayslips">
              Payer {{ batch.payslips.length }} fiche{{ batch.payslips.length > 1 ? 's' : '' }} ({{ money(selectedPayslipsTotal) }})
            </button>
          </template>
        </div>
        <table class="w-full text-sm stack-table">
          <thead class="bg-slate-50 text-left text-gray-600">
            <tr><th v-if="canManage" class="p-2 w-8"><input v-if="draftPayslips.length" type="checkbox" aria-label="Tout cocher" :checked="batch.payslips.length === draftPayslips.length" @change="toggleAllPayslips($event.target.checked)"></th><th class="p-2">Référence</th><th class="p-2">Livreur</th><th class="p-2">Période</th><th class="p-2 text-right">Montant</th><th class="p-2">Statut</th><th /></tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="p in courierPayouts" :key="p.id" :class="p.status === 'draft' && holding(p) ? 'bg-orange-50' : ''">
              <td v-if="canManage" class="p-2"><input v-if="p.status === 'draft'" v-model="batch.payslips" type="checkbox" :value="p.id" :aria-label="`Choisir ${p.reference}`"></td>
              <td class="p-2 font-mono text-xs">{{ p.reference }}</td>
              <td data-label="Livreur" class="p-2">{{ p.courier_name }}<span v-if="p.status === 'draft' && holding(p)" class="block text-xs font-semibold text-orange-800">Garde encore {{ money(holding(p)) }} : vous pouvez aussi garder la paie sur l'encaissé (Détail)</span></td>
              <td data-label="Période" class="p-2 whitespace-nowrap text-gray-600">{{ date(p.period_start) }} → {{ date(p.period_end) }}<span v-if="p.automatic" title="Préparée automatiquement en fin de période"> ⏱</span></td>
              <td data-label="Montant" :class="['p-2 text-right font-semibold', signedClass(p.amount)]">{{ money(p.amount) }}</td>
              <td data-label="Statut" class="p-2"><PayoutBadge :status="p.status" :label="p.status_label" /></td>
              <td class="p-2 text-right"><button class="text-blue-600" @click="openCourierPayout(p)">Détail</button></td>
            </tr>
            <tr v-if="!courierPayouts.length"><td :colspan="canManage ? 7 : 6" class="p-4 text-center text-gray-500">Aucune fiche de paie.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Paiements préparés : marquer payés ensemble -->
    <Modal :open="batchPay.open" title="Marquer des paiements comme payés" @close="batchPay.open = false">
      <p class="text-sm text-gray-600 mb-3">Après les transferts, vérifiez le moyen et saisissez la référence de chacun. Seuls les paiements cochés sont validés.</p>
      <div class="space-y-2 max-h-[60vh] overflow-y-auto">
        <div v-for="row in batchPay.rows" :key="row.id" class="rounded-lg border p-3 flex flex-wrap items-end gap-3">
          <label class="flex items-center gap-2 flex-1 min-w-[12rem]">
            <input v-model="row.selected" type="checkbox">
            <span><span class="font-medium">{{ row.merchant }}</span> · <span :class="signedClass(row.amount)">{{ money(row.amount) }}</span>
              <span class="block text-xs text-gray-600">{{ row.reference }}<template v-if="row.summary"> · {{ row.summary }}<span v-if="!row.verified" class="text-amber-700"> (pas vérifié)</span></template></span>
            </span>
          </label>
          <div>
            <label class="label" :for="`bp-method-${row.id}`">Moyen</label>
            <select :id="`bp-method-${row.id}`" v-model="row.method" class="input w-auto">
              <option v-for="m in PAY_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
          <div>
            <label class="label" :for="`bp-ref-${row.id}`">Référence</label>
            <input :id="`bp-ref-${row.id}`" v-model="row.transaction_ref" type="text" maxlength="100" class="input w-40" :placeholder="row.method === 'cash' ? 'Facultatif' : 'Réf. du transfert'">
          </div>
        </div>
      </div>
      <p v-if="batchPay.error" class="text-sm text-red-700 mt-2" role="alert">{{ batchPay.error }}</p>
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn-secondary" @click="batchPay.open = false">Fermer</button>
        <button type="button" class="btn-primary" :disabled="!batchPaySelected.length || batch.busy" @click="saveBatchPay">
          Marquer {{ batchPaySelected.length }} paiement{{ batchPaySelected.length > 1 ? 's' : '' }} comme payé{{ batchPaySelected.length > 1 ? 's' : '' }}
        </button>
      </div>
    </Modal>

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
        <p v-if="remit.collections.length" class="text-sm text-gray-600">
          Rangés par date de livraison. Décochez ce que le livreur ne verse pas aujourd'hui.
          <template v-if="period.key !== 'all'"> Seules les courses {{ periodLabel }} sont cochées au départ.</template>
        </p>
        <div v-if="remit.collections.length" class="max-h-80 overflow-y-auto space-y-2 text-sm">
          <div v-for="g in remitDays" :key="g.day || 'none'" class="border rounded">
            <label :class="['flex items-center gap-2 p-2 font-semibold border-b', g.selected === g.items.length ? 'bg-emerald-50' : 'bg-slate-50']">
              <input type="checkbox" :checked="g.selected === g.items.length" :indeterminate.prop="g.selected > 0 && g.selected < g.items.length" @change="toggleRemitDay(g, $event.target.checked)">
              <span class="first-letter:uppercase">{{ g.day ? dayLabel(g.day) : 'Sans date' }}</span>
              <span class="text-xs font-normal text-gray-500">· {{ g.items.length }} course{{ g.items.length > 1 ? 's' : '' }}</span>
              <span class="ml-auto">{{ money(g.total) }}</span>
            </label>
            <ul class="divide-y pl-4">
              <li v-for="c in g.items" :key="c.id" class="flex items-center gap-2 p-2">
                <input v-model="remit.selected" type="checkbox" :value="c.id" :aria-label="c.tracking_code">
            <span class="font-mono text-xs">{{ c.tracking_code }}</span>
            <span class="flex-1 truncate">{{ c.recipient_name }}</span>
            <span class="text-xs text-gray-500">{{ c.method_label }}</span>
            <span class="font-medium">{{ money(c.amount_collected) }}</span>
            <!-- Annoncé « reçu par l'entreprise » par le livreur : à retrouver sur le relevé avant de compter -->
            <button v-if="c.awaits_confirmation && canManage" type="button" class="rounded bg-blue-600 px-2 py-0.5 text-xs font-semibold text-white" :title="`Réf. ${c.transaction_ref} : vérifiez sur le relevé ${c.method_label}`" @click="confirmCollection(c)">Reçu ? Confirmer</button>
              </li>
            </ul>
          </div>
        </div>
        <p v-if="remit.collections.length && remit.selected.length < remit.collections.length" class="text-xs text-gray-600">
          Restera chez le livreur : {{ money(remit.collections.filter((c) => !remit.selected.includes(c.id)).reduce((n, c) => n + c.amount_collected, 0)) }}
        </p>
        <p v-if="remit.collections.some((c) => c.awaits_confirmation)" class="text-xs text-blue-800">
          Paiements annoncés « reçus par l'entreprise » : vérifiez la référence sur le relevé Wave ou Mobile Money, puis confirmez. Tant qu'ils ne sont pas confirmés, le livreur doit cet argent.
        </p>
        <!-- Frais déclarés par le livreur : sans effet tant qu'ils ne sont pas validés -->
        <template v-if="remit.expensesToApprove.length">
          <p class="text-sm font-semibold pt-1">🧾 Frais à valider</p>
          <ul class="border rounded divide-y text-sm">
            <li v-for="e in remit.expensesToApprove" :key="`v${e.id}`" class="flex flex-wrap items-center gap-2 p-2">
              <span class="font-mono text-xs">{{ e.tracking_code }}</span>
              <span class="flex-1">{{ e.description }}</span>
              <span class="font-medium">{{ money(e.amount) }}</span>
              <template v-if="canManage">
                <button type="button" class="rounded bg-emerald-700 px-2 py-0.5 text-xs font-semibold text-white" @click="decideExpense(e, true)">Valider</button>
                <button type="button" class="text-xs text-red-700 underline" @click="decideExpense(e, false)">Refuser</button>
              </template>
            </li>
          </ul>
        </template>
        <ul v-if="remit.advances.length || remit.expenses.length" class="border rounded divide-y text-sm">
          <li v-for="a in remit.advances" :key="`a${a.id}`" class="flex items-center gap-2 p-2">
            <span class="flex-1">{{ a.pay_kept ? '🧾' : '💵 Avance :' }} {{ a.reason }} <span class="text-xs text-gray-500">{{ dateTime(a.given_at) }}</span></span>
            <span :class="['font-medium', a.amount < 0 ? 'text-emerald-700' : '']">{{ a.amount < 0 ? '−' : '+' }} {{ money(Math.abs(a.amount)) }}</span>
            <button v-if="a.can_cancel && canManage" type="button" class="text-xs text-red-700 underline" @click="cancelAdvance(a)">Annuler</button>
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

    <!-- Grand livre d'un client -->
    <Modal :open="ledger.open" :title="`Grand livre · ${ledger.merchant?.business_name}`" @close="ledger.open = false">
      <LedgerTable :entries="ledger.entries" by-day />
      <button v-if="ledger.page < ledger.lastPage" type="button" class="btn-secondary mt-2 w-full" @click="moreLedger">Voir les lignes plus anciennes ({{ ledger.total - ledger.entries.length }})</button>
      <form v-if="canManage" class="border-t mt-4 pt-3 space-y-2" @submit.prevent="saveMerchantAdjust">
        <p class="text-sm font-medium">Ajustement (+ en faveur du client, − à sa charge)</p>
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
          method="cash"
          cancel-label="Annuler la fiche"
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
import { myActivities } from '../../utils/activities'
import { downloadCsv } from '../../components/stats/chart'
import { PERIOD_PRESETS, shift } from '../../utils/periods'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const route = useRoute()
const canManage = computed(() => auth.can('finance.manage'))

// Soldes et reversements : e-commerce ; paiements de factures : distribution et MAD
const seen = computed(() => myActivities(auth.user).map((a) => a.value))
const tabs = computed(() => [
  { value: 'cash', label: 'Argent chez les livreurs' },
  ...(seen.value.includes('ecommerce') ? [{ value: 'merchants', label: 'À payer aux clients' }, { value: 'payouts', label: 'Paiements aux clients' }] : []),
  ...(seen.value.some((a) => a !== 'ecommerce') ? [{ value: 'invoices', label: 'Paiements de factures' }] : []),
  { value: 'payroll', label: 'Paie des livreurs' },
])
const invoicePayments = reactive({ month: new Date().toISOString().slice(0, 7), data: [], total: 0, by_method: {} })
const tab = ref(route.query.tab || 'cash')

const cash = ref(null)
const days = ref(null)
const remittances = ref([])

// Date de livraison des courses (onglets livreurs et e-commerçants), gardée dans l'adresse
const PERIODS = [
  { key: 'today', label: 'Aujourd\'hui' }, { key: 'yesterday', label: 'Hier' }, { key: 'week', label: '7 jours' },
  { key: 'month', label: 'Ce mois' }, { key: 'all', label: 'Tout' }, { key: 'custom', label: 'Choisir…' },
]
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/
const period = reactive(DATE_RE.test(route.query.du || '')
  ? { key: 'custom', from: route.query.du, to: DATE_RE.test(route.query.au || '') ? route.query.au : route.query.du }
  : { key: PERIOD_PRESETS[route.query.periode] ? route.query.periode : 'all', from: '', to: '' })
if (period.key !== 'custom') Object.assign(period, { from: PERIOD_PRESETS[period.key].from(), to: PERIOD_PRESETS[period.key].to() })
const shortDay = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
const dayLabel = (d) => `${new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })}${d === shift(0) ? ' (aujourd\'hui)' : ''}`
const periodLabel = computed(() => {
  if (period.key === 'all') return 'toutes les dates'
  if (period.from === period.to) return `du ${dayLabel(period.from)}`
  return `du ${shortDay(period.from)} au ${shortDay(period.to)}`
})
const payLabel = computed(() => (period.from === period.to ? `le ${shortDay(period.from)}` : 'la période'))
const periodParams = () => (period.key === 'all' ? {} : { from: period.from, to: period.to })
const inPeriod = (d) => period.key === 'all' || (d >= period.from && d <= period.to)
const periodAmount = (c) => c.days.filter((d) => inPeriod(d.day)).reduce((n, d) => n + d.amount, 0)

function syncPeriodQuery() {
  const query = { ...route.query }
  delete query.periode; delete query.du; delete query.au
  if (period.key === 'custom') Object.assign(query, { du: period.from, au: period.to })
  else if (period.key !== 'all') query.periode = period.key
  router.replace({ query })
}
function choosePeriod(key) {
  period.key = key
  if (key !== 'custom') Object.assign(period, { from: PERIOD_PRESETS[key].from(), to: PERIOD_PRESETS[key].to() })
  else if (!period.from) Object.assign(period, { from: shift(0), to: shift(0) })
  periodChanged()
}
function chooseDay(day) {
  Object.assign(period, { key: 'custom', from: day, to: day })
  periodChanged()
}
function periodChanged() {
  if (period.to < period.from) period.to = period.from
  syncPeriodQuery()
  load()
}

// Encaissements de la fenêtre « Recevoir l'argent », par date de livraison
const remitDays = computed(() => {
  const groups = {}
  for (const c of remit.collections) {
    const g = (groups[c.delivery_day || ''] ??= { day: c.delivery_day || null, items: [], total: 0, selected: 0 })
    g.items.push(c)
    g.total += c.amount_collected
    if (remit.selected.includes(c.id)) g.selected++
  }
  return Object.values(groups).sort((a, b) => (a.day || '').localeCompare(b.day || ''))
})
function toggleRemitDay(group, on) {
  const ids = group.items.map((c) => c.id)
  remit.selected = on ? [...new Set([...remit.selected, ...ids])] : remit.selected.filter((id) => !ids.includes(id))
}
const merchantBalances = ref([])
const payouts = ref([])
const courierPayouts = ref([])

// Recherche et filtres (dans la page) ; « à surveiller » ouvert depuis l'alerte
const filters = reactive({ search: '', withMoney: true, watchOnly: route.query.surveiller === '1', merchant: '', allMerchants: false, payout: '', payoutStatus: '' })
const norm = (v) => (v || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
const visibleCouriers = computed(() => (cash.value?.data || []).filter((c) => (!filters.withMoney || c.cash_in_hand !== 0 || c.parcels_in_hand > 0 || c.pending_collections > 0)
  && (!filters.watchOnly || c.watch)
  && (!filters.search || norm(`${c.name} ${c.phone}`).includes(norm(filters.search)))))
const visibleMerchants = computed(() => merchantBalances.value.filter((m) => !filters.merchant || norm(m.business_name).includes(norm(filters.merchant))))
const visiblePayouts = computed(() => payouts.value.filter((p) => !filters.payout || norm(`${p.reference} ${p.merchant.business_name}`).includes(norm(filters.payout))))
const alertText = computed(() => {
  const a = cash.value?.alert
  if (!a) return ''
  const parts = [a.hours ? `argent gardé plus de ${a.hours} h` : '', a.amount ? `au-dessus de ${money(a.amount)}` : ''].filter(Boolean)
  return parts.length ? parts.join(' ou ') : 'alerte désactivée (Réglages › Alertes)'
})
const today = new Date().toISOString().slice(0, 10)
const remitFilter = reactive({ from: today, to: today, basis: 'received' })

const remit = reactive({ open: false, courier: null, collections: [], advances: [], expenses: [], expensesToApprove: [], parcels: [], returned: [], selected: [], amount_received: 0, notes: '', error: '' })
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
const ledger = reactive({ open: false, merchant: null, entries: [], page: 1, lastPage: 1, total: 0, adjust: { amount: null, description: '' }, error: '' })
const adjust = reactive({ open: false, courier: null, amount: null, description: '', error: '' })
const courierPayout = reactive({ open: false, data: null })

// Paiements groupés
const PAY_METHODS = [
  { value: 'cash', label: 'Espèces' }, { value: 'wave', label: 'Wave' }, { value: 'orange_money', label: 'Orange Money' },
  { value: 'mtn_momo', label: 'MTN MoMo' }, { value: 'moov_money', label: 'Moov Money' }, { value: 'bank', label: 'Virement bancaire' },
]
const batch = reactive({ merchants: [], payslips: [], payslipMethod: 'cash', busy: false })
const batchPay = reactive({ open: false, rows: [], error: '' })
const merchantPayable = (m) => m.available > 0 && (!m.payment_method || m.payment_method.status === 'verified')
const payableMerchants = computed(() => visibleMerchants.value.filter(merchantPayable))
const selectedMerchantsTotal = computed(() => merchantBalances.value.filter((m) => batch.merchants.includes(m.merchant_id)).reduce((s, m) => s + m.available, 0))
const draftPayouts = computed(() => payouts.value.filter((p) => p.status === 'draft'))
const batchPaySelected = computed(() => batchPay.rows.filter((r) => r.selected))
const draftPayslips = computed(() => courierPayouts.value.filter((p) => p.status === 'draft'))
const selectedPayslipsTotal = computed(() => draftPayslips.value.filter((p) => batch.payslips.includes(p.id)).reduce((s, p) => s + p.amount, 0))
const holding = (payslip) => Math.max(0, (cash.value?.data || []).find((c) => c.courier_id === payslip.courier_id)?.cash_in_hand || 0)

function toggleAllMerchants(on) {
  batch.merchants = on ? payableMerchants.value.map((m) => m.merchant_id) : []
}
function toggleAllPayslips(on) {
  batch.payslips = on ? draftPayslips.value.map((p) => p.id) : []
}
function reportBatch(done, errors, what) {
  const failed = Object.entries(errors || {})
  if (done) toasts.success(`${done} ${what}.`)
  failed.forEach(([who, why]) => toasts.error(`${who} : ${why}`))
}

async function prepareSelected() {
  batch.busy = true
  try {
    const { data } = await http.post('/finance/payouts/batch', { merchant_ids: batch.merchants, ...periodParams() })
    reportBatch(data.data.length, data.errors, data.data.length > 1 ? 'paiements préparés' : 'paiement préparé')
    batch.merchants = []
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    batch.busy = false
  }
}

function openBatchPay() {
  batchPay.rows = draftPayouts.value.map((p) => ({
    id: p.id, reference: p.reference, merchant: p.merchant.business_name, amount: p.net_amount, selected: true,
    method: p.pay_with?.method || 'cash', summary: p.pay_with?.summary, verified: p.pay_with?.verified ?? true, transaction_ref: '',
  }))
  Object.assign(batchPay, { open: true, error: '' })
}

async function saveBatchPay() {
  batch.busy = true
  batchPay.error = ''
  try {
    const { data } = await http.post('/finance/payouts/pay-batch', {
      payouts: batchPaySelected.value.map((r) => ({ id: r.id, method: r.method, transaction_ref: r.transaction_ref || null })),
    })
    reportBatch(data.data.length, data.errors, data.data.length > 1 ? 'paiements marqués payés' : 'paiement marqué payé')
    batchPay.open = false
    await load()
  } catch (e) {
    batchPay.error = apiErrorMessage(e)
  } finally {
    batch.busy = false
  }
}

async function paySelectedPayslips() {
  const label = PAY_METHODS.find((m) => m.value === batch.payslipMethod)?.label
  if (!window.confirm(`Payer ${batch.payslips.length} fiche(s) pour ${money(selectedPayslipsTotal.value)} en ${label} ?`)) return
  batch.busy = true
  try {
    const { data } = await http.post('/finance/courier-payouts/pay-batch', { ids: batch.payslips, method: batch.payslipMethod })
    reportBatch(data.data.length, data.errors, data.data.length > 1 ? 'fiches payées' : 'fiche payée')
    batch.payslips = []
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    batch.busy = false
  }
}
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
  router.replace({ query: { ...route.query, tab: value } })
  load()
}

async function load() {
  if (tab.value === 'cash' || tab.value === 'payroll') {
    cash.value = (await http.get('/finance/cash')).data
  }
  if (tab.value === 'cash') {
    // « Tout » : le point des 31 derniers jours
    const range = period.key === 'all' ? { from: shift(-30), to: shift(0) } : { from: period.from, to: period.to }
    days.value = (await http.get('/finance/cash/days', { params: range }).catch(() => ({ data: { data: null } }))).data.data
    await loadRemittances()
  }
  if (tab.value === 'merchants') merchantBalances.value = (await http.get('/finance/merchants', { params: { ...(filters.allMerchants ? { all: 1 } : {}), ...periodParams() } })).data.data
  if (tab.value === 'payouts') payouts.value = (await http.get('/finance/payouts', { params: filters.payoutStatus ? { status: filters.payoutStatus } : {} })).data.data
  if (tab.value === 'payroll') courierPayouts.value = (await http.get('/finance/courier-payouts')).data.data
  if (tab.value === 'invoices') Object.assign(invoicePayments, (await http.get('/finance/invoice-payments', { params: { month: invoicePayments.month } })).data)
}

async function loadRemittances() {
  remittances.value = (await http.get('/finance/remittances', { params: { from: remitFilter.from || undefined, to: remitFilter.to || undefined, date_basis: remitFilter.basis } })).data.data
}

function exportCash() {
  downloadCsv('argent-chez-les-livreurs.csv', [
    ['Livreur', 'Téléphone', 'À remettre (F)', 'Encaissé (F)', 'Avances (F)', 'Frais (F)', 'Colis', 'Depuis', 'À surveiller'],
    ...visibleCouriers.value.map((c) => [c.name, c.phone, c.cash_in_hand, c.collected, c.advances, c.expenses, c.parcels_in_hand, c.oldest_collected_at ? dateTime(c.oldest_collected_at) : '', c.watch || '']),
  ])
}

function exportDays() {
  if (!days.value) return
  const t = days.value.totals
  downloadCsv(`point-caisse-${days.value.from}-${days.value.to}.csv`, [
    ['Date de livraison', 'Livrées', 'Encaissé (F)', 'Déjà à la caisse (F)', 'Chez les livreurs (F)', 'Frais de livraison (F)', 'À reverser (F)'],
    ...days.value.days.map((d) => [d.day, d.delivered, d.collected, d.at_desk, d.with_couriers, d.fees, d.net_to_merchants]),
    ['Total', t.delivered, t.collected, t.at_desk, t.with_couriers, t.fees, t.net_to_merchants],
  ])
}

function exportRemittances() {
  downloadCsv(`argent-recu-${remitFilter.from}-${remitFilter.to}.csv`, [
    ['Date', 'Reçu', 'Livreur', 'Attendu (F)', 'Reçu (F)', 'Écart (F)', 'Colis rendus', 'Colis gardés', 'Reçu par', 'Note', 'Annulé'],
    ...remittances.value.map((r) => [dateTime(r.received_at), r.reference, r.courier_name, r.amount_expected, r.amount_received, r.difference,
      r.parcels_returned.length, r.parcels_kept.length, r.received_by, r.notes || '', r.cancelled_at ? `oui : ${r.cancel_reason}` : '']),
  ])
}

function exportMerchants() {
  downloadCsv('a-payer-aux-e-commercants.csv', [
    ['Client', 'Disponible (F)', 'En attente (F)', 'Total à payer (F)', 'Payer avec'],
    ...visibleMerchants.value.map((m) => [m.business_name, m.available, m.pending_cash, m.unpaid_total, m.payment_method?.summary || '']),
  ])
}

function exportPayouts() {
  downloadCsv('paiements-aux-e-commercants.csv', [
    ['Référence', 'Client', 'Du', 'Au', 'Net (F)', 'Statut', 'Payé le', 'Moyen'],
    ...visiblePayouts.value.map((p) => [p.reference, p.merchant.business_name, date(p.period_start), date(p.period_end), p.net_amount, p.status_label, p.paid_at ? dateTime(p.paid_at) : '', p.method_label || '']),
  ])
}

async function cancelAdvance(a) {
  const reason = window.prompt(`Annuler l'avance « ${a.reason} » de ${money(a.amount)} ? Raison :`)?.trim()
  if (!reason) return
  try {
    await http.post(`/finance/advances/${a.id}/cancel`, { reason })
    toasts.success('Avance annulée.')
    await openRemit(remit.courier)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function openRemit(courier) {
  const { data } = await http.get(`/finance/couriers/${courier.courier_id}/collections`)
  Object.assign(remit, {
    open: true,
    courier,
    collections: data.data,
    advances: data.advances || [],
    expenses: data.expenses || [],
    expensesToApprove: data.expenses_to_approve || [],
    parcels: data.parcels || [],
    returned: [],
    // Caisse filtrée sur des dates : seules les courses de ces dates sont cochées au départ
    selected: data.data.filter((c) => !c.delivery_day || inPeriod(c.delivery_day)).map((c) => c.id),
    amount_received: 0,
    notes: '',
    error: '',
  })
  remit.amount_received = Math.abs(remitExpected.value)
}

// Paiement Wave ou Mobile Money retrouvé sur le relevé : il n'est plus dû par le livreur
async function confirmCollection(c) {
  if (!confirm(`Confirmer que ${money(c.amount_collected)} (${c.method_label}, réf. ${c.transaction_ref}) est bien arrivé sur le compte de l'entreprise ?`)) return
  try {
    await http.post(`/finance/collections/${c.id}/confirm`)
    toasts.success('Paiement confirmé.')
    await openRemit(remit.courier)
    load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

// Frais déclarés par le livreur : validés (déduits de son versement, facturés au marchand) ou refusés
async function decideExpense(e, approve) {
  if (!approve && !confirm(`Refuser les frais « ${e.description} » (${money(e.amount)}) ?`)) return
  try {
    await http.post(`/orders/${e.order_id}/expenses/${e.id}/${approve ? 'approve' : 'cancel'}`)
    toasts.success(approve ? 'Frais validés.' : 'Frais refusés.')
    await openRemit(remit.courier)
    load()
  } catch (err) {
    toasts.error(apiErrorMessage(err))
  }
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
  const { data } = await http.get(`/finance/merchants/${merchant.merchant_id}/ledger`, { params: periodParams() })
  Object.assign(ledger, { entries: data.data, page: data.meta.current_page, lastPage: data.meta.last_page, total: data.meta.total, open: true })
}

async function moreLedger() {
  const { data } = await http.get(`/finance/merchants/${ledger.merchant.merchant_id}/ledger`, { params: { page: ledger.page + 1, ...periodParams() } })
  ledger.entries = [...ledger.entries, ...data.data]
  ledger.page = data.meta.current_page
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
    const { data } = await http.post('/finance/payouts', { merchant_id: merchant.merchant_id, ...periodParams() })
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
  if (!window.confirm(`Annuler la fiche de paie ${courierPayout.data.reference} ? Les gains reviennent dans « Gains non payés ».`)) return
  await http.post(`/finance/courier-payouts/${courierPayout.data.id}/cancel`)
  courierPayout.open = false
  load()
}

onMounted(load)
</script>
