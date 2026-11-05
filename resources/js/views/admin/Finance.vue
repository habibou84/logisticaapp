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

    <!-- Argent chez les livreurs -->
    <template v-if="tab === 'cash'">
      <p v-if="cash?.whatsapp_templates === 'pending'" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900" role="status">
        Reçu de versement et rappel aux livreurs par WhatsApp : messages envoyés à Meta pour acceptation (de quelques minutes à 24 h). Ils partiront tout seuls ensuite.
      </p>
      <p v-else-if="cash?.whatsapp_templates === 'rejected'" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900" role="alert">
        Meta a refusé un message WhatsApp de la caisse (reçu ou rappel). Voir la page <RouterLink to="/admin/whatsapp" class="underline font-medium">WhatsApp</RouterLink>.
      </p>

      <!-- 1. En ce moment : toutes dates confondues, ne dépend pas de la date choisie -->
      <section v-if="cash" class="space-y-2">
        <div class="flex flex-wrap items-baseline gap-x-2">
          <h2 class="font-semibold">💼 En ce moment</h2>
          <span class="text-xs text-gray-500">toutes dates confondues · ne dépend pas de la date choisie plus bas</span>
        </div>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div class="card p-4">
            <p class="text-sm text-gray-600">🛵 Total chez les livreurs</p>
            <p class="text-2xl font-bold tabular-nums">{{ money(cash.totals.cash_in_hands) }}</p>
            <p class="text-xs text-gray-500">toutes dates de livraison · {{ couriersWithMoney }} livreur{{ couriersWithMoney > 1 ? 's' : '' }}</p>
            <p v-if="cash.totals.owed_to_couriers" class="text-xs text-violet-700">La caisse leur doit {{ money(cash.totals.owed_to_couriers) }} (à part)</p>
          </div>
          <div class="card p-4">
            <p class="text-sm text-gray-600">💵 Encaissé aujourd'hui</p>
            <p class="text-2xl font-bold tabular-nums">{{ money(cash.totals.collected_today) }}</p>
            <p class="text-xs text-gray-500">chez les clients, depuis minuit</p>
          </div>
          <div class="card p-4">
            <p class="text-sm text-gray-600">🏦 Reçu des livreurs aujourd'hui</p>
            <p class="text-2xl font-bold tabular-nums">{{ money(cash.totals.received_today) }}</p>
            <p class="text-xs text-gray-500">versements à la caisse</p>
          </div>
          <button type="button" :class="['card p-4 text-left', cash.totals.watched ? 'border-orange-300 bg-orange-50' : '']" @click="filters.watchOnly = !filters.watchOnly">
            <p :class="['text-sm', cash.totals.watched ? 'text-orange-800' : 'text-gray-600']">{{ cash.totals.watched ? '⚠️ ' : '' }}À surveiller</p>
            <p :class="['text-2xl font-bold', cash.totals.watched ? 'text-orange-800' : '']">{{ cash.totals.watched }} livreur{{ cash.totals.watched > 1 ? 's' : '' }}</p>
            <p :class="['text-xs', cash.totals.watched ? 'text-orange-800' : 'text-gray-500']">{{ alertText }}</p>
          </button>
        </div>
      </section>

      <!-- 2. Point par date de livraison : le choix de la date est juste au-dessus de ses chiffres -->
      <section class="card">
        <div class="space-y-3 border-b p-4">
          <div class="flex flex-wrap items-center gap-2">
            <h2 class="flex-1 font-semibold">📊 Point par date de livraison</h2>
            <button v-if="days" type="button" class="btn-secondary" @click="exportDays">Exporter</button>
          </div>
          <div class="rounded-xl bg-slate-50 p-2">
            <DeliveryDatePicker :period="period" :periods="PERIODS" label="Courses livrées" :summary="periodLabel" @choose="choosePeriod" @changed="periodChanged" />
          </div>
          <p class="text-sm">
            <span class="font-semibold text-slate-800">📅 Date choisie : <span class="text-amber-700">{{ period.key === 'all' ? 'toutes les dates' : chosenDate }}</span></span>
            <span class="block text-xs text-gray-500">S'applique au point ci-dessous et à la liste des livreurs.{{ period.key === 'all' ? ' « Tout » : 31 derniers jours pour le point.' : '' }}</span>
          </p>
        </div>
        <template v-if="days">
          <div class="grid grid-cols-2 gap-3 p-4 lg:grid-cols-4">
            <div class="rounded-xl bg-slate-50 p-3">
              <p class="text-xs text-gray-500">Encaissé chez les clients</p>
              <p class="text-xl font-bold tabular-nums">{{ money(days.totals.collected) }}</p>
              <p class="text-xs text-gray-500">{{ days.totals.delivered }} course{{ days.totals.delivered > 1 ? 's' : '' }} livrée{{ days.totals.delivered > 1 ? 's' : '' }}</p>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
              <p class="text-xs text-gray-500">Déjà à la caisse</p>
              <p class="text-xl font-bold tabular-nums text-emerald-700">{{ money(days.totals.at_desk) }}</p>
              <p class="text-xs text-gray-500">reçu des livreurs</p>
            </div>
            <div :class="['rounded-xl p-3', days.totals.with_couriers ? 'bg-amber-50' : 'bg-slate-50']">
              <p :class="['text-xs', days.totals.with_couriers ? 'text-amber-800' : 'text-gray-500']">Encore chez les livreurs</p>
              <p :class="['text-xl font-bold tabular-nums', days.totals.with_couriers ? 'text-amber-800' : '']">{{ money(days.totals.with_couriers) }}</p>
              <p :class="['text-xs', days.totals.with_couriers ? 'text-amber-800' : 'text-emerald-700']">{{ days.totals.with_couriers ? 'à recevoir' : '✓ tout est arrivé' }}</p>
            </div>
            <div class="rounded-xl bg-slate-50 p-3">
              <p class="text-xs text-gray-500">À reverser aux clients</p>
              <p class="text-xl font-bold tabular-nums">{{ money(days.totals.net_to_merchants) }}</p>
              <p class="text-xs text-gray-500">après {{ money(days.totals.fees) }} de frais</p>
            </div>
          </div>
          <!-- Une ligne par jour quand plusieurs dates sont choisies -->
          <table v-if="days.days.length > 1" class="w-full text-sm stack-table">
            <thead class="bg-slate-50 text-left text-gray-600">
              <tr><th class="p-2">Date de livraison</th><th class="p-2 text-right">Livrées</th><th class="p-2 text-right">Encaissé</th><th class="p-2 text-right">Déjà à la caisse</th><th class="p-2 text-right">Chez les livreurs</th><th class="p-2 text-right">Frais de livraison</th><th class="p-2 text-right">À reverser</th></tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="d in days.days" :key="d.day" :class="d.with_couriers ? 'bg-amber-50/60' : ''">
                <td class="p-2"><button type="button" class="font-medium hover:underline first-letter:uppercase" title="Voir seulement ce jour" @click="chooseDay(d.day)">{{ dayLabel(d.day) }} ›</button></td>
                <td data-label="Livrées" class="p-2 text-right">{{ d.delivered }}</td>
                <td data-label="Encaissé" class="p-2 text-right tabular-nums">{{ money(d.collected) }}</td>
                <td data-label="Déjà à la caisse" class="p-2 text-right tabular-nums">{{ money(d.at_desk) }}</td>
                <td data-label="Chez les livreurs" :class="['p-2 text-right tabular-nums', d.with_couriers ? 'font-semibold text-amber-700' : 'text-emerald-700']">{{ d.with_couriers ? money(d.with_couriers) : '✓ 0' }}</td>
                <td data-label="Frais" class="p-2 text-right tabular-nums">{{ money(d.fees) }}</td>
                <td data-label="À reverser" class="p-2 text-right tabular-nums">{{ money(d.net_to_merchants) }}</td>
              </tr>
              <tr class="bg-slate-100 font-bold">
                <td class="p-2">Total</td><td data-label="Livrées" class="p-2 text-right">{{ days.totals.delivered }}</td><td data-label="Encaissé" class="p-2 text-right">{{ money(days.totals.collected) }}</td>
                <td data-label="Déjà à la caisse" class="p-2 text-right">{{ money(days.totals.at_desk) }}</td><td data-label="Chez les livreurs" class="p-2 text-right text-amber-700">{{ money(days.totals.with_couriers) }}</td>
                <td data-label="Frais" class="p-2 text-right">{{ money(days.totals.fees) }}</td><td data-label="À reverser" class="p-2 text-right">{{ money(days.totals.net_to_merchants) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else-if="!days.days.length" class="px-4 pb-4 text-sm text-gray-500">Aucune course livrée à ces dates.</p>
        </template>
      </section>

      <!-- 3. Argent par livreur : une ligne simple, le détail par date en dépliant -->
      <section class="card">
        <div class="flex flex-wrap items-center gap-2 border-b p-4">
          <h2 class="basis-full font-semibold sm:basis-auto sm:flex-1">🛵 Argent par livreur <span class="text-sm font-normal text-gray-500">· {{ period.key === 'all' ? 'toutes dates' : `courses livrées ${periodLabel}` }}</span></h2>
          <input id="cash-search" v-model="filters.search" type="search" class="input w-full sm:!w-56" placeholder="🔍 Nom ou téléphone" aria-label="Chercher un livreur">
          <button type="button" :class="['rounded-full px-3 py-1 text-xs ring-1', filters.withMoney ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']" :aria-pressed="filters.withMoney" @click="filters.withMoney = !filters.withMoney">Avec argent ou colis</button>
          <button type="button" :class="['rounded-full px-3 py-1 text-xs ring-1', filters.watchOnly ? 'bg-orange-600 text-white ring-orange-600' : 'bg-white ring-slate-300']" :aria-pressed="filters.watchOnly" @click="filters.watchOnly = !filters.watchOnly">À surveiller</button>
          <button type="button" class="btn-secondary" @click="exportCash">Exporter</button>
        </div>

        <!-- Ordinateur : tableau -->
        <table class="hidden w-full text-sm md:table">
          <thead class="border-b-2 border-slate-300 bg-slate-100 text-left text-sm font-bold text-slate-800">
            <tr>
              <th class="p-3">Livreur</th>
              <th v-if="period.key !== 'all'" class="p-3 text-right">Date choisie<span class="block text-xs font-semibold text-amber-700 first-letter:uppercase">{{ chosenDate }}</span></th>
              <th class="p-3 text-right" title="Encaissé + avances − frais payés">Total à remettre<span class="block text-xs font-normal text-slate-500">toutes dates</span></th>
              <th class="hidden p-3 xl:table-cell">Colis</th>
              <th class="hidden p-3 xl:table-cell">Garde l'argent depuis</th>
              <th class="p-3" />
            </tr>
          </thead>
          <tbody class="divide-y">
            <template v-for="c in visibleCouriers" :key="c.courier_id">
              <tr :class="['cursor-pointer align-top hover:bg-slate-50', c.watch ? 'bg-orange-50/60' : '']" @click="toggleCourier(c)">
                <td class="p-3">
                  <p class="font-semibold"><span class="inline-block w-4 text-gray-400">{{ opened.has(c.courier_id) ? '▾' : '▸' }}</span>{{ c.name }}</p>
                  <p class="pl-4 text-xs text-gray-500">{{ c.phone }}</p>
                  <p v-if="c.watch" class="ml-4 mt-1 inline-block rounded bg-orange-100 px-1.5 py-0.5 text-xs font-medium text-orange-800">⚠️ {{ c.watch }}</p>
                </td>
                <td v-if="period.key !== 'all'" class="p-3 text-right">
                  <template v-if="periodAmount(c)"><p class="font-semibold tabular-nums">{{ money(periodAmount(c)) }}</p><p class="text-xs text-gray-500">{{ countLabel(periodCount(c)) }}</p></template>
                  <template v-else><p class="text-gray-300">—</p><p class="text-xs text-gray-400">rien à ces dates</p></template>
                </td>
                <td class="p-3 text-right">
                  <template v-if="c.cash_in_hand < 0"><p class="font-semibold text-violet-700">La caisse lui doit {{ money(-c.cash_in_hand) }}</p><p class="text-xs text-gray-500">frais payés de sa poche</p></template>
                  <template v-else><p class="text-lg font-bold tabular-nums">{{ money(c.cash_in_hand) }}</p><p class="text-xs text-gray-500">{{ spreadLabel(c) }}</p></template>
                </td>
                <td class="hidden p-3 xl:table-cell"><span v-if="c.parcels_in_hand" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">📦 {{ c.parcels_in_hand }}</span><span v-else class="text-gray-300">—</span></td>
                <td class="hidden p-3 text-xs text-gray-600 xl:table-cell">{{ c.oldest_collected_at ? dateTime(c.oldest_collected_at) : '—' }}</td>
                <td class="whitespace-nowrap p-3 text-right" @click.stop>
                  <template v-if="canManage">
                    <button v-if="c.cash_in_hand < 0" type="button" class="rounded-lg border border-violet-400 px-3 py-1.5 text-violet-700" @click="openRemit(c)">Rembourser</button>
                    <button v-else-if="c.pending_collections || c.advances || c.expenses" type="button" class="btn-primary" @click="openRemit(c)">Recevoir</button>
                    <button v-else-if="c.parcels_in_hand" type="button" class="btn-primary" @click="openRemit(c)">Recevoir les colis</button>
                  </template>
                </td>
              </tr>
              <tr v-if="opened.has(c.courier_id)" :class="c.watch ? 'bg-orange-50/60' : 'bg-slate-50/60'">
                <td :colspan="period.key !== 'all' ? 6 : 5" class="px-3 pb-4">
                  <CourierCashDetail :courier="c" :in-period="inPeriod" :period-key="period.key" :period-label="periodLabel" :can-manage="canManage" :short-day="dayLabel" @remit-all="openRemit(c, true)" @advance="openAdvance(c)" />
                </td>
              </tr>
            </template>
            <tr v-if="cash && !visibleCouriers.length"><td :colspan="period.key !== 'all' ? 6 : 5" class="p-4 text-center text-gray-500">Aucun livreur à afficher.</td></tr>
            <tr v-else-if="visibleCouriers.length > 1" class="bg-slate-100 font-semibold">
              <td class="p-3">Total · {{ visibleCouriers.length }} livreurs</td>
              <td v-if="period.key !== 'all'" class="p-3 text-right tabular-nums">{{ money(visibleTotals.period) }}</td>
              <td class="p-3 text-right tabular-nums">{{ money(visibleTotals.due) }}</td>
              <td class="hidden xl:table-cell" /><td class="hidden xl:table-cell" /><td />
            </tr>
          </tbody>
        </table>

        <!-- Téléphone : une carte par livreur -->
        <div class="divide-y md:hidden">
          <div v-for="c in visibleCouriers" :key="c.courier_id" :class="['space-y-2 p-4', c.watch ? 'bg-orange-50/60' : '']">
            <button type="button" class="flex w-full items-start gap-2 text-left" @click="toggleCourier(c)">
              <span class="min-w-0 flex-1">
                <span class="block font-semibold">{{ c.name }}</span>
                <span class="block text-xs text-gray-500">{{ c.phone }}<template v-if="c.parcels_in_hand"> · 📦 {{ c.parcels_in_hand }} colis</template></span>
              </span>
              <span class="text-right">
                <template v-if="c.cash_in_hand < 0"><span class="block font-semibold text-violet-700">La caisse lui doit {{ money(-c.cash_in_hand) }}</span></template>
                <template v-else><span class="block text-lg font-bold tabular-nums">{{ money(c.cash_in_hand) }}</span><span class="block text-xs text-gray-500">total, {{ spreadLabel(c) }}</span></template>
              </span>
            </button>
            <p v-if="c.watch" class="inline-block rounded bg-orange-100 px-1.5 py-0.5 text-xs font-medium text-orange-800">⚠️ {{ c.watch }}</p>
            <div v-if="period.key !== 'all' && periodAmount(c)" class="flex justify-between rounded-lg bg-amber-50 px-3 py-2 text-sm">
              <span class="first-letter:uppercase">{{ periodLabel }} · {{ countLabel(periodCount(c)) }}</span><span class="font-semibold tabular-nums">{{ money(periodAmount(c)) }}</span>
            </div>
            <CourierCashDetail v-if="opened.has(c.courier_id)" :courier="c" :in-period="inPeriod" :period-key="period.key" :period-label="periodLabel" :can-manage="canManage" :short-day="dayLabel" @remit-all="openRemit(c, true)" @advance="openAdvance(c)" />
            <button v-else type="button" class="text-xs text-gray-600" @click="toggleCourier(c)">▸ Détail par date</button>
            <div v-if="canManage" class="flex gap-2">
              <button v-if="c.cash_in_hand < 0" type="button" class="flex-1 rounded-lg border border-violet-400 py-2 text-violet-700" @click="openRemit(c)">Rembourser</button>
              <button v-else-if="c.pending_collections || c.advances || c.expenses" type="button" class="btn-primary flex-1" @click="openRemit(c)">Recevoir</button>
              <button v-else-if="c.parcels_in_hand" type="button" class="btn-primary flex-1" @click="openRemit(c)">Recevoir les colis</button>
            </div>
          </div>
          <p v-if="cash && !visibleCouriers.length" class="p-4 text-center text-sm text-gray-500">Aucun livreur à afficher.</p>
        </div>
      </section>

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

    <!-- À payer aux clients : même organisation que l'argent chez les livreurs -->
    <template v-if="tab === 'merchants'">
      <!-- 1. En ce moment : toutes dates confondues -->
      <section class="space-y-2">
        <div class="flex flex-wrap items-baseline gap-x-2">
          <h2 class="font-semibold">💼 En ce moment</h2>
          <span class="text-xs text-gray-500">toutes dates confondues · ne dépend pas de la date choisie plus bas</span>
        </div>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div class="card p-4">
            <p class="text-sm text-gray-600">🏪 Total à payer</p>
            <p class="text-2xl font-bold tabular-nums">{{ money(merchantTotals.unpaid) }}</p>
            <p class="text-xs text-gray-500">toutes dates · {{ merchantTotals.count }} client{{ merchantTotals.count > 1 ? 's' : '' }}</p>
          </div>
          <div class="card p-4">
            <p class="text-sm text-gray-600">🏦 Prêt à payer</p>
            <p class="text-2xl font-bold tabular-nums text-emerald-700">{{ money(merchantTotals.available) }}</p>
            <p class="text-xs text-gray-500">argent déjà à la caisse</p>
          </div>
          <div class="card p-4">
            <p class="text-sm text-gray-600">🛵 En attente</p>
            <p class="text-2xl font-bold tabular-nums text-amber-700">{{ money(merchantTotals.pending) }}</p>
            <p class="text-xs text-gray-500">encore chez les livreurs</p>
          </div>
          <div :class="['card p-4', merchantTotals.unverified ? 'border-red-200 bg-red-50' : '']">
            <p :class="['text-sm', merchantTotals.unverified ? 'text-red-800' : 'text-gray-600']">{{ merchantTotals.unverified ? '⚠️ ' : '' }}Sans moyen vérifié</p>
            <p :class="['text-2xl font-bold', merchantTotals.unverified ? 'text-red-800' : '']">{{ merchantTotals.unverified }} client{{ merchantTotals.unverified > 1 ? 's' : '' }}</p>
            <p :class="['text-xs', merchantTotals.unverified ? 'text-red-800' : 'text-gray-500']">à vérifier avant de payer</p>
          </div>
        </div>
      </section>

      <!-- 2. Point par date de livraison -->
      <section class="card">
        <div class="space-y-3 border-b p-4">
          <div class="flex flex-wrap items-center gap-2">
            <h2 class="flex-1 font-semibold">📊 Point par date de livraison</h2>
            <button type="button" class="btn-secondary" @click="exportMerchants">Exporter</button>
          </div>
          <div class="rounded-xl bg-slate-50 p-2">
            <DeliveryDatePicker :period="period" :periods="PERIODS" label="Courses livrées" :summary="periodLabel" @choose="choosePeriod" @changed="periodChanged" />
          </div>
          <p class="text-sm">
            <span class="font-semibold text-slate-800">📅 Date choisie : <span class="text-amber-700">{{ period.key === 'all' ? 'toutes les dates' : chosenDate }}</span></span>
            <span class="block text-xs text-gray-500">{{ period.key === 'all' ? '« Payer » paie tout ce qui est arrivé à la caisse.' : '« Payer » ne paie que les courses de cette date ; « Payer tout » dans le détail.' }}</span>
          </p>
        </div>
        <div class="grid grid-cols-2 gap-3 p-4 lg:grid-cols-4">
          <div class="rounded-xl bg-slate-50 p-3">
            <p class="text-xs text-gray-500">Encaissé chez les clients</p>
            <p class="text-xl font-bold tabular-nums">{{ days ? money(days.totals.collected) : '—' }}</p>
            <p class="text-xs text-gray-500">{{ days ? `${days.totals.delivered} course${days.totals.delivered > 1 ? 's' : ''} livrée${days.totals.delivered > 1 ? 's' : ''}` : '' }}</p>
          </div>
          <div class="rounded-xl bg-slate-50 p-3">
            <p class="text-xs text-gray-500">Frais de livraison</p>
            <p class="text-xl font-bold tabular-nums">{{ days ? money(-days.totals.fees) : '—' }}</p>
            <p class="text-xs text-gray-500">gardés par l'entreprise</p>
          </div>
          <div class="rounded-xl bg-emerald-50 p-3">
            <p class="text-xs text-emerald-800">Prêt à payer</p>
            <p class="text-xl font-bold tabular-nums text-emerald-700">{{ money(merchantPeriod.available) }}</p>
            <p class="text-xs text-emerald-800">argent à la caisse</p>
          </div>
          <div :class="['rounded-xl p-3', merchantPeriod.pending ? 'bg-amber-50' : 'bg-slate-50']">
            <p :class="['text-xs', merchantPeriod.pending ? 'text-amber-800' : 'text-gray-500']">En attente</p>
            <p :class="['text-xl font-bold tabular-nums', merchantPeriod.pending ? 'text-amber-800' : '']">{{ money(merchantPeriod.pending) }}</p>
            <p :class="['text-xs', merchantPeriod.pending ? 'text-amber-800' : 'text-gray-500']">chez les livreurs</p>
          </div>
        </div>
      </section>

      <!-- 3. À payer par client -->
      <section class="card">
        <div class="flex flex-wrap items-center gap-2 border-b p-4">
          <h2 class="basis-full font-semibold sm:basis-auto sm:flex-1">🏪 À payer par client <span class="text-sm font-normal text-gray-500">· {{ period.key === 'all' ? 'toutes dates' : `courses livrées ${periodLabel}` }}</span></h2>
          <input id="merchant-search" v-model="filters.merchant" type="search" class="input w-full sm:!w-56" placeholder="🔍 Nom de la boutique" aria-label="Chercher un client">
          <button type="button" :class="['rounded-full px-3 py-1 text-xs ring-1', !filters.allMerchants ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']" :aria-pressed="!filters.allMerchants" @click="filters.allMerchants = !filters.allMerchants; load()">Avec un solde</button>
        </div>
        <div v-if="canManage && payableMerchants.length" class="flex flex-wrap items-center gap-3 border-b bg-emerald-50 px-4 py-2 text-sm">
          <span class="flex-1">☑️ {{ batch.merchants.length }} client{{ batch.merchants.length > 1 ? 's' : '' }} coché{{ batch.merchants.length > 1 ? 's' : '' }} · <b class="tabular-nums">{{ money(selectedMerchantsTotal) }}</b><span class="text-xs text-gray-600"> · grisés : moyen de paiement à vérifier</span></span>
          <button type="button" class="btn-primary" :disabled="!batch.merchants.length || batch.busy" @click="prepareSelected">Préparer {{ batch.merchants.length > 1 ? `les ${batch.merchants.length} paiements` : 'le paiement' }}</button>
        </div>

        <!-- Ordinateur : tableau -->
        <table class="hidden w-full text-sm md:table">
          <thead class="border-b-2 border-slate-300 bg-slate-100 text-left text-sm font-bold text-slate-800">
            <tr>
              <th v-if="canManage" class="w-8 p-3"><input type="checkbox" aria-label="Tout cocher" :checked="payableMerchants.length > 0 && batch.merchants.length === payableMerchants.length" @change="toggleAllMerchants($event.target.checked)"></th>
              <th class="p-3">Client</th>
              <th v-if="period.key !== 'all'" class="p-3 text-right">Date choisie<span class="block text-xs font-semibold text-amber-700 first-letter:uppercase">{{ chosenDate }}</span></th>
              <th class="p-3 text-right">Total à payer<span class="block text-xs font-normal text-slate-500">toutes dates</span></th>
              <th class="p-3">Payer avec</th>
              <th class="p-3" />
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="m in visibleMerchants" :key="m.merchant_id" :class="['align-top', canManage && m.available > 0 && !merchantPayable(m) ? 'bg-slate-50 text-gray-600' : '']">
              <td v-if="canManage" class="p-3"><input v-model="batch.merchants" type="checkbox" :value="m.merchant_id" :disabled="!merchantPayable(m)" :aria-label="`Choisir ${m.business_name}`"></td>
              <td class="p-3">
                <p class="font-semibold text-slate-900">{{ m.business_name }}
                  <span v-if="m.premium" class="ml-1 whitespace-nowrap rounded-full bg-amber-100 px-2 text-xs font-bold text-amber-800">⭐ {{ m.premium.name }}<template v-if="m.premium.payout_label"> · payer {{ m.premium.payout_label.toLowerCase() }}</template></span>
                </p>
                <p class="text-xs text-gray-500">{{ m.last_paid_at ? `dernier paiement : ${dateTime(m.last_paid_at)}` : 'jamais payé' }}</p>
              </td>
              <td v-if="period.key !== 'all'" class="p-3 text-right">
                <template v-if="m.unpaid_total">
                  <p class="font-semibold tabular-nums">{{ money(m.unpaid_total) }}</p>
                  <p v-if="m.pending_cash" class="text-xs text-amber-700">dont {{ money(m.pending_cash) }} chez un livreur</p>
                  <p v-else class="text-xs text-gray-500">{{ countLabel(m.period_count) }} · ✓ tout à la caisse</p>
                </template>
                <template v-else><p class="text-gray-300">—</p><p class="text-xs text-gray-400">rien à cette date</p></template>
              </td>
              <td class="p-3 text-right">
                <p class="text-lg font-bold tabular-nums">{{ money(m.all.unpaid_total) }}</p>
                <p v-if="m.all.pending_cash" class="text-xs text-amber-700">dont {{ money(m.all.pending_cash) }} chez les livreurs</p>
              </td>
              <td :class="['p-3 text-xs', m.payment_method ? '' : 'text-red-700']">
                <RouterLink :to="{ path: '/admin/marchands', query: { id: m.merchant_id, onglet: 'paiement' } }" class="hover:underline">
                  <template v-if="m.payment_method">
                    {{ m.payment_method.summary }}
                    <span :class="m.payment_method.status === 'verified' ? 'text-emerald-700' : 'text-amber-700'">{{ m.payment_method.status === 'verified' ? '✔' : '⏳' }}</span>
                  </template>
                  <template v-else>⚠️ Aucun moyen de paiement</template>
                  <span v-if="m.payment_methods_pending" class="ml-1 whitespace-nowrap rounded-full bg-amber-100 px-2 text-amber-800">{{ m.payment_methods_pending }} à vérifier</span>
                </RouterLink>
              </td>
              <td class="whitespace-nowrap p-3 text-right">
                <button type="button" class="btn-secondary mr-1" @click="openMerchantDetail(m)">Détail</button>
                <button v-if="canManage && m.available > 0" type="button" class="btn-primary" :disabled="!merchantPayable(m)" @click="preparePayout(m)">Payer</button>
              </td>
            </tr>
            <tr v-if="!visibleMerchants.length"><td :colspan="canManage ? 6 : 5" class="p-4 text-center text-gray-500">Aucun montant à payer.</td></tr>
            <tr v-else-if="visibleMerchants.length > 1" class="bg-slate-100 font-semibold">
              <td v-if="canManage" /><td class="p-3">Total · {{ visibleMerchants.length }} clients</td>
              <td v-if="period.key !== 'all'" class="p-3 text-right tabular-nums">{{ money(visibleMerchants.reduce((n, m) => n + m.unpaid_total, 0)) }}</td>
              <td class="p-3 text-right tabular-nums">{{ money(visibleMerchants.reduce((n, m) => n + m.all.unpaid_total, 0)) }}</td>
              <td colspan="2" />
            </tr>
          </tbody>
        </table>

        <!-- Téléphone : une carte par client -->
        <div class="divide-y md:hidden">
          <div v-for="m in visibleMerchants" :key="m.merchant_id" class="space-y-2 p-4">
            <div class="flex items-start gap-2">
              <input v-if="canManage" v-model="batch.merchants" type="checkbox" class="mt-1" :value="m.merchant_id" :disabled="!merchantPayable(m)" :aria-label="`Choisir ${m.business_name}`">
              <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ m.business_name }}</p>
                <p class="text-xs text-gray-500">{{ m.payment_method ? m.payment_method.summary : '⚠️ Aucun moyen de paiement' }}</p>
              </div>
              <div class="text-right"><p class="text-lg font-bold tabular-nums">{{ money(m.all.unpaid_total) }}</p><p class="text-xs text-gray-500">total</p></div>
            </div>
            <div v-if="period.key !== 'all' && m.unpaid_total" class="flex justify-between rounded-lg bg-amber-50 px-3 py-2 text-sm">
              <span class="first-letter:uppercase">{{ chosenDate }} · {{ countLabel(m.period_count) }}</span><span class="font-semibold tabular-nums">{{ money(m.unpaid_total) }}</span>
            </div>
            <div class="flex gap-2">
              <button type="button" class="btn-secondary flex-1" @click="openMerchantDetail(m)">Détail</button>
              <button v-if="canManage && m.available > 0" type="button" class="btn-primary flex-1" :disabled="!merchantPayable(m)" @click="preparePayout(m)">Payer</button>
            </div>
          </div>
          <p v-if="!visibleMerchants.length" class="p-4 text-center text-sm text-gray-500">Aucun montant à payer.</p>
        </div>
      </section>
    </template>

    <!-- Détail d'un client : à payer par date, versements, grand livre -->
    <MerchantDetail
      v-if="merchantDetail"
      :merchant="merchantDetail"
      :can-manage="canManage"
      :in-period="inPeriod"
      :period-key="period.key"
      :chosen-date="chosenDate"
      :day-label="dayLabel"
      @close="merchantDetail = null"
      @pay="(all) => preparePayout(merchantDetail, all)"
      @changed="load"
    />

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
import DeliveryDatePicker from '../../components/finance/DeliveryDatePicker.vue'
import CourierCashDetail from '../../components/finance/CourierCashDetail.vue'
import MerchantDetail from '../../components/finance/MerchantDetail.vue'
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

// Date de livraison des courses (onglets livreurs et clients), gardée dans l'adresse
const PERIODS = [
  { key: 'today', label: 'Aujourd\'hui' }, { key: 'yesterday', label: 'Hier' }, { key: 'week', label: '7 jours' },
  { key: 'month', label: 'Ce mois' }, { key: 'all', label: 'Tout' }, { key: 'custom', label: 'Choisir…' },
]
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/
// Par défaut : aujourd'hui (« Payer tout » reste dans le détail de chaque client)
const DEFAULT_PERIOD = 'today'
const period = reactive(DATE_RE.test(route.query.du || '')
  ? { key: 'custom', from: route.query.du, to: DATE_RE.test(route.query.au || '') ? route.query.au : route.query.du }
  : { key: PERIOD_PRESETS[route.query.periode] ? route.query.periode : DEFAULT_PERIOD, from: '', to: '' })
if (period.key !== 'custom') Object.assign(period, { from: PERIOD_PRESETS[period.key].from(), to: PERIOD_PRESETS[period.key].to() })
const shortDay = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
const dayLabel = (d) => `${new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })}${d === shift(0) ? ' (aujourd\'hui)' : ''}`
// Date choisie en clair, sous l'en-tête « Date choisie » : « aujourd'hui, jeu. 5 nov. », « du 30 oct. au 5 nov. »
const chosenDate = computed(() => {
  if (period.from === period.to) return period.from === shift(0) ? `aujourd'hui, ${dayLabel(period.from).replace(' (aujourd\'hui)', '')}` : dayLabel(period.from)
  return `du ${shortDay(period.from)} au ${shortDay(period.to)}`
})
const periodLabel = computed(() => {
  if (period.key === 'all') return 'toutes les dates'
  if (period.from === period.to) return `du ${dayLabel(period.from)}`
  return `du ${shortDay(period.from)} au ${shortDay(period.to)}`
})
const payLabel = computed(() => (period.from === period.to ? `le ${shortDay(period.from)}` : 'la période'))
const periodParams = () => (period.key === 'all' ? {} : { from: period.from, to: period.to })
const inPeriod = (d) => period.key === 'all' || (d >= period.from && d <= period.to)
const periodAmount = (c) => c.days.filter((d) => d.day && inPeriod(d.day)).reduce((n, d) => n + d.amount, 0)
const periodCount = (c) => c.days.filter((d) => d.day && inPeriod(d.day)).reduce((n, d) => n + (d.count || 0), 0)
const countLabel = (n) => `${n} course${n > 1 ? 's' : ''}`
// « sur 4 dates », « seulement le 5 nov. », « avances et frais »
function spreadLabel(c) {
  const dated = c.days.filter((d) => d.day)
  if (!dated.length) return c.advances || c.expenses ? 'avances et frais' : ''
  if (dated.length === 1) return `seulement ${dated[0].day === shift(0) ? 'aujourd\'hui' : `le ${shortDay(dated[0].day)}`}`
  return `sur ${dated.length} dates`
}
// Lignes de livreurs dépliées (détail par date)
const opened = ref(new Set())
function toggleCourier(c) {
  const next = new Set(opened.value)
  next.has(c.courier_id) ? next.delete(c.courier_id) : next.add(c.courier_id)
  opened.value = next
}

function syncPeriodQuery() {
  const query = { ...route.query }
  delete query.periode; delete query.du; delete query.au
  if (period.key === 'custom') Object.assign(query, { du: period.from, au: period.to })
  else if (period.key !== DEFAULT_PERIOD) query.periode = period.key
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
const couriersWithMoney = computed(() => (cash.value?.data || []).filter((c) => c.cash_in_hand > 0).length)
const visibleTotals = computed(() => ({
  period: visibleCouriers.value.reduce((n, c) => n + periodAmount(c), 0),
  due: visibleCouriers.value.reduce((n, c) => n + Math.max(0, c.cash_in_hand), 0),
}))
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
const adjust = reactive({ open: false, courier: null, amount: null, description: '', error: '' })
const courierPayout = reactive({ open: false, data: null })

// Paiements groupés
const PAY_METHODS = [
  { value: 'cash', label: 'Espèces' }, { value: 'wave', label: 'Wave' }, { value: 'orange_money', label: 'Orange Money' },
  { value: 'mtn_momo', label: 'MTN MoMo' }, { value: 'moov_money', label: 'Moov Money' }, { value: 'bank', label: 'Virement bancaire' },
]
const batch = reactive({ merchants: [], payslips: [], payslipMethod: 'cash', busy: false })
const batchPay = reactive({ open: false, rows: [], error: '' })
// « En ce moment » (toutes dates) et point de la date choisie pour les clients
const merchantTotals = computed(() => {
  const rows = merchantBalances.value
  return {
    unpaid: rows.reduce((n, m) => n + (m.all?.unpaid_total || 0), 0),
    available: rows.reduce((n, m) => n + (m.all?.available || 0), 0),
    pending: rows.reduce((n, m) => n + (m.all?.pending_cash || 0), 0),
    count: rows.filter((m) => m.all?.unpaid_total).length,
    unverified: rows.filter((m) => m.all?.unpaid_total > 0 && m.payment_method?.status !== 'verified').length,
  }
})
const merchantPeriod = computed(() => ({
  available: merchantBalances.value.reduce((n, m) => n + m.available, 0),
  pending: merchantBalances.value.reduce((n, m) => n + m.pending_cash, 0),
}))
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
  if (tab.value === 'cash' || tab.value === 'merchants') {
    // « Tout » : le point des 31 derniers jours
    const range = period.key === 'all' ? { from: shift(-30), to: shift(0) } : { from: period.from, to: period.to }
    days.value = (await http.get('/finance/cash/days', { params: range }).catch(() => ({ data: { data: null } }))).data.data
  }
  if (tab.value === 'cash') await loadRemittances()
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
    ['Client', 'Date choisie', 'Prêt à payer (F)', 'En attente (F)', 'À payer à cette date (F)', 'Total à payer toutes dates (F)', 'Dernier paiement', 'Payer avec'],
    ...visibleMerchants.value.map((m) => [m.business_name, period.key === 'all' ? 'toutes' : chosenDate.value, m.available, m.pending_cash, m.unpaid_total, m.all?.unpaid_total ?? m.unpaid_total, m.last_paid_at ? dateTime(m.last_paid_at) : '', m.payment_method?.summary || '']),
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

async function openRemit(courier, all = false) {
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
    selected: data.data.filter((c) => all || !c.delivery_day || inPeriod(c.delivery_day)).map((c) => c.id),
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

// Frais déclarés par le livreur : validés (déduits de son versement, facturés au client) ou refusés
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

const merchantDetail = ref(null)
function openMerchantDetail(m) {
  merchantDetail.value = m
}

async function preparePayout(merchant, all = false) {
  try {
    const { data } = await http.post('/finance/payouts', { merchant_id: merchant.merchant_id, ...(all ? {} : periodParams()) })
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

onMounted(async () => {
  await load()
  // Lien « Faire le point » depuis l'activité des livreurs : versement du livreur ouvert
  const id = Number(route.query.verser)
  const courier = id && (cash.value?.data || []).find((c) => c.courier_id === id)
  if (courier && canManage.value) openRemit(courier)
})
</script>
