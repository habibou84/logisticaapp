<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Clients</h1>
        <p class="text-sm text-slate-600">Cliquez sur un client pour ouvrir sa fiche.</p>
      </div>
      <button v-if="auth.can('merchants.manage')" type="button" class="btn-primary" @click="openNew">+ Nouveau client</button>
    </div>

    <ConnectRequests v-if="auth.can('merchants.manage')" @accepted="load(meta?.current_page)" />

    <!-- Résumé : chaque case filtre la liste -->
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
      <button v-for="k in kpis" :key="k.key" type="button" :aria-pressed="k.active" :class="['card px-3 py-2 text-left transition hover:border-slate-400', k.active ? 'ring-2 ring-blue-500' : '', k.alert ? 'border-amber-200 bg-amber-50' : '']" @click="k.apply()">
        <span :class="['block text-xs', k.alert ? 'text-amber-900' : 'text-slate-600']">{{ k.label }}</span>
        <span class="block text-xl font-bold tabular-nums">{{ k.value }}</span>
      </button>
    </div>

    <!-- Filtres -->
    <div class="card flex flex-wrap items-end gap-2 p-3">
      <div class="min-w-[12rem] flex-1">
        <label class="label" for="m-search">Recherche</label>
        <input id="m-search" v-model="f.search" type="search" class="input" placeholder="Nom, contact ou téléphone" @input="debounced">
      </div>
      <button type="button" class="btn-secondary md:hidden" :aria-expanded="moreFilters" @click="moreFilters = !moreFilters">Filtres{{ activeFilters ? ` (${activeFilters})` : '' }}</button>
      <div :class="[moreFilters ? 'flex' : 'hidden', 'w-full flex-wrap items-end gap-2 md:flex md:w-auto']">
        <div v-if="activities.length > 1">
          <label class="label" for="m-activity">Activité</label>
          <select id="m-activity" v-model="f.activity" class="input" @change="reload">
            <option value="">Toutes</option>
            <option v-for="a in activities" :key="a.value" :value="a.value">{{ a.label }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="m-status">Statut</label>
          <select id="m-status" v-model="f.status" class="input" @change="reload">
            <option value="">Tous</option><option value="active">Actifs</option><option value="suspended">Suspendus</option>
          </select>
        </div>
        <div>
          <label class="label" for="m-source">Arrivée</label>
          <select id="m-source" v-model="f.source" class="input" @change="reload">
            <option value="">Tous</option><option value="signup">Inscrits en ligne</option><option value="backoffice">Ajoutés par l'agence</option>
          </select>
        </div>
        <div>
          <label class="label" for="m-zone">Zone de ramassage</label>
          <select id="m-zone" v-model="f.zone_id" class="input max-w-[14rem]" @change="reload">
            <option value="">Toutes</option>
            <option v-for="z in zones" :key="z.id" :value="String(z.id)">{{ z.full_name }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="m-sort">Trier par</label>
          <select id="m-sort" v-model="order" class="input" @change="reload">
            <option value="name:asc">Nom</option><option value="orders:desc">Nombre de courses</option><option value="created_at:desc">Les plus récents</option>
          </select>
        </div>
      </div>
      <button v-if="filtered" type="button" class="btn-secondary" @click="reset">Effacer</button>
    </div>

    <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</p>

    <div class="card overflow-hidden">
      <!-- Ordinateur : tableau -->
      <div class="hidden overflow-x-auto md:block">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-600">
            <tr>
              <th class="px-3 py-2.5">Client</th><th class="px-3 py-2.5">Contact</th><th class="px-3 py-2.5">Ramassage</th>
              <th class="px-3 py-2.5">Activités · facturation</th><th class="px-3 py-2.5 text-right">Courses</th><th class="px-3 py-2.5">Statut</th>
              <th class="px-3 py-2.5"><span class="sr-only">Ouvrir</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="m in rows" :key="m.id" :class="['cursor-pointer align-top hover:bg-slate-50', m.status === 'active' ? '' : 'opacity-70']" @click="openMerchant(m)">
              <td class="px-3 py-2.5">
                <span class="block font-semibold text-slate-900">{{ m.business_name }}</span>
                <span v-if="m.source === 'signup'" class="mt-0.5 inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800" :title="`Inscrit en ligne le ${day(m.created_at)}`">
                  {{ isNew(m) ? 'Nouveau · ' : '' }}inscrit en ligne
                </span>
              </td>
              <td class="px-3 py-2.5">{{ m.contact_name || '—' }}<span class="block text-xs text-slate-600">{{ m.phone }}</span></td>
              <td class="max-w-[16rem] px-3 py-2.5">
                {{ m.pickup_zone?.name || '—' }}
                <span v-if="m.pickup_address" class="block truncate text-xs text-slate-600">{{ m.pickup_address }}</span>
                <span :class="['block text-xs', m.pickup_lat == null ? 'font-semibold text-amber-800' : m.pickup_location_source === 'courier' ? 'text-sky-700' : 'text-emerald-700']">
                  <Icone :nom="m.pickup_lat == null ? 'alerte' : 'adresse'" taille="petite" class="inline" /> {{ m.pickup_lat == null ? 'sans position' : (SOURCES[m.pickup_location_source] || 'localisé') }}
                </span>
              </td>
              <td class="px-3 py-2.5">
                <span class="flex flex-wrap gap-1">
                  <span v-for="a in m.activities" :key="a" :class="['rounded-full px-2 py-0.5 text-xs font-medium', activityBadge(a)]">{{ activityShort(a) }}</span>
                </span>
                <span v-for="p in m.billing_plans || []" :key="p.id" class="mt-1 block text-xs text-slate-600">
                  <span class="rounded-full bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700">{{ p.formula_label }}</span> facture du mois
                </span>
                <span v-if="m.activities?.includes('ecommerce')" class="mt-1 block text-xs text-slate-600">Courses une à une · frais payés par {{ m.default_fee_payer === 'recipient' ? 'le destinataire' : 'le client' }}</span>
                <span v-else-if="!m.billing_plans?.length" class="mt-1 inline-block rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800">Sans formule en cours</span>
              </td>
              <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ m.orders_count ?? 0 }}</td>
              <td class="px-3 py-2.5">
                <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', m.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800']">{{ m.status === 'active' ? 'Actif' : 'Suspendu' }}</span>
              </td>
              <td class="px-3 py-2.5 text-right whitespace-nowrap">
                <button type="button" class="font-semibold text-blue-600 hover:underline" :aria-label="`Ouvrir la fiche de ${m.business_name}`" @click.stop="openMerchant(m)">Ouvrir ›</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Téléphone : lignes -->
      <ul class="divide-y divide-slate-100 md:hidden">
        <li v-for="m in rows" :key="m.id">
          <button type="button" :class="['flex w-full items-center gap-3 px-4 py-3 text-left', m.status === 'active' ? '' : 'opacity-70']" @click="openMerchant(m)">
            <span class="min-w-0 flex-1">
              <span class="block truncate font-semibold">{{ m.business_name }}</span>
              <span class="block truncate text-xs text-slate-600">{{ m.phone }}<template v-if="m.pickup_zone"> · {{ m.pickup_zone.name }}</template> · {{ m.orders_count ?? 0 }} course{{ m.orders_count > 1 ? 's' : '' }}</span>
              <span v-if="m.status !== 'active' || m.pickup_lat == null || m.source === 'signup'" class="block text-xs font-semibold">
                <span v-if="m.status !== 'active'" class="text-red-700">Suspendu </span>
                <span v-if="m.pickup_lat == null" class="text-amber-800"><Icone nom="alerte" taille="petite" class="inline" /> sans position </span>
                <span v-if="m.source === 'signup'" class="text-emerald-800">{{ isNew(m) ? 'Nouveau · ' : '' }}inscrit en ligne</span>
              </span>
            </span>
            <span class="text-slate-600" aria-hidden="true">›</span>
          </button>
        </li>
      </ul>

      <p v-if="loaded && !rows.length" class="p-6 text-center text-slate-600">Aucun client ne correspond.</p>
      <p v-if="!loaded" class="p-6 text-center text-slate-600">Chargement…</p>
      <div v-if="meta && meta.total" class="flex flex-wrap items-center justify-between gap-2 border-t px-3">
        <label class="flex items-center gap-2 py-3 text-sm text-slate-600">
          {{ meta.from }} à {{ meta.to }} sur {{ meta.total }} client{{ meta.total > 1 ? 's' : '' }} ·
          <select v-model="f.per_page" class="input w-20 py-1" aria-label="Clients par page" @change="reload"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
          par page
        </label>
        <div v-if="meta.last_page > 1" class="flex items-center gap-2 py-3 text-sm">
          <button type="button" class="btn-secondary" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">‹ Précédent</button>
          <span class="text-slate-600">Page {{ meta.current_page }} sur {{ meta.last_page }}</span>
          <button type="button" class="btn-secondary" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Suivant ›</button>
        </div>
      </div>
    </div>

    <!-- Fiche du client : informations, contrat, comptes, départements, paiement -->
    <Modal :open="form.open" wide :title="form.id ? form.name : 'Nouveau client'" @close="close">
      <div class="space-y-4">
        <template v-if="form.id">
          <p class="text-sm text-slate-600">
            <span :class="['mr-2 rounded-full px-2 py-0.5 text-xs font-medium', form.data.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800']">{{ form.data.status === 'active' ? 'Actif' : 'Suspendu' }}</span>
            Client depuis le {{ day(form.createdAt) }} · {{ form.ordersCount }} course{{ form.ordersCount > 1 ? 's' : '' }}<template v-if="form.signup"> · inscrit en ligne</template>
          </p>
          <div class="flex gap-1 overflow-x-auto border-b" role="tablist" aria-label="Fiche du client">
            <button
              v-for="t in tabs" :key="t.value" type="button" role="tab" :aria-selected="tab === t.value"
              :class="['-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', tab === t.value ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-600 hover:text-slate-800']"
              @click="setTab(t.value)"
            >
              {{ t.label }}
            </button>
            <RouterLink :to="{ path: '/admin/courses', query: { merchant_id: form.id } }" class="-mb-px whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Ses courses ↗</RouterLink>
          </div>
        </template>

        <form v-if="tab === 'fiche' && auth.can('merchants.manage')" class="space-y-3" @submit.prevent="save">
            <div v-if="form.error" class="rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">{{ form.error }}</div>
            <div><label class="label">Nom commercial *</label><input v-model="form.data.business_name" class="input" required></div>
            <fieldset class="border rounded p-3">
              <legend class="text-sm font-medium px-1">Activités du client *</legend>
              <div class="flex flex-wrap gap-x-4 gap-y-1">
                <label v-for="a in activities" :key="a.value" class="flex items-center gap-2 text-sm">
                  <input v-model="form.activities" type="checkbox" :value="a.value"> {{ a.label }}
                </label>
              </div>
              <p class="text-xs text-slate-600 mt-1">
                Distribution et MAD : facture du mois selon la formule (onglet « Contrat »). E-commerce : frais retenus sur les reversements.
              </p>
            </fieldset>
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label">Contact</label><input v-model="form.data.contact_name" class="input"></div>
              <div><label class="label">Téléphone *</label><input v-model="form.data.phone" class="input" required></div>
              <div><label class="label">WhatsApp</label><input v-model="form.data.whatsapp_phone" class="input"></div>
              <div><label class="label">E-mail</label><input v-model="form.data.email" type="email" class="input"></div>
            </div>
            <div>
              <label class="label">Zone de ramassage</label>
              <select v-model="form.data.pickup_zone_id" class="input">
                <option :value="null">—</option>
                <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.full_name }}</option>
              </select>
            </div>
            <div><label class="label">Adresse de {{ collectsOnly(form.activities) ? 'collecte' : 'ramassage' }}</label><input v-model="form.data.pickup_address" class="input"></div>
            <div><label class="label">Repère</label><input v-model="form.data.pickup_landmark" class="input"></div>
            <fieldset class="border rounded p-3 space-y-2">
              <legend class="text-sm font-medium px-1">Position de {{ collectsOnly(form.activities) ? 'collecte' : 'ramassage' }} (carte des livreurs)</legend>
              <p v-if="form.source && !locationChanged" class="text-xs text-slate-600">
                {{ form.source === 'courier' ? 'Estimée d\'après les ramassages des livreurs' : form.source === 'merchant' ? 'Indiquée par le client depuis sa boutique' : 'Saisie par l\'agence' }}
              </p>
              <p class="text-xs text-slate-600">Collez le lien Google Maps envoyé par le client (« Partager » → « Copier le lien »), ou placez le repère sur la carte.</p>
              <LocationPicker v-model="form.location" allow-link height="h-56" gps-label="Ma position (sur place)" />
              <button v-if="form.location" type="button" class="text-xs text-red-600" @click="form.location = null">Retirer la position</button>
            </fieldset>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="label">Grille tarifaire</label>
                <select v-model="form.data.pricing_grid_id" class="input">
                  <option :value="null">Grille par défaut</option>
                  <option v-for="g in grids" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
              </div>
              <div>
                <label class="label">Frais payés par</label>
                <select v-model="form.data.default_fee_payer" class="input">
                  <option value="merchant">Client</option>
                  <option value="recipient">Destinataire</option>
                </select>
              </div>
            </div>
            <fieldset v-if="!form.id" class="border rounded p-3 space-y-2">
              <legend class="text-sm font-medium px-1">Compte de connexion (facultatif)</legend>
              <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Nom</label><input v-model="form.owner.name" class="input"></div>
                <div><label class="label">Téléphone</label><input v-model="form.owner.phone" class="input"></div>
                <div class="col-span-2"><label class="label">Mot de passe (8 caractères min.)</label><input v-model="form.owner.password" type="text" class="input" autocomplete="new-password"></div>
              </div>
            </fieldset>
            <!-- Étiquettes : réglage propre au client (vide : celui de l'entreprise) -->
            <details class="rounded border border-slate-200 p-3" :open="!!form.labelPrint">
              <summary class="font-medium text-sm cursor-pointer">Étiquettes</summary>
              <div class="grid grid-cols-2 gap-3 mt-3">
                <div>
                  <label class="label" for="m-label-print">Imprime ses étiquettes</label>
                  <select id="m-label-print" v-model="form.labelPrint" class="input">
                    <option value="">Comme l'entreprise</option>
                    <option value="yes">Oui</option>
                    <option value="required">Obligatoire avant le ramassage</option>
                    <option value="no">Non, l'agence imprime</option>
                  </select>
                </div>
                <div>
                  <label class="label" for="m-label-format">Format proposé</label>
                  <select id="m-label-format" v-model="form.labelFormat" class="input">
                    <option value="">Comme l'entreprise</option>
                    <option value="thermal">Rouleau 10 × 15</option>
                    <option value="a6">A6</option>
                    <option value="a4">A4 · 4 par page</option>
                  </select>
                </div>
              </div>
            </details>
            <!-- Imprimé sur les factures du mois et les avoirs -->
            <details class="rounded border border-slate-200 p-3" :open="!!(form.data.ncc || form.data.vat_exempt)">
              <summary class="font-medium text-sm cursor-pointer">Facturation : raison sociale, NCC, TVA</summary>
              <div class="grid grid-cols-2 gap-3 mt-3">
                <div class="col-span-2"><label class="label" for="m-legal">Raison sociale</label><input id="m-legal" v-model="form.data.legal_name" class="input" maxlength="255" :placeholder="form.data.business_name"></div>
                <div><label class="label" for="m-ncc">NCC</label><input id="m-ncc" v-model="form.data.ncc" class="input" maxlength="30"></div>
                <div><label class="label" for="m-rccm">RCCM</label><input id="m-rccm" v-model="form.data.rccm" class="input" maxlength="60"></div>
                <div class="col-span-2"><label class="label" for="m-billing">Adresse de facturation</label><input id="m-billing" v-model="form.data.billing_address" class="input" maxlength="500" placeholder="Sinon : l'adresse de ramassage"></div>
                <div class="col-span-2">
                  <label class="label" for="m-fne">Facture normalisée (FNE)</label>
                  <select id="m-fne" v-model="form.data.fne_template" class="input">
                    <option :value="null">Automatique : B2B avec un NCC, sinon B2C</option>
                    <option value="B2B">B2B : entreprise ou professionnel avec NCC</option>
                    <option value="B2G">B2G : institution publique</option>
                    <option value="B2C">B2C : particulier</option>
                  </select>
                </div>
                <label class="col-span-2 flex items-center gap-2 text-sm"><input v-model="form.data.vat_exempt" type="checkbox"> Exonéré de TVA</label>
                <template v-if="form.data.vat_exempt">
                  <div><label class="label" for="m-exo">N° de l'attestation</label><input id="m-exo" v-model="form.data.vat_exemption_ref" class="input" maxlength="120"></div>
                  <div><label class="label" for="m-exo-until">Valable jusqu'au</label><input id="m-exo-until" v-model="form.data.vat_exemption_until" type="date" class="input"></div>
                  <div class="col-span-2">
                    <label class="label" for="m-exo-type">Type d'exonération</label>
                    <select id="m-exo-type" v-model="form.data.vat_exemption_type" class="input">
                      <option value="conventional">Conventionnelle (TVAC)</option>
                      <option value="legal">Légale (TVAD)</option>
                    </select>
                  </div>
                </template>
              </div>
            </details>
            <div><label class="label">Notes internes</label><textarea v-model="form.data.notes" rows="2" class="input" /></div>
          <div class="flex flex-wrap items-center justify-between gap-2 border-t pt-3">
            <button v-if="form.id" type="button" :class="['btn-secondary', form.data.status === 'active' ? '!border-red-200 !text-red-700' : '']" :disabled="form.saving" @click="toggleStatus">
              {{ form.data.status === 'active' ? 'Suspendre le client' : 'Réactiver le client' }}
            </button>
            <span v-else />
            <button class="btn-primary" :disabled="form.saving">Enregistrer</button>
          </div>
        </form>
        <!-- Sans droit de modifier : lecture seule -->
        <dl v-if="tab === 'fiche' && form.id && !auth.can('merchants.manage')" class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
          <div><dt class="text-slate-600">Contact</dt><dd>{{ form.data.contact_name || '—' }} · {{ form.data.phone }}</dd></div>
          <div><dt class="text-slate-600">Adresse de ramassage</dt><dd>{{ form.data.pickup_address || '—' }}</dd></div>
          <div><dt class="text-slate-600">Frais payés par</dt><dd>{{ form.data.default_fee_payer === 'recipient' ? 'Destinataire' : 'Client' }}</dd></div>
          <div><dt class="text-slate-600">Statut</dt><dd>{{ form.data.status === 'active' ? 'Actif' : 'Suspendu' }}</dd></div>
        </dl>
        <BillingPlanModal v-if="tab === 'contrat' && form.id" inline open :merchant="{ id: form.id, business_name: form.name }" @saved="load(meta?.current_page)" />
        <MerchantAccounts v-if="tab === 'comptes' && form.id" :merchant-id="form.id" />
        <template v-if="tab === 'departements' && form.id">
          <p class="text-sm text-slate-600">Qui envoie, qui reçoit, qui gère quoi : chaque département est connu sur les courses, les bordereaux et la facture.</p>
          <DepartmentsManager :merchant-id="form.id" staff />
        </template>
        <PaymentMethodsManager v-if="tab === 'paiement' && form.id" :merchant-id="form.id" :can-edit="auth.can('finance.manage')" />
      </div>
    </Modal>
  </div>
</template>

<script setup>
import Icone from '../../components/ui/Icone.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import BillingPlanModal from '../../components/BillingPlanModal.vue'
import ConnectRequests from '../../components/ConnectRequests.vue'
import LocationPicker from '../../components/LocationPicker.vue'
import Modal from '../../components/Modal.vue'
import DepartmentsManager from '../../components/departments/DepartmentsManager.vue'
import MerchantAccounts from '../../components/merchant/MerchantAccounts.vue'
import PaymentMethodsManager from '../../components/payment/PaymentMethodsManager.vue'
import { confirmDialog } from '../../composables/useDialog'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { activityBadge, activityShort, myActivities } from '../../utils/activities'
import { collectsOnly } from '../../utils/format'

/**
 * Clients : liste par pages (recherche, filtres, tri), résumé cliquable, et une fiche unique à onglets
 * (informations, contrat, comptes de connexion, départements, paiement) à la place des liens de chaque ligne.
 */
const DEFAULTS = { search: '', activity: '', status: '', source: '', zone_id: '', located: '', sort: 'name', dir: 'asc', per_page: '25' }
const FILTERS = ['activity', 'status', 'source', 'zone_id', 'located']
const FIELDS = ['business_name', 'contact_name', 'phone', 'whatsapp_phone', 'email', 'pickup_zone_id', 'pickup_address',
  'pickup_landmark', 'pricing_grid_id', 'default_fee_payer', 'status', 'notes',
  'legal_name', 'ncc', 'rccm', 'billing_address', 'vat_exempt', 'vat_exemption_ref', 'vat_exemption_until', 'vat_exemption_type', 'fne_template']
const SOURCES = { merchant: 'par le client', staff: 'par l\'agence', courier: 'estimée (livreurs)' }

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const f = reactive({ ...DEFAULTS, ...Object.fromEntries(Object.entries(route.query).filter(([k]) => k in DEFAULTS).map(([k, v]) => [k, String(v)])) })
// Lien de la notification « Nouveau client » : ?nouveaux=1
if (route.query.nouveaux) Object.assign(f, { source: 'signup', sort: 'created_at', dir: 'desc' })

const rows = ref([])
const meta = ref(null)
const summary = ref({})
const zones = ref([])
const grids = ref([])
const loaded = ref(false)
const error = ref('')
const moreFilters = ref(false)
const tab = ref('fiche')
// Activités du périmètre de ce compte (cases du formulaire, filtre)
const activities = computed(() => myActivities(auth.user))
// Liste « Trier par » : champ et sens réunis
const order = computed({
  get: () => `${f.sort}:${f.dir}`,
  set: (v) => { [f.sort, f.dir] = v.split(':') },
})

const filtered = computed(() => ['search', ...FILTERS].some((k) => f[k] !== ''))
const activeFilters = computed(() => FILTERS.filter((k) => f[k] !== '').length)
const kpis = computed(() => {
  const s = summary.value
  return [
    { key: 'all', label: 'Clients', value: s.merchants ?? '—', active: !filtered.value, apply: reset },
    { key: 'active', label: 'Actifs', value: s.active ?? '—', active: f.status === 'active' && !f.located, apply: () => toggle({ status: 'active' }) },
    { key: 'suspended', label: 'Suspendus', value: s.suspended ?? 0, active: f.status === 'suspended', apply: () => toggle({ status: 'suspended' }) },
    { key: 'new', label: 'Inscrits cette semaine', value: s.signups_new ?? 0, active: f.source === 'signup' && f.sort === 'created_at', apply: () => toggle({ source: 'signup' }, { sort: 'created_at', dir: 'desc' }) },
    { key: 'located', label: 'Sans position de ramassage', value: s.without_location ?? 0, active: f.located === '0', alert: s.without_location > 0, apply: () => toggle({ located: '0', status: 'active' }) },
  ]
})

const form = reactive({ open: false, id: null, name: '', createdAt: null, ordersCount: 0, signup: false, merchantActivities: [], data: {}, activities: [], owner: {}, error: '', saving: false, location: null, initialLocation: null, source: null, labelPrint: '', labelFormat: '' })
const tabs = computed(() => [
  { value: 'fiche', label: 'Informations' },
  { value: 'contrat', label: 'Contrat' },
  { value: 'comptes', label: 'Comptes de connexion' },
  ...(form.merchantActivities.some((a) => a !== 'ecommerce') ? [{ value: 'departements', label: 'Départements' }] : []),
  ...(auth.can('finance.view') ? [{ value: 'paiement', label: 'Paiement' }] : []),
])
const locationChanged = computed(() => form.location?.lat !== form.initialLocation?.lat || form.location?.lng !== form.initialLocation?.lng)

const day = (iso) => (iso ? new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : '—')
// Inscrit depuis moins de 7 jours
const isNew = (m) => Date.now() - new Date(m.created_at).getTime() < 7 * 86400000

// Adresse de la page : garde les filtres et la fiche ouverte (lien à partager, retour arrière)
function syncUrl() {
  router.replace({ query: {
    ...Object.fromEntries(Object.entries(f).filter(([k, v]) => v !== DEFAULTS[k])),
    ...(form.open && form.id ? { id: form.id, ...(tab.value === 'fiche' ? {} : { onglet: tab.value }) } : {}),
  } })
}

async function load(page = 1) {
  error.value = ''
  syncUrl()
  try {
    const { data } = await http.get('/merchants', { params: { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')), page, summary: 1 } })
    rows.value = data.data
    meta.value = data.meta
    summary.value = data.summary || {}
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loaded.value = true
  }
}

const reload = () => load(1)
let timer = null
function debounced() {
  clearTimeout(timer)
  timer = setTimeout(reload, 300)
}
// Case du résumé : la recliquer enlève son filtre
function toggle(filters, extra = {}) {
  const on = Object.entries(filters).every(([k, v]) => f[k] === v)
  Object.assign(f, { ...DEFAULTS, search: f.search, per_page: f.per_page }, on ? {} : { ...filters, ...extra })
  reload()
}
function reset() {
  Object.assign(f, { ...DEFAULTS, per_page: f.per_page })
  reload()
}

function fill(merchant) {
  form.id = merchant?.id ?? null
  form.name = merchant?.business_name ?? ''
  form.createdAt = merchant?.created_at ?? null
  form.ordersCount = merchant?.orders_count ?? 0
  form.signup = merchant?.source === 'signup'
  form.merchantActivities = merchant?.activities || []
  form.data = Object.fromEntries(FIELDS.map((k) => [k, merchant?.[k] ?? null]))
  form.data.default_fee_payer ??= 'merchant'
  form.data.status ??= 'active'
  form.data.vat_exempt ??= false
  form.data.vat_exemption_type ??= 'conventional'
  form.labelPrint = merchant?.label_settings?.client_print || ''
  form.labelFormat = merchant?.label_settings?.default_format || ''
  // Seules les activités de son périmètre se cochent ; les autres restent telles quelles (serveur)
  const mine = activities.value.map((a) => a.value)
  form.activities = merchant ? (merchant.activities || []).filter((a) => mine.includes(a)) : [mine[0]]
  form.owner = { name: '', phone: '', password: '' }
  form.location = merchant?.pickup_lat != null ? { lat: merchant.pickup_lat, lng: merchant.pickup_lng, accuracy: null } : null
  form.initialLocation = form.location ? { ...form.location } : null
  form.source = merchant?.pickup_location_source ?? null
  form.error = ''
}

function openNew() {
  fill(null)
  tab.value = 'fiche'
  form.open = true
}

// Fiche : rechargée (à jour, et trouvée même hors de la page de la liste)
async function openMerchant(m, onglet = 'fiche') {
  try {
    fill((await http.get(`/merchants/${m.id}`)).data.data)
    tab.value = tabs.value.some((t) => t.value === onglet) ? onglet : 'fiche'
    form.open = true
    syncUrl()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function setTab(value) {
  tab.value = value
  syncUrl()
}

function close() {
  form.open = false
  syncUrl()
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    const payload = Object.fromEntries(Object.entries(form.data).filter(([k, v]) => v !== '' && (v !== null || form.id) && k !== 'status'))
    if (!form.activities.length) {
      form.error = 'Cochez au moins une activité.'
      return
    }
    payload.activities = [...form.activities]
    payload.label_settings = { ...(form.labelPrint ? { client_print: form.labelPrint } : {}), ...(form.labelFormat ? { default_format: form.labelFormat } : {}) }
    if (!form.id && form.owner.phone) payload.owner = { ...form.owner, name: form.owner.name || form.data.contact_name || form.data.business_name }
    const { data } = form.id ? await http.patch(`/merchants/${form.id}`, payload) : await http.post('/merchants', payload)
    // Position : seulement si elle a changé (garde la source « client » ou « livreurs » sinon)
    if (locationChanged.value) {
      await http.put(`/merchants/${data.data.id}/pickup-location`, { lat: form.location?.lat ?? null, lng: form.location?.lng ?? null })
    }
    toasts.success('Client enregistré.')
    close()
    load(meta.value?.current_page)
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

// Suspendre : le client et ses comptes ne peuvent plus se connecter ; réactiver : tout revient
async function toggleStatus() {
  const suspend = form.data.status === 'active'
  if (suspend && !(await confirmDialog(`Suspendre ${form.name} ? Ses comptes ne pourront plus se connecter ni créer de courses.`))) return
  form.saving = true
  form.error = ''
  try {
    await http.patch(`/merchants/${form.id}`, { status: suspend ? 'suspended' : 'active' })
    form.data.status = suspend ? 'suspended' : 'active'
    toasts.success(suspend ? 'Client suspendu.' : 'Client réactivé.')
    load(meta.value?.current_page)
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

onMounted(async () => {
  load()
  // Liens de la caisse, des alertes, de la messagerie ou de la page Utilisateurs : ?id=…&onglet=paiement|comptes
  if (route.query.id) openMerchant({ id: Number(route.query.id) }, route.query.onglet || 'fiche')
  zones.value = (await http.get('/zones')).data.data
  if (auth.can(['pricing.manage', 'merchants.manage'])) grids.value = (await http.get('/pricing-grids')).data.data
})
</script>
