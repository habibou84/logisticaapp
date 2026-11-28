<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <!-- Titre et, juste en face, le jour affiché : aujourd'hui par défaut (retards compris), gardé d'un onglet à l'autre -->
      <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <h1 class="text-xl font-bold">Courses</h1>
        <div
          v-if="isStaff" class="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Jour affiché"
          :title="filters.day ? 'Seulement les courses prévues ce jour (jours passés dans « Courses en retard »)' : null"
        >
          <Icone nom="date" taille="petite" class="text-slate-600" />
          <button
            v-for="d in DAY_CHOICES" :key="d.label" type="button"
            :class="['rounded-full px-3 py-1 ring-1', filters.day === d.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
            :aria-pressed="filters.day === d.value"
            @click="setDay(d.value)"
          >
            {{ d.label }}<span v-if="d.count" class="ml-1 opacity-75">({{ d.count }})</span>
          </button>
          <input
            type="date" class="input w-auto py-1"
            :value="filters.day && filters.day !== todayIso && filters.day !== tomorrowIso ? filters.day : ''"
            aria-label="Autre jour" title="Autre jour"
            @change="$event.target.value && setDay($event.target.value)"
          >
        </div>
      </div>
      <div class="flex gap-2">
        <RouterLink v-if="isStaff && auth.can('settings.manage')" to="/admin/reglages/courses" class="btn-secondary" title="Réglages des courses" aria-label="Réglages des courses"><Icone nom="reglages" /></RouterLink>
        <RouterLink v-if="auth.can('orders.create')" :to="`${auth.homeRoute}/courses/import`" class="btn-secondary"><Icone nom="importer" />Importer</RouterLink>
        <RouterLink v-if="auth.can('orders.create')" :to="`${auth.homeRoute}/courses/nouvelle`" :class="['btn-primary', isStaff ? 'hidden md:inline-flex' : '']">+ Nouvelle course</RouterLink>
      </div>
    </div>

    <!-- Courses, activité des livreurs, retours et retards : quatre cartes, celle-ci allumée -->
    <CoursesTabs v-if="isStaff" />

    <!-- Réglages manquants pour l'assignation automatique : bandeau d'action -->
    <AutoAssignBanner v-if="isStaff" />

    <DeliveryDayBar v-if="isStaff && auth.can('orders.dispatch')" @changed="load(1); dispatchCounts.fetchCounts().catch(() => {})" />

    <!-- Point du jour : livrées, argent (e-commerce), plis et accusés (distribution), MAD -->
    <DayPoint v-if="isStaff && isDispatch && filters.day" :day="filters.day" :activity="filters.activity" @delivered="showDelivered" />

    <!-- Cas à régler : cartes visibles seulement s'il y en a, jusqu'à ce qu'ils soient réglés -->
    <div v-if="isStaff && exceptionCards.length" class="grid grid-cols-2 gap-2 lg:grid-cols-4">
      <button
        v-for="e in exceptionCards" :key="e.value" type="button"
        :class="['card px-3 py-2 text-left text-sm', e.class, filters.queue === e.value ? 'ring-2 ring-slate-900' : '']"
        :aria-pressed="filters.queue === e.value"
        @click="setQueue(filters.queue === e.value ? '' : e.value)"
      >
        <b class="flex items-center gap-1.5"><Icone :nom="e.icon" taille="petite" />{{ e.count }} {{ e.count > 1 ? e.plural : e.label }}</b>
        <span class="block text-xs opacity-90">{{ e.hint }}</span>
      </button>
    </div>

    <!-- Activités côte à côte : toutes, ou une seule (e-commerce, distribution, MAD) -->
    <div v-if="activityChoices.length > 1" class="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="Activité">
      <span class="text-slate-600">Activité :</span>
      <button
        v-for="a in [{ value: '', label: 'Toutes' }, ...activityChoices]" :key="a.value" type="button"
        :class="['rounded-full px-3 py-1 ring-1', filters.activity === a.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        :aria-pressed="filters.activity === a.value"
        @click="setActivity(a.value)"
      >
        {{ a.label }}
      </button>
    </div>

    <!-- Étapes du colis : validation, ramassage, livraison ; les cases rouges demandent une action -->
    <div v-if="isStaff" class="space-y-2">
      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          :class="['rounded-full px-4 py-1.5 text-sm ring-1', filters.queue === '' ? 'bg-slate-900 font-semibold text-white ring-transparent' : 'bg-white ring-slate-400 hover:bg-slate-50']"
          :aria-pressed="filters.queue === ''" @click="setQueue('')"
        >
          Toutes les courses<template v-if="isDispatch && tabCount('') !== null"> · {{ tabCount('') }}</template>
        </button>
        <span class="hidden text-xs text-slate-600 sm:inline">Ou une étape : les cases rouges demandent une action.</span>
      </div>
      <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] 2xl:grid-cols-[var(--stage-cols)]" :style="{ '--stage-cols': stageCols }" role="group" aria-label="Étapes des courses">
        <section v-for="(st, i) in stages" :key="st.key" :class="['card space-y-2 p-3', st.key === 'delivery' ? 'sm:col-span-2 2xl:col-span-1' : '']" :aria-label="st.label">
          <p :class="['text-xs font-bold uppercase tracking-wide', STAGE_COLORS[st.key].title]">{{ i + 1 }}. {{ st.label }}</p>
          <div :class="['grid gap-2', CELL_GRID[st.items.length] || 'grid-cols-2']">
            <button
              v-for="q in st.items" :key="q.value" type="button"
              :class="['min-w-0 rounded-xl px-3 py-2 text-left transition', stageCellClass(st, q), filters.queue === q.value ? 'ring-2 ring-slate-900' : 'hover:brightness-95']"
              :aria-pressed="filters.queue === q.value" :title="q.hint" @click="setQueue(filters.queue === q.value ? '' : q.value)"
            >
              <span class="block truncate text-sm font-semibold">{{ q.label }}</span>
              <span v-if="isDispatch" class="block text-2xl font-extrabold tabular-nums">{{ tabCount(q.value) ?? '—' }}</span>
              <span v-if="isDispatch && tabLate(q.value)" class="block truncate text-xs font-semibold text-red-700">{{ tabLate(q.value) }} attendent trop</span>
              <span v-else-if="q.hint" class="hidden truncate text-xs opacity-80 sm:block">{{ q.hint }}</span>
            </button>
          </div>
        </section>
      </div>
    </div>

    <!-- Autres files : par client, retours, et celles propres à Logistica visibles seulement s'il y en a -->
    <div v-if="isStaff && visibleQueues.length" class="flex gap-2 overflow-x-auto no-scrollbar pb-1 [mask-image:linear-gradient(to_right,black_88%,transparent)] md:[mask-image:none] md:flex-wrap md:overflow-visible">
      <button
        v-for="q in visibleQueues"
        :key="q.value"
        :class="['btn whitespace-nowrap', filters.queue === q.value ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300']"
        @click="setQueue(filters.queue === q.value ? '' : q.value)"
      >
        <Icone v-if="q.icon" :nom="q.icon" taille="petite" />{{ q.label }}
        <span v-if="tabCount(q.value)" :class="['ml-1 rounded-full px-1.5 text-xs font-semibold', filters.queue === q.value ? 'bg-white/20' : 'bg-slate-100 text-slate-700']">{{ tabCount(q.value) }}</span>
        <span
          v-if="tabLate(q.value)"
          class="ml-1 rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white"
          :title="`${tabLate(q.value)} course(s) sans livreur depuis trop longtemps`"
        ><Icone nom="retard" taille="petite" class="inline align-[-0.15em]" /> {{ tabLate(q.value) }}</span>
      </button>
    </div>

    <!-- Sans livreur : ramassage, livraison ou les deux ; de la plus ancienne attente à la plus récente -->
    <div v-if="filters.queue === 'unassigned'" class="flex flex-wrap items-center gap-2 text-sm">
      <span class="text-slate-600">Afficher :</span>
      <button
        v-for="a in AWAITING" :key="a.value" type="button"
        :class="['rounded-full px-3 py-1 ring-1', filters.awaiting === a.value ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white ring-slate-300']"
        @click="setAwaiting(a.value)"
      >
        {{ a.label }}
      </button>
      <span class="text-slate-600">· de la plus ancienne attente à la plus récente</span>
    </div>

    <!-- Jour choisi : courses prévues plus tard hors de la file, à ajouter pour un ramassage en avance -->
    <p v-if="laterCount || (filters.include_later && LATER_QUEUES[filters.queue])" class="card px-3 py-2 text-sm bg-amber-50 border-amber-200 text-amber-900">
      <template v-if="!filters.include_later">
        + {{ laterCount }} {{ LATER_QUEUES[filters.queue] }} pour une livraison après le {{ date(filters.day) }}
        · <button type="button" class="font-medium underline" @click="toggleLater">Afficher</button>
      </template>
      <template v-else>
        Courses prévues plus tard affichées (étiquette <Icone nom="date" taille="petite" class="inline align-[-0.15em]" />)
        · <button type="button" class="font-medium underline" @click="toggleLater">Masquer</button>
      </template>
    </p>
    <p v-else-if="filters.day && ALL_DATES_QUEUES.includes(filters.queue)" class="text-xs text-slate-600">
      Cette file montre toutes les dates : chaque cas reste visible jusqu'à ce qu'il soit réglé.
    </p>

    <!-- Ramassages regroupés par client -->
    <PickupGroupsPanel v-if="byClient" :couriers="couriers" :day="filters.day" :activity="filters.activity" :include-later="filters.include_later" @assigned="dispatchCounts.fetchCounts().catch(() => {})" />

    <template v-else>
      <!-- Téléphone : recherche et filtres dans un panneau -->
      <div class="md:hidden flex gap-2">
        <input v-model="filters.search" class="input" placeholder="Code, téléphone, nom…" aria-label="Rechercher une course" @input="debouncedLoad">
        <button type="button" :class="['btn-secondary shrink-0', activeFilters ? 'ring-2 ring-slate-900' : '']" @click="filtersOpen = true">
          Filtres<span v-if="activeFilters">&nbsp;({{ activeFilters }})</span>
        </button>
      </div>
      <BottomSheet :open="filtersOpen" title="Filtres" @close="filtersOpen = false">
        <div class="space-y-3">
          <div>
            <label class="label" for="f-status">Statut</label>
            <select id="f-status" v-model="filters.status" class="input" @change="load(1)">
              <option value="">Tous les statuts</option>
              <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
              <option value="delivered_late">Livrées en retard (après la 1re date prévue)</option>
            </select>
          </div>
          <!-- Distribution, MAD : les courses d'un contrat ou d'un lot -->
          <div v-if="isStaff && plans.length">
            <label class="label" for="f-plan">Contrat / lot</label>
            <select id="f-plan" v-model="filters.billing_plan_id" class="input" @change="syncQuery(); load(1)">
              <option value="">Tous</option>
              <option v-for="p in plans" :key="p.id" :value="String(p.id)">{{ p.merchant?.business_name }} · {{ p.formula === 'distribution_lot' ? p.lot_name : p.formula_label }}</option>
            </select>
          </div>
          <div v-if="isStaff">
            <label class="label" for="f-merchant">Client</label>
            <SearchSelect
              id="f-merchant" v-model="filters.merchant_id" placeholder="Tous les clients" @change="load(1)"
              :options="merchants.map((m) => ({ value: String(m.id), label: m.business_name, search: m.phone }))"
            />
          </div>
          <div>
            <p class="label">Période <span class="font-normal text-slate-600">(remplace le jour)</span></p>
            <select v-if="isStaff" v-model="filters.date_basis" class="input mb-2" aria-label="Dates selon" @change="filters.from || filters.to ? onPeriod() : null">
              <option value="delivery">Selon la livraison</option>
              <option value="created">Selon la création</option>
            </select>
            <div class="grid grid-cols-2 gap-2">
              <input v-model="filters.from" type="date" class="input" aria-label="Du" @change="onPeriod">
              <input v-model="filters.to" type="date" class="input" aria-label="Au" @change="onPeriod">
            </div>
          </div>
          <div class="flex gap-2 pt-2">
            <button type="button" class="btn-secondary flex-1" @click="clearFilters">Effacer</button>
            <button type="button" class="btn-primary flex-1" @click="filtersOpen = false">Voir les courses</button>
          </div>
        </div>
      </BottomSheet>

      <div :class="['card p-3 hidden md:grid grid-cols-2 gap-2', isStaff ? (plans.length ? 'md:grid-cols-8' : 'md:grid-cols-7') : 'md:grid-cols-6']">
        <input v-model="filters.search" class="input col-span-2" placeholder="Code, téléphone, nom, référence…" @input="debouncedLoad">
        <select v-model="filters.status" class="input" @change="load(1)">
          <option value="">Tous les statuts</option>
          <option v-for="(label, value) in STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
          <option value="delivered_late">Livrées en retard (après la 1re date prévue)</option>
        </select>
        <select v-if="isStaff && plans.length" v-model="filters.billing_plan_id" class="input" aria-label="Contrat ou lot" @change="syncQuery(); load(1)">
          <option value="">Tous les contrats</option>
          <option v-for="p in plans" :key="p.id" :value="String(p.id)">{{ p.merchant?.business_name }} · {{ p.formula === 'distribution_lot' ? p.lot_name : p.formula_label }}</option>
        </select>
        <SearchSelect
          v-if="isStaff" v-model="filters.merchant_id" placeholder="Tous les clients" aria-label="Client" @change="load(1)"
          :options="merchants.map((m) => ({ value: String(m.id), label: m.business_name, search: m.phone }))"
        />
        <!-- Dates : jour de livraison prévu (par défaut) ou jour de création ; le client voit toujours le jour de livraison -->
        <select v-if="isStaff" v-model="filters.date_basis" class="input" aria-label="Dates selon" title="Dates : jour de livraison prévu ou jour de création" @change="filters.from || filters.to ? onPeriod() : null">
          <option value="delivery">Livraison</option>
          <option value="created">Création</option>
        </select>
        <input v-model="filters.from" type="date" class="input" :aria-label="filters.date_basis === 'created' ? 'Créées à partir du' : 'À livrer à partir du'" :title="filters.date_basis === 'created' ? 'Créées à partir du' : 'À livrer à partir du'" @change="onPeriod">
        <input v-model="filters.to" type="date" class="input" :aria-label="filters.date_basis === 'created' ? 'Créées jusqu\'au' : 'À livrer jusqu\'au'" :title="filters.date_basis === 'created' ? 'Créées jusqu\'au' : 'À livrer jusqu\'au'" @change="onPeriod">
      </div>

      <p v-if="filters.courier_id" class="text-sm">
        Filtré sur un livreur · <button class="text-blue-600" @click="filters.courier_id = ''; load(1)">retirer le filtre</button>
      </p>

      <!-- Actions groupées : validation, assignation -->
      <!-- Téléphone : barre fixée au-dessus des onglets, toujours à portée de pouce -->
      <div v-if="isStaff && selected.length" class="card p-3 flex flex-wrap items-center gap-2 bg-blue-50 border-blue-200 fixed inset-x-2 bottom-16 z-30 shadow-lg md:static md:shadow-none">
        <span class="text-sm font-medium">{{ selected.length }} sélectionnée(s)</span>
        <template v-if="auth.can('orders.dispatch') && pendingSelected.length">
          <button class="btn-success" :disabled="confirming" @click="bulkConfirm">
            <Icone v-if="!confirming" nom="valider" />{{ confirming ? 'Validation…' : `Valider (${pendingSelected.length})` }}
          </button>
          <span class="mx-1 h-6 w-px bg-blue-200" aria-hidden="true" />
        </template>
        <select v-model="bulk.type" class="input w-auto">
          <option v-if="filters.queue === 'unassigned'" value="auto">Selon la course (ramassage ou livraison)</option>
          <option value="pickup">Ramassage</option>
          <option value="delivery">Livraison</option>
          <option value="return">Retour</option>
        </select>
        <SearchSelect
          v-model="bulk.courier_id" input-class="input w-auto min-w-[14rem]" placeholder="Livreur…" :allow-empty="false" :empty-value="null" aria-label="Livreur"
          :options="couriers.map((c) => ({ value: c.id, label: c.name, hint: `${c.active_assignments_count} en cours${c.is_available ? '' : ' · indisponible'}${madSuffix(c)}`, search: c.phone }))"
        />
        <button class="btn-primary" :disabled="!bulk.courier_id || assigning" @click="bulkAssign">Assigner</button>
        <button v-if="auth.can('orders.dispatch') && withCourierSelected.length" class="btn-secondary text-red-700" :disabled="assigning" @click="bulkUnassign">
          Retirer le livreur ({{ withCourierSelected.length }})
        </button>
        <!-- Distribution : liste à faire signer à chaque remise -->
        <a :href="`/feuille-de-route?ids=${selected.join(',')}`" target="_blank" class="btn-secondary"><Icone nom="imprimer" />Feuille de route</a>
        <a :href="`/etiquettes?ids=${selected.join(',')}`" target="_blank" class="btn-secondary"><Icone nom="etiquette" />Étiquettes</a>
        <button class="btn-secondary" @click="selected = []">Annuler</button>
      </div>

      <!-- Téléphone : une carte par course -->
      <div class="md:hidden space-y-2">
        <p v-if="loading" class="card p-6 text-center text-slate-600">Chargement…</p>
        <p v-else-if="!orders.length" class="card p-6 text-center text-slate-600">Aucune course.</p>
        <article
          v-for="o in orders"
          v-else
          :key="o.id"
          :class="['card p-3 space-y-1.5 cursor-pointer', highlighted === o.id ? 'bg-yellow-50' : o.awaiting_courier?.late ? 'bg-red-50/70' : '']"
          @click="open(o)"
        >
          <div class="flex items-center justify-between gap-2">
            <label v-if="isStaff" class="flex items-center gap-2 min-w-0" @click.stop>
              <input v-model="selected" type="checkbox" :value="o.id" :aria-label="`Sélectionner ${o.tracking_code}`">
              <span class="font-mono text-xs truncate">{{ o.tracking_code }}</span>
              <span v-if="activityChoices.length > 1" :class="['rounded-full px-1.5 text-xs font-medium', activityBadge(o.activity)]">{{ activityShort(o.activity) }}</span>
            </label>
            <span v-else class="font-mono text-xs">{{ o.tracking_code }}<span v-if="activityChoices.length > 1" :class="['ml-1 rounded-full px-1.5 text-xs font-medium font-sans', activityBadge(o.activity)]">{{ activityShort(o.activity) }}</span></span>
            <span v-if="o.billed_monthly" class="text-xs text-indigo-700 whitespace-nowrap">Facturé au mois</span>
            <span v-else class="font-semibold whitespace-nowrap">{{ money(o.amounts.cod_amount) }}</span>
          </div>
          <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
              <div class="font-medium truncate">{{ o.recipient.name || '—' }}</div>
              <div class="text-xs text-slate-600">{{ o.recipient.phone }}</div>
            </div>
            <a v-if="o.recipient.phone" :href="`tel:${o.recipient.phone}`" class="tap shrink-0 grid h-10 w-10 place-items-center rounded-full bg-slate-100 text-lg" :aria-label="`Appeler ${o.recipient.name || 'le destinataire'}`" @click.stop><Icone nom="appeler" /></a>
          </div>
          <div class="text-xs text-slate-600">{{ o.pickup.zone_name }} → {{ o.delivery.zone_name }}<span v-if="isStaff && o.merchant"> · {{ o.merchant.business_name }}</span></div>
          <OrderStatusCell :order="o" :late="isLate(o)" :carried="isCarried(o)" :late-day="deliveryDay(o)" @decide="deciding = $event" />
          <CourierCell v-if="o.pickup_courier || o.delivery_courier || o.held_by || (isDispatch && o.awaiting_courier)" :order="o" :couriers="couriers" :can-dispatch="isDispatch" class="text-slate-700" @updated="replaceOrder" />
          <div v-if="o.internal_late || o.internal_risk" :class="['text-xs font-medium', o.internal_late ? 'text-red-600' : 'text-amber-700']"><Icone :nom="o.internal_late ? 'rappel' : 'alerte'" taille="petite" class="inline align-[-0.15em]" /> {{ o.internal_late ? 'Délai interne dépassé' : 'Risque de retard' }} ({{ dateTime(o.internal_due_at) }})</div>
          <div class="text-xs text-slate-500">Livraison {{ date(o.delivery.scheduled_date || o.created_at) }} · créée {{ dateTime(o.created_at) }}</div>
        </article>
      </div>

      <div class="card overflow-x-auto hidden md:block">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
              <th v-if="isStaff" class="p-2 w-8"><input type="checkbox" :checked="allSelected" aria-label="Tout sélectionner" @change="toggleAll"></th>
              <th class="p-2">Code</th>
              <th v-if="isStaff" class="p-2">Client</th>
              <th class="p-2">Destinataire</th>
              <th class="p-2">Trajet</th>
              <th class="p-2">Statut</th>
              <th class="p-2">Livreur</th>
              <th class="p-2 text-right">À encaisser</th>
              <th class="p-2">Livraison · création</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-if="loading"><td colspan="9" class="p-6 text-center text-slate-600">Chargement…</td></tr>
            <tr v-else-if="!orders.length"><td colspan="9" class="p-6 text-center text-slate-600">Aucune course.</td></tr>
            <tr
              v-for="o in orders"
              v-else
              :key="o.id"
              :class="['hover:bg-slate-50 cursor-pointer', highlighted === o.id ? 'bg-yellow-50' : o.awaiting_courier?.late ? 'bg-red-50/70' : '']"
              @click="open(o)"
            >
              <td v-if="isStaff" class="p-2" @click.stop><input v-model="selected" type="checkbox" :value="o.id" :aria-label="`Sélectionner ${o.tracking_code}`"></td>
              <td class="p-2 font-mono text-xs">
                {{ o.tracking_code }}
                <div v-if="o.internal_late || o.internal_risk" :class="['font-sans font-medium', o.internal_late ? 'text-red-600' : 'text-amber-700']" :title="`Délai interne : ${dateTime(o.internal_due_at)}`"><Icone :nom="o.internal_late ? 'rappel' : 'alerte'" taille="petite" class="inline align-[-0.15em]" /> {{ o.internal_late ? 'délai dépassé' : 'risque de retard' }}</div>
                <div v-if="activityChoices.length > 1"><span :class="['rounded-full px-1.5 font-sans font-medium', activityBadge(o.activity)]">{{ activityShort(o.activity) }}</span></div>
              </td>
              <td v-if="isStaff" class="p-2">{{ o.merchant?.business_name }}</td>
              <td class="p-2">
                <div>{{ o.recipient.name || '—' }}</div>
                <div class="text-xs text-slate-600">{{ o.recipient.phone }}</div>
              </td>
              <td class="p-2 whitespace-nowrap">{{ o.pickup.zone_name }} → {{ o.delivery.zone_name }}</td>
              <td class="p-2">
                <OrderStatusCell :order="o" :late="isLate(o)" :carried="isCarried(o)" :late-day="deliveryDay(o)" @decide="deciding = $event" />
              </td>
              <td class="p-2" @click.stop>
                <CourierCell :order="o" :couriers="couriers" :can-dispatch="isDispatch" @updated="replaceOrder" />
              </td>
              <!-- Distribution, MAD : rien à encaisser, la course passe sur la facture du mois -->
              <td class="p-2 text-right whitespace-nowrap">
                <span v-if="o.billed_monthly" class="text-xs text-indigo-700">Facturé au mois</span>
                <template v-else>{{ money(o.amounts.cod_amount) }}</template>
              </td>
              <td class="p-2 text-xs whitespace-nowrap">
                <!-- Jour de livraison : la date prévue, sinon le jour de création -->
                <div class="font-medium text-slate-800">{{ date(o.delivery.scheduled_date || o.created_at) }}</div>
                <div class="text-slate-600">créée {{ dateTime(o.created_at) }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :meta="meta" @change="load" />
    </template>

    <OrderDecision :open="!!deciding" :order="deciding" :couriers="couriers" @close="deciding = null" @decided="decided" />
  </div>
</template>

<script setup>
import Icone from '../../components/ui/Icone.vue'
import SearchSelect from '../../components/SearchSelect.vue'
import { madSuffix } from '../../utils/mad'
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import OrderStatusCell from '../../components/OrderStatusCell.vue'
import BottomSheet from '../../components/mobile/BottomSheet.vue'
import Pagination from '../../components/Pagination.vue'
import OrderDecision from '../../components/OrderDecision.vue'
import CourierCell from '../../components/CourierCell.vue'
import PickupGroupsPanel from '../../components/PickupGroupsPanel.vue'
import DeliveryDayBar from '../../components/DeliveryDayBar.vue'
import AutoAssignBanner from '../../components/AutoAssignBanner.vue'
import CoursesTabs from '../../components/CoursesTabs.vue'
import DayPoint from '../../components/DayPoint.vue'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { useDispatchCountStore } from '../../stores/dispatchCounts'
import { orderListChanges, lastOrderChange } from '../../composables/useRealtime'
import { collectsOnly, date, dateTime, money, STATUS_LABELS } from '../../utils/format'
import { ACTIVITIES, activityBadge, activityShort, myActivities } from '../../utils/activities'
import { confirmDialog, promptDialog } from '../../composables/useDialog'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const route = useRoute()

const isStaff = computed(() => !auth.user?.merchant_id)

const isoDay = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const todayIso = isoDay(new Date())
const tomorrowIso = isoDay(new Date(Date.now() + 86400000))
// Jour de livraison : la date prévue, sinon le jour de création
const deliveryDay = (o) => (o.delivery.scheduled_date || o.created_at || '').slice(0, 10)
const FINAL = ['delivered', 'cancelled', 'rejected', 'returned', 'lost']
// Jour affiché par l'agence : aujourd'hui par défaut, « all » dans l'adresse pour toutes les dates
const initialDay = () => {
  if (!auth.user || auth.user.merchant_id) return ''
  const q = route.query.day
  if (q === 'all') return ''
  return /^\d{4}-\d{2}-\d{2}$/.test(q || '') ? q : todayIso
}
// Files de travail (limitées au jour) ; on peut y ajouter les courses prévues plus tard (ramassage en avance)
const LATER_QUEUES = { to_pickup: 'à ramasser', by_client: 'à ramasser', to_prepare: 'à préparer', unassigned: 'à ramasser', pickup_unassigned: 'à ramasser' }

// Étapes du colis (cases au-dessus de la liste), dans l'ordre du parcours ; « action » : rouge quand il y en a
const pick = computed(() => (collectsOnly(myActivities(auth.user).map((a) => a.value)) ? 'Collecte' : 'Ramassage'))
const STAGES = computed(() => [
  { key: 'validation', label: 'Validation', items: [{ value: 'to_confirm', label: 'À valider', action: true }] },
  { key: 'pickup', label: pick.value, items: [
    { value: 'pickup_unassigned', label: 'À assigner', hint: 'sans livreur', action: true },
    { value: 'to_pickup', label: 'En cours', hint: 'livreur désigné' },
    // Commandes stockées à l'entrepôt : préparées au lieu d'être ramassées
    { value: 'to_prepare', label: 'À préparer', hint: 'au stock' },
  ] },
  { key: 'delivery', label: 'Livraison', items: [
    { value: 'delivery_unassigned', label: 'À assigner', hint: 'sans livreur', action: true },
    { value: 'delivery_assigned', label: 'Assignées', hint: 'pas encore parties' },
    { value: 'on_the_way', label: 'En chemin', hint: 'chez le livreur' },
    { value: 'delivered', label: 'Livrées', done: true },
    { value: 'failed', label: 'Échecs', hint: 'à relivrer', action: true },
  ] },
])
const CELL_GRID = { 1: 'grid-cols-1', 2: 'grid-cols-2', 3: 'grid-cols-3', 4: 'grid-cols-2 sm:grid-cols-4', 5: 'grid-cols-3 sm:grid-cols-5' }
const STAGE_COLORS = {
  validation: { title: 'text-violet-700', cell: 'bg-violet-50 text-violet-900' },
  pickup: { title: 'text-amber-700', cell: 'bg-amber-50 text-amber-900' },
  delivery: { title: 'text-blue-700', cell: 'bg-blue-50 text-blue-900' },
}
// « À préparer » : seulement avec un stock (ou s'il reste des commandes à préparer)
const stages = computed(() => STAGES.value.map((st) => ({ ...st, items: st.items.filter((q) => q.value !== 'to_prepare' || auth.can('stock.manage') || tabCount('to_prepare') > 0 || filters.queue === 'to_prepare') })))
// Largeur de chaque étape selon son nombre de cases (grand écran : une seule ligne)
const stageCols = computed(() => stages.value.map((st) => `minmax(0,${st.items.length + 0.4}fr)`).join(' '))
function stageCellClass(st, q) {
  if (q.action && isDispatch.value && tabCount(q.value) > 0) return 'bg-red-50 text-red-900 ring-1 ring-red-200'
  if (q.done) return 'bg-emerald-50 text-emerald-900'
  return STAGE_COLORS[st.key].cell
}

// Files sous les étapes
const queues = [
  // Sans livreur pour le ramassage, regroupés par client et adresse (lot à assigner d'un coup)
  { value: 'by_client', icon: 'marchand', label: 'Ramassages par client' },
  // Liens venus d'ailleurs (tableau de bord) : visibles quand on y est
  { value: 'unassigned', icon: 'livreur', label: 'Sans livreur', linked: true },
  { value: 'to_deliver', label: 'À livrer', linked: true },
  // Files toutes dates, visibles seulement s'il y en a
  // Colis à rendre, pas encore sur un bon (même liste que Retours clients)
  { value: 'to_return', icon: 'retour', label: 'À retourner', extra: true },
  { value: 'to_approve', icon: 'entreprise', label: 'À approuver', extra: true },
  // Reports et retours signalés par les livreurs, à valider avant qu'ils prennent effet
  { value: 'to_validate', icon: 'valider', label: 'À valider (livreurs)', extra: true },
  { value: 'to_decide', label: 'À décider', extra: true },
  // Notes de suivi de l'équipe (Réglages › Motifs) ; « En attente » : celles qui retiennent la course
  { value: 'on_hold', icon: 'pause', label: 'En attente', extra: true },
  { value: 'follow_up', icon: 'epingle', label: 'Suivi', extra: true },
  // Délai interne de distribution dépassé
  { value: 'internal_late', icon: 'rappel', label: 'Délai interne dépassé', extra: true },
  { value: 'incidents', label: 'Incidents', extra: true },
  { value: 'scheduled', label: 'Prévues plus tard', extra: true },
  // Hors onglets : carte « à régler » et page « Courses en retard »
  { value: 'code_to_verify', icon: 'alerte', label: 'Codes à vérifier', hidden: true },
  { value: 'untreated', icon: 'sablier', label: 'Non traitées', hidden: true },
]
// Files qui montrent toutes les dates, même quand un jour est choisi
const ALL_DATES_QUEUES = queues.filter((q) => q.extra || q.hidden).map((q) => q.value)

// Files propres à Logistica : seulement quand il y en a (ou quand on y est) ; « Par client » réservé au dispatch
const visibleQueues = computed(() => queues.filter((q) => {
  if (q.hidden) return false
  if (q.linked) return filters.queue === q.value
  if (q.value === 'by_client') return isDispatch.value
  if (q.extra) return filters.queue === q.value || exceptionCount(q.value) > 0
  return true
}))
// Cas à régler, toutes dates : en cartes de couleur au-dessus des onglets, seulement quand il y en a
const EXCEPTIONS = [
  { value: 'code_to_verify', icon: 'alerte', label: 'code à vérifier', plural: 'codes à vérifier', hint: 'livrées hors connexion avec un code faux', class: 'border-red-300 bg-red-50 text-red-900' },
]
const exceptionCount = (queue) => (isDispatch.value ? dispatchCounts.counts.exceptions?.[queue] || 0 : 0)
const exceptionCards = computed(() => EXCEPTIONS.map((e) => ({ ...e, count: exceptionCount(e.value) })).filter((e) => e.count > 0 || filters.queue === e.value))

const filters = reactive({
  queue: route.query.queue || '',
  search: route.query.search || '',
  status: '',
  merchant_id: route.query.merchant_id || '',
  activity: route.query.activity || '',
  billing_plan_id: route.query.billing_plan_id || '',
  courier_id: route.query.courier_id || '',
  // File « Sans livreur » : pickup, delivery ou les deux
  awaiting: route.query.awaiting || '',
  from: '',
  to: '',
  // Jour de travail (AAAA-MM-JJ) ; vide : toutes les dates
  day: initialDay(),
  // Files de travail : ajouter les courses à livrer après ce jour
  include_later: false,
  // Période selon le jour de livraison prévu (sinon le jour de création) ou selon le jour de création
  date_basis: route.query.date_basis === 'created' ? 'created' : 'delivery',
})

const orders = ref([])
// Activités entre lesquelles filtrer : son périmètre (équipe) ou celles de son entreprise (client)
const activityChoices = computed(() => (auth.user?.merchant_id
  ? ACTIVITIES.filter((a) => (auth.user.merchant?.activities || []).includes(a.value))
  : myActivities(auth.user)))
function setActivity(value) {
  filters.activity = value
  // Cases des étapes, jours et files : compteurs de cette activité seulement
  dispatchCounts.activity = value || null
  syncQuery()
  load(1)
}
const meta = ref(null)
const loading = ref(false)
const merchants = ref([])
// Contrats et lots en cours (filtre « Contrat / lot »)
const plans = ref([])
const couriers = ref([])
const selected = ref([])
const bulk = reactive({ type: route.query.queue === 'unassigned' ? 'auto' : 'pickup', courier_id: null })
const AWAITING = [
  { value: '', label: 'Les deux' },
  { value: 'pickup', label: 'Ramassage' },
  { value: 'delivery', label: 'Livraison' },
]
const assigning = ref(false)
// Téléphone : panneau des filtres et nombre de filtres actifs
const filtersOpen = ref(false)
const activeFilters = computed(() => [filters.status, filters.merchant_id, filters.from || filters.to].filter(Boolean).length)
function clearFilters() {
  filters.status = ''
  filters.merchant_id = ''
  filters.from = ''
  filters.to = ''
  load(1)
}
const confirming = ref(false)
// Courses sélectionnées encore en attente de validation
const pendingSelected = computed(() => selected.value.filter((id) => orders.value.find((o) => o.id === id)?.status === 'pending'))
const highlighted = ref(null)
// Courses sélectionnées qui ont un livreur (ramassage, livraison ou retour)
const withCourierSelected = computed(() => selected.value.filter((id) => {
  const o = orders.value.find((x) => x.id === id)
  return o && (o.pickup_courier || o.delivery_courier || o.return_courier)
}))

const allSelected = computed(() => orders.value.length > 0 && selected.value.length === orders.value.length)

// Compteurs des files et courses en attente d'un livreur depuis trop longtemps
const dispatchCounts = useDispatchCountStore()
const isDispatch = computed(() => auth.can('orders.dispatch'))
const COUNTED = { to_confirm: 'to_confirm', to_pickup: 'to_pickup', to_deliver: 'to_deliver', unassigned: 'unassigned' }
// Jour choisi : compteurs de ce jour (retards compris) ; sinon compteurs généraux
const DAY_COUNTED = { '': 'all', to_confirm: 'to_confirm', to_pickup: 'to_pickup', to_prepare: 'to_prepare', to_deliver: 'to_deliver', unassigned: 'unassigned', failed: 'failed', to_return: 'to_return', pickup_unassigned: 'pickup_unassigned', delivery_unassigned: 'delivery_unassigned', delivery_assigned: 'delivery_assigned', on_the_way: 'on_the_way', delivered: 'delivered' }
// Toutes les dates : étapes sans compteur général (trait à la place du chiffre)
const DAY_ONLY = ['', 'pickup_unassigned', 'delivery_unassigned', 'delivery_assigned', 'on_the_way', 'delivered']
const dayCounts = computed(() => (filters.day && dispatchCounts.dayCounts?.date === filters.day ? dispatchCounts.dayCounts : null))
const tabCount = (queue) => {
  if (!isDispatch.value) return 0
  if (ALL_DATES_QUEUES.includes(queue)) return exceptionCount(queue)
  if (queue === 'by_client') return 0
  if (dayCounts.value) return dayCounts.value[DAY_COUNTED[queue]] || 0
  // Jour choisi, compteurs du jour pas encore arrivés : rien plutôt que le total de toutes les dates
  if (filters.day) return 0
  if (DAY_ONLY.includes(queue)) return null
  return COUNTED[queue] ? dispatchCounts.counts[COUNTED[queue]] : exceptionCount(queue)
}
// Courses prévues après le jour choisi, hors de la file tant qu'on ne les demande pas
const laterCount = computed(() => {
  if (!filters.day || !LATER_QUEUES[filters.queue]) return 0
  return dayCounts.value?.later?.[filters.queue === 'by_client' ? 'unassigned' : filters.queue] || 0
})
// En retard : le jour de livraison est passé et la course est toujours en cours
// Jour choisi : une course d'un jour passé qui y figure est encore dans sa règle (lot pas échu, MAD), pas en retard
const isCarried = (o) => !!filters.day && !ALL_DATES_QUEUES.includes(filters.queue) && !FINAL.includes(o.status) && deliveryDay(o) < todayIso
const isLate = (o) => !isCarried(o) && !FINAL.includes(o.status) && deliveryDay(o) < todayIso && (!!filters.day || filters.queue === 'untreated')
const DAY_CHOICES = computed(() => [
  { value: todayIso, label: 'Aujourd\'hui', count: dispatchCounts.dayCounts?.days?.today },
  { value: tomorrowIso, label: 'Demain', count: dispatchCounts.dayCounts?.days?.tomorrow },
  { value: '', label: 'Toutes les dates' },
])
const tabLate = (queue) => {
  if (!isDispatch.value) return 0
  const late = { pickup_unassigned: dispatchCounts.counts.late.pickup, delivery_unassigned: dispatchCounts.counts.late.delivery, to_deliver: dispatchCounts.counts.late.delivery, unassigned: dispatchCounts.counts.late.total }[queue] || 0
  // Jour choisi : pas plus que la file n'en montre (les autres sont dans « prévues plus tard »)
  return dayCounts.value ? Math.min(late, tabCount(queue)) : late
}


// Alerte « sans livreur » reçue : la liste se met à jour
watch(() => dispatchCounts.version, () => load(meta.value?.current_page || 1))

const byClient = computed(() => filters.queue === 'by_client')

async function load(page = 1) {
  if (byClient.value) {
    if (isDispatch.value) dispatchCounts.fetchCounts().catch(() => {})
    return
  }
  loading.value = orders.value.length === 0
  const params = { page }
  for (const [k, v] of Object.entries(filters)) {
    if (v) params[k] = k === 'status' ? [v] : (k === 'include_later' ? 1 : v)
  }
  // Livrées en retard : livrées après leur première date prévue
  if (filters.status === 'delivered_late') {
    delete params.status
    params.delivered_late = 1
  }
  const { data } = await http.get('/orders', { params })
  orders.value = data.data
  meta.value = data.meta
  loading.value = false
  if (isDispatch.value) dispatchCounts.fetchCounts().catch(() => {})
  selected.value = selected.value.filter((id) => orders.value.some((o) => o.id === id))
}

let timer
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
}

// Adresse de la page : file, étape attendue et jour (gardé d'un onglet à l'autre)
function syncQuery() {
  const query = {}
  if (filters.queue) query.queue = filters.queue
  if (filters.queue === 'unassigned' && filters.awaiting) query.awaiting = filters.awaiting
  if (isStaff.value && filters.day !== todayIso) query.day = filters.day || 'all'
  if (filters.activity) query.activity = filters.activity
  if (filters.billing_plan_id) query.billing_plan_id = filters.billing_plan_id
  router.replace({ query })
}

function setQueue(value) {
  filters.queue = value
  filters.awaiting = ''
  filters.include_later = false
  bulk.type = { to_deliver: 'delivery', delivery_unassigned: 'delivery', delivery_assigned: 'delivery', failed: 'delivery', incidents: 'delivery', to_return: 'return', unassigned: 'auto' }[value] || 'pickup'
  syncQuery()
  load(1)
}

function setAwaiting(value) {
  filters.awaiting = value
  bulk.type = value || 'auto'
  syncQuery()
  load(1)
}

// Jour choisi : remplace la période ; les courses prévues plus tard redeviennent masquées
function setDay(value) {
  filters.day = value
  filters.from = ''
  filters.to = ''
  filters.include_later = false
  dispatchCounts.day = value || null
  syncQuery()
  load(1)
}

// Période choisie : elle remplace le jour
function onPeriod() {
  if (filters.day) {
    filters.day = ''
    dispatchCounts.day = null
    syncQuery()
  }
  load(1)
}

// Point du jour : « Livrées » ouvre les courses livrées du jour
function showDelivered() {
  filters.status = 'delivered'
  setQueue('')
}

function toggleLater() {
  filters.include_later = !filters.include_later
  load(1)
}

function toggleAll(e) {
  selected.value = e.target.checked ? orders.value.map((o) => o.id) : []
}

// Livreur changé depuis la liste : la ligne reprend la course à jour
function replaceOrder(order) {
  const i = orders.value.findIndex((x) => x.id === order.id)
  if (i >= 0) orders.value[i] = { ...orders.value[i], ...order }
  dispatchCounts.fetchCounts().catch(() => {})
}

// Suite à donner à un colis non livré
const deciding = ref(null)
function decided(order) {
  deciding.value = null
  toasts.success(order.status === 'rescheduled' ? `Relivraison prévue le ${date(order.delivery.scheduled_date)}.` : (order.return_requested ? 'Retour au client décidé.' : 'Décision enregistrée.'))
  load(meta.value?.current_page || 1)
}

function open(order) {
  router.push(`${auth.homeRoute}/courses/${order.id}`)
}

async function bulkConfirm() {
  const count = pendingSelected.value.length
  if (!(await confirmDialog(`Valider ${count} course(s) en attente ?`))) return
  confirming.value = true
  try {
    const { data } = await http.post('/orders/bulk-confirm', { order_ids: pendingSelected.value })
    if (data.data.confirmed.length) toasts.success(`${data.data.confirmed.length} course(s) validée(s).`)
    Object.entries(data.data.errors).forEach(([id, message]) => {
      const code = orders.value.find((o) => o.id === Number(id))?.tracking_code || id
      toasts.error(`${code} : ${message}`)
    })
    // Encore affichées (file « Toutes ») : elles restent sélectionnées, prêtes à être assignées
    await load(meta.value?.current_page || 1)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    confirming.value = false
  }
}

async function bulkAssign() {
  assigning.value = true
  try {
    // « Selon la course » : ramassage pour les courses à ramasser, livraison pour les colis à livrer
    const groups = bulk.type === 'auto'
      ? Object.entries(selected.value.reduce((acc, id) => {
        const stage = orders.value.find((o) => o.id === id)?.awaiting_courier?.stage || 'pickup'
        ;(acc[stage] ||= []).push(id)
        return acc
      }, {}))
      : [[bulk.type, selected.value]]
    const result = { assigned: [], errors: {} }
    for (const [type, ids] of groups) {
      const { data } = await http.post('/orders/bulk-assign', { order_ids: ids, type, courier_id: bulk.courier_id })
      result.assigned.push(...data.data.assigned)
      Object.assign(result.errors, data.data.errors)
    }
    const data = { data: result }
    const errors = Object.entries(data.data.errors)
    if (data.data.assigned.length) toasts.success(`${data.data.assigned.length} course(s) assignée(s).`)
    errors.forEach(([id, message]) => {
      const code = orders.value.find((o) => o.id === Number(id))?.tracking_code || id
      toasts.error(`${code} : ${message}`)
    })
    selected.value = []
    await load(meta.value?.current_page || 1)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    assigning.value = false
  }
}

async function bulkUnassign() {
  const ids = withCourierSelected.value
  // « Selon la course » : la mission de l'étape en cours de chaque course
  const type = bulk.type === 'auto' ? undefined : bulk.type
  const what = type ? `du ${{ pickup: 'ramassage', delivery: 'livraison', return: 'retour' }[type]}` : 'de l\'étape en cours'
  const reason = (await promptDialog(`Retirer le livreur ${what} sur ${ids.length} course(s) ? Les livreurs seront prévenus.\nMotif (facultatif) :`, ''))
  if (reason === null) return
  assigning.value = true
  try {
    const { data } = await http.post('/orders/bulk-unassign', { order_ids: ids, type, reason: reason.trim() || undefined })
    if (data.data.unassigned.length) toasts.success(`Livreur retiré sur ${data.data.unassigned.length} course(s).`)
    Object.entries(data.data.errors).forEach(([id, message]) => {
      const code = orders.value.find((o) => o.id === Number(id))?.tracking_code || id
      toasts.error(`${code} : ${message}`)
    })
    selected.value = []
    await load(meta.value?.current_page || 1)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    assigning.value = false
  }
}

watch(() => route.query.search, (value) => {
  if (value === undefined) return
  filters.search = value
  load(1)
})

// Ailleurs (menu, tableau de bord) : compteurs généraux seulement
onUnmounted(() => { dispatchCounts.day = null; dispatchCounts.activity = null })

// Rafraîchissement temps réel
watch(orderListChanges, async () => {
  await load(meta.value?.current_page || 1)
  highlighted.value = lastOrderChange.value?.order?.id
  setTimeout(() => { highlighted.value = null }, 3000)
})

onMounted(async () => {
  dispatchCounts.day = filters.day || null
  dispatchCounts.activity = filters.activity || null
  await load()
  if (isStaff.value) {
    const empty = { data: { data: [] } }
    const [m, c] = await Promise.all([
      http.get('/merchants', { params: { per_page: 200 } }).catch(() => empty),
      auth.can('orders.dispatch') ? http.get('/couriers') : Promise.resolve(empty),
    ])
    merchants.value = m.data.data
    couriers.value = c.data.data
    if (myActivities(auth.user).some((a) => a.value !== 'ecommerce') && auth.can('merchants.view')) {
      plans.value = (await http.get('/billing-plans', { params: { state: 'current' } }).catch(() => empty)).data.data
    }
  }
})
</script>
