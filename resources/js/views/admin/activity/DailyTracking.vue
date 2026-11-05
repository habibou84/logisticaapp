<template>
  <div class="space-y-4">
    <!-- Jour suivi, direct, export, écran mural -->
    <div class="flex flex-wrap items-center gap-3">
      <span v-if="isToday" class="flex items-center gap-1.5 text-sm font-semibold text-emerald-700"><span class="h-2 w-2 rounded-full bg-emerald-600 animate-pulse" aria-hidden="true" /> En direct</span>
      <span v-else class="text-sm font-semibold text-slate-700">{{ isFuture ? 'Prévu le' : 'Journée du' }} {{ dayLabel }}</span>
      <span class="text-sm text-gray-600">Colis dont la livraison est prévue ce jour-là</span>
      <span class="flex-1" />
      <label class="flex items-center gap-2 text-sm font-medium">Jour
        <input v-model="day" type="date" class="input w-auto" :max="maxIso" @change="changeDay">
      </label>
      <button v-if="!isToday" type="button" class="btn-secondary" @click="day = todayIso; changeDay()">Aujourd'hui</button>
      <button type="button" class="btn-secondary" :disabled="!couriers.length" @click="exportCsv">Exporter</button>
      <RouterLink to="/admin/activite/mural" class="btn-secondary">Écran mural</RouterLink>
    </div>

    <!-- Activité : e-commerce, distribution, MAD (comptes limités : leurs activités seulement) -->
    <div v-if="activityChoices.length > 1" class="flex flex-wrap gap-2" role="group" aria-label="Activité">
      <button
        v-for="a in [{ value: '', label: 'Toutes' }, ...activityChoices]" :key="a.value" type="button" :aria-pressed="activity === a.value"
        :class="['rounded-full px-3 py-1.5 text-sm font-medium', activity === a.value ? 'bg-slate-900 text-white' : 'bg-white ring-1 ring-slate-300 text-slate-700']"
        @click="setActivity(a.value)"
      >{{ a.label }}</button>
    </div>

    <!-- Chiffres de l'équipe -->
    <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
      <div class="card p-3">
        <p class="text-sm text-gray-600">{{ isToday ? 'Livreurs actifs' : isFuture ? 'Livreurs prévus' : 'Livreurs au travail' }}</p>
        <p class="text-2xl font-bold">{{ totals.active ?? '—' }} <span class="text-sm font-normal text-gray-600">sur {{ totals.couriers ?? '—' }}</span></p>
      </div>
      <div class="card p-3">
        <p class="text-sm text-gray-600">{{ isFuture ? 'Livraisons prévues' : 'Livrés' }}</p>
        <p v-if="isFuture" class="text-2xl font-bold">{{ totals.deliveries ?? '—' }}</p>
        <p v-else class="text-2xl font-bold text-emerald-700">{{ totals.delivered ?? '—' }} <span class="text-sm font-normal text-gray-600">sur {{ totals.deliveries ?? '—' }}</span></p>
        <!-- Distribution : plis et chèques comptés un à un -->
        <p v-if="totals.units_total > totals.deliveries" class="text-xs text-gray-600">{{ isFuture ? totals.units_total : `${totals.units_done} plis sur ${totals.units_total}` }}{{ isFuture ? ' plis' : '' }}</p>
      </div>
      <div v-if="!isFuture" class="card p-3">
        <p class="text-sm text-gray-600">Taux de réussite</p>
        <p class="text-2xl font-bold">{{ pct(totals.success_rate) }}</p>
        <p v-if="totals.habit_success_rate !== null && totals.habit_success_rate !== undefined" class="text-xs text-gray-600">habituel {{ totals.habit_success_rate }} %</p>
      </div>
      <div v-if="!isFuture" class="card p-3">
        <p class="text-sm text-gray-600">Échecs</p>
        <p :class="['text-2xl font-bold', totals.failed ? 'text-red-700' : '']">{{ totals.failed ?? '—' }}</p>
      </div>
      <div v-if="isToday" :class="['card p-3', totals.late_end ? 'bg-orange-50 border-orange-200' : '']">
        <p :class="['text-sm', totals.late_end ? 'text-orange-800' : 'text-gray-600']">{{ closesAt ? `Risquent de finir après ${closesAt}` : 'Colis en main' }}</p>
        <p :class="['text-2xl font-bold', totals.late_end ? 'text-orange-800' : '']">{{ closesAt ? `${totals.late_end || 0} livreur${totals.late_end > 1 ? 's' : ''}` : (totals.in_hand ?? '—') }}</p>
      </div>
      <div v-if="isToday" class="card p-3">
        <p class="text-sm text-gray-600">Fin de journée estimée</p>
        <p class="text-2xl font-bold">{{ totals.estimated_end ? time(totals.estimated_end) : '—' }}</p>
        <div class="mt-1 h-2 rounded-full bg-slate-200" role="progressbar" :aria-valuenow="totals.progress || 0" aria-valuemin="0" aria-valuemax="100" aria-label="Avancement de la journée">
          <div class="h-2 rounded-full bg-emerald-700" :style="{ width: `${totals.progress || 0}%` }" />
        </div>
      </div>
    </div>

    <!-- Colis des jours précédents encore à faire : à part, hors avancement du jour -->
    <div v-if="isToday && totals.late_before" class="flex flex-wrap items-center gap-3 rounded-lg border border-orange-200 bg-orange-50 p-3 text-sm text-orange-900" role="status">
      <span class="flex-1"><b>{{ totals.late_before }} colis en retard d'avant</b> : prévus les jours précédents, encore chez les livreurs. Ils ne comptent pas dans l'avancement du jour.</span>
      <button type="button" class="btn-secondary" @click="f.state = f.state === 'late' ? '' : 'late'">{{ f.state === 'late' ? 'Voir tous les livreurs' : 'Voir les livreurs concernés' }}</button>
    </div>

    <!-- Distribution : délai interne dépassé, accusés de réception manquants -->
    <div v-if="isToday && (totals.out_of_delay || totals.missing_receipts)" class="flex flex-wrap items-center gap-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900" role="status">
      <span class="flex-1">
        <template v-if="totals.out_of_delay"><b>{{ totals.out_of_delay }} course{{ totals.out_of_delay > 1 ? 's' : '' }} hors délai interne</b> encore chez les livreurs. </template>
        <template v-if="totals.missing_receipts"><b>{{ totals.missing_receipts }} accusé{{ totals.missing_receipts > 1 ? 's' : '' }} de réception manquant{{ totals.missing_receipts > 1 ? 's' : '' }}</b> : livrée sans la photo demandée.</template>
      </span>
      <button type="button" class="btn-secondary" @click="f.state = f.state === 'watch' ? '' : 'watch'">{{ f.state === 'watch' ? 'Voir tous les livreurs' : 'Voir les livreurs concernés' }}</button>
    </div>

    <!-- Lots de distribution en cours -->
    <details v-if="lots.length" class="card p-3" open>
      <summary class="cursor-pointer font-semibold text-sm">Lots de distribution en cours ({{ lots.length }})</summary>
      <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="l in lots" :key="l.id" class="rounded-lg bg-slate-50 p-2 text-sm">
          <p class="font-medium truncate">{{ l.name }} · {{ l.merchant }}</p>
          <Progress label="Livrés" :done="l.delivered" :total="l.included" :warn="l.late > 0" />
          <p class="text-xs text-gray-600">{{ l.in_progress }} en cours<template v-if="l.late"> · <b class="text-rose-800">{{ l.late }} en retard</b></template><template v-if="l.due_on"> · échéance {{ shortDay(l.due_on) }}</template></p>
        </div>
      </div>
    </details>

    <!-- Filtres -->
    <div class="flex flex-wrap items-end gap-3">
      <div class="flex-1 min-w-[12rem]">
        <label class="label" for="act-search">Chercher un livreur</label>
        <input id="act-search" v-model="f.search" type="search" class="input" placeholder="Nom ou téléphone">
      </div>
      <div>
        <label class="label" for="act-state">État</label>
        <select id="act-state" v-model="f.state" class="input w-auto">
          <option value="">Tous</option>
          <template v-if="isToday">
            <option value="mission">En mission</option>
            <option v-if="totals.mad" value="mad">En MAD ({{ totals.mad }})</option>
            <option value="available">Disponibles</option>
            <option value="offline">Hors service</option>
            <option value="watch">À surveiller ({{ totals.watched || 0 }})</option>
            <option v-if="totals.late_before" value="late">Retards d'avant</option>
          </template>
          <option v-else-if="isFuture" value="planned">Ont des missions prévues</option>
          <option v-else value="done">Ont travaillé</option>
        </select>
      </div>
      <div v-if="zones.length">
        <label class="label" for="act-zone">Zone</label>
        <select id="act-zone" v-model="f.zone" class="input w-auto">
          <option value="">Toutes</option>
          <option v-for="z in zones" :key="z" :value="z">{{ z }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="act-sort">Trier</label>
        <select id="act-sort" v-model="f.sort" class="input w-auto">
          <option value="watch">À surveiller d'abord</option>
          <option value="end">Fin la plus tardive</option>
          <option value="remaining">Le plus à faire</option>
          <option value="progress">Les moins avancés</option>
          <option value="name">Nom</option>
        </select>
      </div>
    </div>

    <div :class="['grid gap-4', selected ? 'xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]' : '']">
      <!-- Vue d'ensemble : un livreur par ligne (la carte est dans l'onglet « Carte ») -->
      <div class="card self-start overflow-x-auto">
        <p v-if="!loaded" class="p-4 text-gray-500">Chargement…</p>
        <p v-else-if="!visible.length" class="p-4 text-center text-gray-500">Aucun livreur.</p>
        <table v-else class="stack-table w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase text-gray-600">
            <tr>
              <th class="min-w-[14rem] p-2">Livreur</th>
              <th class="min-w-[9rem] p-2">Livraisons</th>
              <th class="p-2">Ramassages</th>
              <th v-if="!isFuture" class="p-2 text-right">Échecs</th>
              <th v-if="isToday" class="p-2 text-right">En main</th>
              <th v-if="!isFuture" class="p-2">Fin</th>
              <th class="p-2"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr
              v-for="c in visible" :key="c.id"
              :class="['cursor-pointer hover:bg-slate-50', c.flags.length ? 'bg-orange-50/70' : '', selected?.id === c.id ? '!bg-emerald-50 shadow-[inset_4px_0_0_#047857]' : '']"
              :aria-selected="selected?.id === c.id" @click="select(c)"
            >
              <td data-label="Livreur" class="p-2">
                <div class="flex items-center gap-2">
                  <span :class="['h-2.5 w-2.5 shrink-0 rounded-full', STATE[c.state].dot]" aria-hidden="true" />
                  <span class="font-semibold">{{ c.name }}</span>
                </div>
                <div class="mt-0.5 text-xs text-gray-500">
                  <span :class="['rounded-full px-1.5 py-0.5', STATE[c.state].chip]">{{ STATE[c.state].label }}</span>
                  <template v-if="c.last_location_at"> · vu {{ ago(c.last_location_at) }}</template>
                  <template v-if="c.zones.length"> · {{ c.zones.slice(0, 2).join(', ') }}</template>
                </div>
                <!-- Mise à disposition : chez quel client, horaire, courses faites pour lui -->
                <p v-if="c.mad" class="mt-0.5 text-xs text-violet-900">MAD chez <b>{{ c.mad.merchant }}</b><template v-if="c.mad.from"> · {{ c.mad.from }}–{{ c.mad.to }}</template><template v-if="c.mad.total"> · {{ c.mad.done }} / {{ c.mad.total }}</template><template v-if="isToday && !c.mad.reserved_now"> · pas en ce moment</template></p>
                <p v-if="c.objective" class="mt-0.5 text-xs text-violet-800">Prime {{ money(c.objective.amount) }} : {{ Math.min(c.objective.value, c.objective.threshold) }} / {{ c.objective.threshold }}</p>
                <p v-if="c.flags.length" class="mt-0.5 text-xs font-semibold text-orange-800">⚠ {{ c.flags.join(' · ') }}</p>
              </td>
              <td data-label="Livraisons" class="p-2">
                <template v-if="c.deliveries.total">
                  <div class="flex items-baseline justify-between gap-2 text-xs"><b class="text-sm">{{ c.deliveries.done }} / {{ c.deliveries.total }}</b><span v-if="c.late_before" class="text-orange-700">+{{ c.late_before }} d'avant</span></div>
                  <div class="mt-1 h-1.5 rounded-full bg-slate-200"><div :class="['h-1.5 rounded-full', barColor(c)]" :style="{ width: `${share(c.deliveries)}%` }" /></div>
                  <div v-if="c.deliveries.units_total > c.deliveries.total" class="mt-0.5 text-xs text-gray-500">{{ c.deliveries.units_done }} / {{ c.deliveries.units_total }} plis</div>
                  <div v-if="paceLine(c)" class="mt-0.5 text-xs text-gray-500">{{ paceLine(c) }}</div>
                </template>
                <span v-else class="text-xs text-gray-400">—</span>
              </td>
              <td data-label="Ramassages" class="whitespace-nowrap p-2">{{ c.pickups.total ? `${c.pickups.done} / ${c.pickups.total}` : '—' }}</td>
              <td v-if="!isFuture" data-label="Échecs" :class="['p-2 text-right', c.failed ? 'font-semibold text-red-700' : 'text-gray-400']">{{ c.failed || '—' }}</td>
              <td v-if="isToday" data-label="En main" class="whitespace-nowrap p-2 text-right">
                <span v-if="c.parcels_in_hand || c.cash_in_hand">{{ c.parcels_in_hand }} colis<span class="block text-xs text-gray-600">{{ money(c.cash_in_hand) }}</span></span>
                <span v-else class="text-gray-400">—</span>
              </td>
              <td v-if="!isFuture" data-label="Fin" class="whitespace-nowrap p-2 text-xs">
                <template v-if="c.remaining && c.estimated_end"><b :class="c.late_end ? 'text-orange-800' : ''">{{ time(c.estimated_end) }}</b><span v-if="c.late_end" class="block text-orange-800">après {{ closesAt }}</span></template>
                <template v-else-if="!c.remaining && c.estimated_end"><span class="text-emerald-700">✓ {{ time(c.estimated_end) }}</span></template>
                <template v-else>—</template>
              </td>
              <td class="whitespace-nowrap p-2 text-right" @click.stop>
                <a v-if="c.phone" :href="telLink(c.phone)" class="inline-grid h-8 w-8 place-items-center rounded-lg border border-slate-300 hover:bg-slate-100" :title="`Appeler ${c.name}`">📞</a>
                <RouterLink :to="{ path: '/admin/activite/carte', query: { livreur: c.id, date: day } }" class="ml-1 inline-grid h-8 w-8 place-items-center rounded-lg border border-slate-300 hover:bg-slate-100" :title="`${c.name} sur la carte`">🗺️</RouterLink>
                <RouterLink v-if="isToday && !c.remaining && (c.cash_in_hand > 0 || c.parcels_in_hand > 0) && auth.can('finance.manage')" :to="{ path: '/admin/caisse', query: { verser: c.id } }" class="ml-1 text-xs font-semibold text-emerald-700 hover:underline">Faire le point</RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="loaded && visible.length && !selected" class="border-t px-3 py-2 text-xs text-gray-500">Touchez un livreur pour voir ses missions, réassigner une course ou lui envoyer une consigne.</p>
      </div>

      <!-- Détail du livreur choisi -->
      <div v-if="selected" class="space-y-3 self-start xl:sticky xl:top-4">
        <div v-if="selected" ref="detailEl" class="card p-4 space-y-3">
          <div class="flex items-start gap-2">
            <div class="flex-1">
              <h2 class="text-lg font-bold">{{ selected.name }}</h2>
              <p class="text-sm text-gray-600">{{ STATE[selected.state].label }}<template v-if="selected.zones.length"> · {{ selected.zones.join(', ') }}</template><template v-if="selected.vehicle_type"> · {{ selected.vehicle_type }}</template></p>
            </div>
            <button type="button" class="btn-secondary" aria-label="Fermer le détail" @click="unselect">✕</button>
          </div>
          <div class="flex flex-wrap gap-2">
            <a v-if="selected.phone" :href="telLink(selected.phone)" class="btn-primary">Appeler</a>
            <a v-if="selected.phone" :href="whatsappLink(selected.phone)" target="_blank" rel="noopener" class="btn-secondary">WhatsApp</a>
            <RouterLink :to="{ path: '/admin/activite/carte', query: { livreur: selected.id, date: day } }" class="btn-secondary">🗺️ Voir sur la carte</RouterLink>
            <button v-if="isToday && activeMissions.length" type="button" class="btn-secondary" @click="openConsigne">Envoyer une consigne</button>
            <RouterLink v-if="isToday && (selected.cash_in_hand > 0 || selected.parcels_in_hand > 0) && auth.can('finance.manage')" :to="{ path: '/admin/caisse', query: { verser: selected.id } }" class="btn-secondary">Faire le point</RouterLink>
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
            <div class="rounded-lg bg-slate-50 p-2"><p class="text-gray-600">Livrées</p><p class="text-lg font-bold text-emerald-700">{{ selected.deliveries.done }} / {{ selected.deliveries.total }}</p></div>
            <div class="rounded-lg bg-slate-50 p-2"><p class="text-gray-600">Ramassées</p><p class="text-lg font-bold">{{ selected.pickups.done }} / {{ selected.pickups.total }}</p></div>
            <div class="rounded-lg bg-slate-50 p-2"><p class="text-gray-600">{{ isToday ? 'Colis en main' : 'Échecs' }}</p><p class="text-lg font-bold">{{ isToday ? selected.parcels_in_hand : selected.failed }}</p></div>
            <div class="rounded-lg bg-slate-50 p-2"><p class="text-gray-600">{{ isToday ? 'Argent en main' : 'Réussite' }}</p><p class="text-lg font-bold">{{ isToday ? money(selected.cash_in_hand) : pct(selected.success_rate) }}</p></div>
          </div>
          <p v-if="selected.flags.length" class="rounded-lg bg-orange-50 px-3 py-2 text-sm text-orange-900">⚠ {{ selected.flags.join(' · ') }}</p>

          <!-- Consigne au livreur, rattachée à une de ses missions en cours -->
          <form v-if="consigne.open" class="rounded-lg bg-slate-50 p-3 space-y-2" @submit.prevent="sendConsigne">
            <label class="label" for="consigne-body">Consigne à {{ selected.name }}</label>
            <textarea id="consigne-body" v-model="consigne.body" class="input min-h-[4.5rem]" maxlength="500" placeholder="Ex. : passe d'abord par Riviera, le client attend avant 17 h." />
            <div class="flex flex-wrap items-end gap-2">
              <div>
                <label class="label" for="consigne-order">À propos de la course</label>
                <select id="consigne-order" v-model="consigne.order_id" class="input w-auto">
                  <option v-for="m in activeMissions" :key="m.id" :value="m.order_id">{{ m.tracking_code }} · {{ m.type_label }}</option>
                </select>
              </div>
              <button type="submit" class="btn-primary" :disabled="consigne.busy || !consigne.body.trim()">Envoyer</button>
              <button type="button" class="btn-secondary" @click="consigne.open = false">Annuler</button>
            </div>
            <p class="text-xs text-gray-600">Il la reçoit dans son application et peut répondre « lu » ou « compris ».</p>
            <p v-if="consigne.error" class="text-sm text-red-700" role="alert">{{ consigne.error }}</p>
          </form>

          <h3 class="font-semibold">{{ isToday ? 'Missions du jour' : 'Missions de la journée' }}</h3>
          <p v-if="missionsLoading" class="text-sm text-gray-500">Chargement…</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm stack-table">
              <thead class="bg-slate-50 text-left text-gray-600">
                <tr><th class="p-2">Heure</th><th class="p-2">Course</th><th class="p-2">État</th><th class="p-2 text-right">Montant</th><th v-if="canAct" /></tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="m in missions" :key="m.id" :class="m.status === 'in_progress' ? 'bg-blue-50' : ''">
                  <td class="p-2 whitespace-nowrap">
                    <template v-if="m.completed_at">{{ time(m.completed_at) }}</template>
                    <template v-else>{{ m.status === 'in_progress' ? 'en cours' : 'à faire' }}<span v-if="stopNumber(m)" class="ml-1 rounded-full bg-blue-100 px-1.5 text-xs font-bold text-blue-900">{{ stopNumber(m) }}</span></template>
                  </td>
                  <td data-label="Course" class="p-2">
                    <RouterLink :to="`/admin/courses/${m.order_id}`" class="font-mono text-xs text-blue-700 hover:underline">{{ m.tracking_code }}</RouterLink>
                    <span v-if="m.from_before" class="mr-1 rounded-full bg-orange-100 px-1.5 text-xs font-semibold text-orange-900">prévu le {{ shortDay(m.delivery_day) }}</span>
                    <span v-if="m.out_of_delay" class="mr-1 rounded-full bg-rose-100 px-1.5 text-xs font-semibold text-rose-900">hors délai</span>
                    <span v-if="m.activity !== 'ecommerce'" :class="['mr-1 rounded-full px-1.5 text-xs font-semibold', m.activity === 'mad' ? 'bg-violet-100 text-violet-900' : 'bg-blue-100 text-blue-900']">{{ m.activity === 'mad' ? 'MAD' : 'Distribution' }}<template v-if="m.units > 1"> · {{ m.units }} plis</template></span>
                    {{ m.type_label }} · {{ m.merchant }}<template v-if="m.zone"> · {{ m.zone }}</template><template v-if="m.recipient"> · {{ m.recipient }}</template>
                  </td>
                  <td data-label="État" :class="['p-2 font-medium', MISSION[m.status]?.cls]">{{ MISSION[m.status]?.label || m.status }}</td>
                  <td data-label="Montant" class="p-2 text-right">{{ m.cod_amount ? money(m.cod_amount) : '—' }}</td>
                  <td v-if="canAct" class="p-2 text-right">
                    <button v-if="!m.completed_at" type="button" class="text-blue-700 hover:underline whitespace-nowrap" :title="`Donner ${m.tracking_code} à un autre livreur`" @click="openReassign(m)">Réassigner</button>
                  </td>
                </tr>
                <tr v-if="!missions.length"><td :colspan="canAct ? 5 : 4" class="p-3 text-center text-gray-500">Aucune mission.</td></tr>
              </tbody>
            </table>
          </div>
          <p class="text-sm">
            <RouterLink to="/admin/livreurs" class="text-blue-700 hover:underline">Fiches des livreurs</RouterLink> ·
            <RouterLink to="/admin/activite/colis" class="text-blue-700 hover:underline">Colis en main</RouterLink> ·
            <RouterLink to="/admin/activite/remontees" class="text-blue-700 hover:underline">Remontées terrain</RouterLink>
          </p>
        </div>
      </div>
    </div>

    <!-- Donner une course à un autre livreur -->
    <Modal :open="reassign.open" :title="`Donner ${reassign.mission?.tracking_code || ''} à un autre livreur`" @close="reassign.open = false">
      <p class="text-sm text-gray-600 mb-3">Les plus proches et les moins chargés d'abord. Les deux livreurs sont prévenus.</p>
      <div class="space-y-2 max-h-[50vh] overflow-y-auto">
        <label v-for="o in reassignOptions" :key="o.id" :class="['flex items-start gap-3 rounded-lg border p-3 cursor-pointer', reassign.courier_id === o.id ? 'border-emerald-700 ring-1 ring-emerald-700' : '']">
          <input v-model="reassign.courier_id" type="radio" :value="o.id" class="mt-1 h-4 w-4">
          <span class="flex-1 text-sm">
            <b>{{ o.name }}</b> · {{ STATE[o.state].label.toLowerCase() }}<template v-if="o.km !== null"> · {{ o.km.toFixed(1).replace('.', ',') }} km</template>
            <span class="block text-gray-600">{{ o.remaining }} mission{{ o.remaining > 1 ? 's' : '' }} en cours<template v-if="o.estimated_end && o.remaining"> · fin estimée {{ time(o.estimated_end) }}</template></span>
          </span>
        </label>
        <p v-if="!reassignOptions.length" class="text-sm text-gray-500">Aucun autre livreur en service.</p>
      </div>
      <p v-if="reassign.mission?.status === 'in_progress'" class="mt-2 text-xs text-gray-600">Si {{ selected?.name }} a déjà le colis, il passera par un relais vers le nouveau livreur.</p>
      <p v-if="reassign.error" class="mt-2 text-sm text-red-700" role="alert">{{ reassign.error }}</p>
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn-secondary" @click="reassign.open = false">Annuler</button>
        <button type="button" class="btn-primary" :disabled="!reassign.courier_id || reassign.busy" @click="saveReassign">Donner la course</button>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../../bootstrap/axios'
import { useEcho } from '../../../bootstrap/echo'
import { orderChanges } from '../../../composables/useRealtime'
import Modal from '../../../components/Modal.vue'
import { downloadCsv } from '../../../components/stats/chart'
import { useAuthStore } from '../../../stores/auth'
import { useToastStore } from '../../../stores/toasts'
import { money, telLink, whatsappLink } from '../../../utils/format'
import { myActivities } from '../../../utils/activities'

defineProps({ embedded: { type: Boolean, default: false } })

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const echo = useEcho()
const STATE = {
  mission: { label: 'En mission', dot: 'bg-blue-600', chip: 'bg-blue-100 text-blue-900', color: '#2563eb' },
  available: { label: 'Disponible', dot: 'bg-emerald-600', chip: 'bg-emerald-100 text-emerald-900', color: '#059669' },
  offline: { label: 'Hors service', dot: 'bg-slate-400', chip: 'bg-slate-200 text-slate-700', color: '#94a3b8' },
  done: { label: 'A travaillé', dot: 'bg-emerald-700', chip: 'bg-emerald-100 text-emerald-900', color: '#047857' },
  idle: { label: 'Rien de prévu', dot: 'bg-slate-400', chip: 'bg-slate-200 text-slate-700', color: '#94a3b8' },
  planned: { label: 'Missions prévues', dot: 'bg-indigo-600', chip: 'bg-indigo-100 text-indigo-900', color: '#4f46e5' },
  mad: { label: 'En MAD', dot: 'bg-violet-600', chip: 'bg-violet-100 text-violet-900', color: '#7c3aed' },
}
const MISSION = {
  completed: { label: 'Faite', cls: 'text-emerald-700' },
  failed: { label: 'Échec', cls: 'text-red-700' },
  in_progress: { label: 'En cours', cls: 'text-blue-700' },
  accepted: { label: 'À faire', cls: 'text-slate-700' },
  assigned: { label: 'À faire', cls: 'text-slate-700' },
}

// Barre de progression d'un livreur
const Progress = defineComponent({
  props: { label: String, done: Number, total: Number, warn: Boolean, color: { type: String, default: '' } },
  setup(props) {
    return () => {
      const pct = props.total ? Math.round((100 * props.done) / props.total) : 0
      const color = props.color || (pct === 100 ? 'bg-emerald-700' : props.warn ? 'bg-orange-600' : 'bg-emerald-600')
      return h('div', { class: 'flex items-center gap-2 text-sm' }, [
        h('span', { class: 'w-40 shrink-0' }, `${props.label} ${props.done} / ${props.total}`),
        h('div', { class: 'h-2 flex-1 rounded-full bg-slate-200', role: 'progressbar', 'aria-valuenow': pct, 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-label': props.label },
          h('div', { class: ['h-2 rounded-full', color], style: { width: `${pct}%` } })),
      ])
    }
  },
})

const todayIso = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
// Jours à venir consultables : ce qui est déjà prévu (30 jours, comme le serveur)
const maxIso = new Date(Date.now() - new Date().getTimezoneOffset() * 60000 + 30 * 86400000).toISOString().slice(0, 10)
const day = ref(route.query.jour || todayIso)
const isToday = ref(true)
const isFuture = ref(false)
// Réassigner : aujourd'hui et les jours à venir
const canAct = computed(() => isToday.value || isFuture.value)
const closesAt = ref(null)
// Activité suivie (?activite=) : e-commerce, distribution, MAD ; seulement celles du compte
const activityChoices = computed(() => myActivities(auth.user).map((a) => ({ value: a.value, label: a.value === 'mad' ? 'MAD' : a.label })))
const activity = ref(activityChoices.value.some((a) => a.value === route.query.activite) ? route.query.activite : '')
const lots = ref([])
const couriers = ref([])
const totals = ref({})
const loaded = ref(false)
const selected = ref(null)
const missions = ref([])
const missionsLoading = ref(false)
const f = reactive({ search: '', state: '', zone: '', sort: 'watch' })
const consigne = reactive({ open: false, body: '', order_id: null, busy: false, error: '' })
const reassign = reactive({ open: false, mission: null, courier_id: null, busy: false, error: '' })
const detailEl = ref(null)
let timer = null
// Barre d'avancement des livraisons
const share = (p) => (p.total ? Math.round((100 * p.done) / p.total) : 0)
const barColor = (c) => (share(c.deliveries) === 100 ? 'bg-emerald-700' : c.flags.length ? 'bg-orange-600' : 'bg-emerald-600')

const norm = (v) => (v || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const pct = (v) => (v === null || v === undefined ? '—' : `${v} %`)
const time = (at) => new Date(at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ')
const dayLabel = computed(() => new Date(`${day.value}T12:00:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }))
const shortDay = (d) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }) : '')
const zones = computed(() => [...new Set(couriers.value.flatMap((c) => c.zones))].sort())
const visible = computed(() => {
  const list = couriers.value.filter((c) => (!f.search || norm(`${c.name} ${c.phone}`).includes(norm(f.search)))
    && (!f.state || (f.state === 'watch' ? c.flags.length > 0 : f.state === 'late' ? c.late_before > 0 : c.state === f.state))
    && (!f.zone || c.zones.includes(f.zone)))
  const by = {
    remaining: (a, b) => b.remaining - a.remaining,
    progress: (a, b) => (a.progress ?? 101) - (b.progress ?? 101),
    end: (a, b) => (b.remaining && b.estimated_end ? Date.parse(b.estimated_end) : 0) - (a.remaining && a.estimated_end ? Date.parse(a.estimated_end) : 0),
    name: (a, b) => a.name.localeCompare(b.name),
  }
  return f.sort === 'watch' ? list : [...list].sort(by[f.sort])
})
// « 1 mission toutes les 38 min, habituel 25 min · réussite 92 % (habituel 89 %) »
function paceLine(c) {
  const parts = []
  if (c.pace) parts.push(`1 mission toutes les ${c.pace} min${c.habit.pace ? `, habituel ${c.habit.pace} min` : ''}`)
  if (c.success_rate !== null) parts.push(`réussite ${c.success_rate} %${c.habit.success_rate !== null ? ` (habituel ${c.habit.success_rate} %)` : ''}`)
  return parts.join(' · ')
}
const activeMissions = computed(() => missions.value.filter((m) => !m.completed_at))
// Prochains arrêts : missions à faire avec une adresse placée, dans l'ordre
const nextStops = computed(() => activeMissions.value.filter((m) => m.lat !== null && m.lng !== null))
const stopNumber = (m) => { const i = nextStops.value.indexOf(m); return i >= 0 ? i + 1 : null }

function km(lat1, lng1, lat2, lng2) {
  const r = (d) => (d * Math.PI) / 180
  const a = Math.sin(r(lat2 - lat1) / 2) ** 2 + Math.cos(r(lat1)) * Math.cos(r(lat2)) * Math.sin(r(lng2 - lng1) / 2) ** 2
  return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
}
// Livreurs proposés pour reprendre une course : en service, les plus proches puis les moins chargés ;
// un livreur en MAD seulement pour une course de son client
const reassignOptions = computed(() => {
  const m = reassign.mission
  return couriers.value
    .filter((c) => c.id !== selected.value?.id && c.state !== 'offline' && (!c.mad?.reserved_now || c.mad.merchant === m?.merchant))
    .map((c) => ({ ...c, km: m && m.lat !== null && c.lat !== null ? km(m.lat, m.lng, c.lat, c.lng) : null }))
    .sort((a, b) => (a.km ?? 999) - (b.km ?? 999) || a.remaining - b.remaining)
})

function ago(at) {
  const minutes = Math.round((Date.now() - new Date(at).getTime()) / 60000)
  if (minutes < 2) return 'à l\'instant'
  if (minutes < 60) return `il y a ${minutes} min`
  if (minutes < 48 * 60) return `il y a ${Math.round(minutes / 60)} h`
  return `il y a ${Math.round(minutes / 1440)} jours`
}

async function load() {
  const { data } = await http.get('/couriers/activity', { params: { ...(day.value !== todayIso ? { date: day.value } : {}), ...(activity.value ? { activity: activity.value } : {}) } })
  lots.value = data.lots || []
  couriers.value = data.data
  totals.value = data.totals
  isToday.value = data.is_today
  isFuture.value = !!data.is_future
  closesAt.value = data.depot_closes_at
  loaded.value = true
  if (selected.value) selected.value = couriers.value.find((c) => c.id === selected.value.id) || null
}

function query() {
  return { ...(day.value !== todayIso ? { jour: day.value } : {}), ...(activity.value ? { activite: activity.value } : {}) }
}

function changeDay() {
  f.state = ''
  unselect()
  router.replace({ query: query() })
  load()
}

function setActivity(value) {
  activity.value = value
  changeDay()
}

async function loadMissions(c) {
  missionsLoading.value = true
  try {
    missions.value = (await http.get(`/couriers/${c.id}/activity`, { params: { date: day.value } })).data.data
  } finally {
    missionsLoading.value = false
  }
}

async function select(c) {
  selected.value = c
  consigne.open = false
  await loadMissions(c)
  // Téléphone : le détail est sous la liste
  await nextTick()
  if (window.innerWidth < 1280) detailEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function unselect() {
  selected.value = null
  missions.value = []
}

function openConsigne() {
  Object.assign(consigne, { open: true, body: '', order_id: activeMissions.value[0]?.order_id ?? null, error: '' })
}

async function sendConsigne() {
  consigne.busy = true
  consigne.error = ''
  try {
    await http.post(`/orders/${consigne.order_id}/courier-messages`, { body: consigne.body, courier_id: selected.value.id })
    toasts.success(`Consigne envoyée à ${selected.value.name}.`)
    consigne.open = false
  } catch (e) {
    consigne.error = apiErrorMessage(e)
  } finally {
    consigne.busy = false
  }
}

function openReassign(m) {
  Object.assign(reassign, { open: true, mission: m, courier_id: reassignOptions.value[0]?.id ?? null, error: '' })
  // La liste dépend de la mission : on prend le premier proposé une fois calculée
  nextTick(() => { reassign.courier_id = reassignOptions.value[0]?.id ?? null })
}

async function saveReassign() {
  reassign.busy = true
  reassign.error = ''
  try {
    const target = couriers.value.find((c) => c.id === reassign.courier_id)
    await http.post(`/orders/${reassign.mission.order_id}/assign`, { type: reassign.mission.type, courier_id: reassign.courier_id })
    toasts.success(`${reassign.mission.tracking_code} donnée à ${target?.name}.`)
    reassign.open = false
    await load()
    if (selected.value) await loadMissions(selected.value)
  } catch (e) {
    reassign.error = apiErrorMessage(e)
  } finally {
    reassign.busy = false
  }
}

function exportCsv() {
  downloadCsv(`activite-livreurs-${day.value}.csv`, [
    ['Livreur', 'État', 'En MAD chez', 'Livraisons faites', 'Livraisons prévues', 'Plis livrés', 'Plis prévus', 'Ramassages faits', 'Ramassages prévus', 'Retards d\'avant', 'Échecs', 'Réussite (%)', 'Réussite habituelle (%)', 'Minutes par mission', 'Habituel (min)', 'Fin estimée', 'Colis en main', 'Argent en main', 'À surveiller'],
    ...couriers.value.map((c) => [c.name, STATE[c.state].label, c.mad?.merchant || '', c.deliveries.done, c.deliveries.total, c.deliveries.units_done, c.deliveries.units_total, c.pickups.done, c.pickups.total, c.late_before, c.failed,
      c.success_rate ?? '', c.habit.success_rate ?? '', c.pace ?? '', c.habit.pace ?? '', c.estimated_end ? time(c.estimated_end) : '',
      c.parcels_in_hand, c.cash_in_hand, c.flags.join(' ; ')]),
  ])
}

// Direct : une course change ou un livreur bouge → mise à jour (au plus toutes les 5 secondes)
let pending = null
function refreshSoon() {
  if (!isToday.value || pending) return
  pending = setTimeout(async () => {
    pending = null
    await load().catch(() => {})
    if (selected.value) await loadMissions(selected.value).catch(() => {})
  }, 5000)
}
function onLocation(payload) {
  const c = couriers.value.find((x) => x.id === payload.id)
  if (!c || !isToday.value) return
  Object.assign(c, { lat: payload.lat, lng: payload.lng, last_location_at: payload.last_location_at, is_available: payload.is_available })
}
watch(orderChanges, refreshSoon)
const channel = auth.user?.company_id ? `company.${auth.user.company_id}` : null

onMounted(async () => {
  await load()
  if (echo && channel) echo.private(channel).listen('.courier.location', onLocation)
  // Filet de sécurité si le direct est coupé
  timer = setInterval(() => { if (isToday.value) load().catch(() => {}) }, 120000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  clearTimeout(pending)
  if (echo && channel) echo.private(channel).stopListening('.courier.location', onLocation)
})
</script>
