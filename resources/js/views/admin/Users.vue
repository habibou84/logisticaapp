<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-bold">Utilisateurs</h1>
        <p class="text-sm text-gray-500">Les comptes de l'équipe, des livreurs et des clients : qui accède à quoi, et qui n'utilise plus son accès.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <RouterLink to="/admin/roles" class="btn-secondary">🔐 Rôles et droits</RouterLink>
        <button v-if="auth.can('users.manage')" class="btn-primary" @click="openForm()">+ Nouveau compte</button>
      </div>
    </div>

    <!-- Compteurs : un clic ouvre l'onglet -->
    <div v-if="summary" class="grid grid-cols-2 gap-3 lg:grid-cols-5">
      <button v-for="c in counters" :key="c.key" type="button" :class="['card p-3 text-left hover:shadow', c.warn ? 'border-amber-300 bg-amber-50' : '']" @click="setTab(c.key)">
        <p :class="['text-xs', c.warn ? 'text-amber-800' : 'text-gray-500']">{{ c.label }}</p>
        <p :class="['text-2xl font-bold', c.warn ? 'text-amber-800' : '']">{{ c.value }}</p>
        <p class="text-xs text-gray-500">{{ c.hint }}</p>
      </button>
    </div>

    <!-- Onglets, recherche, filtres -->
    <div class="card space-y-3 p-3">
      <div class="flex gap-2 overflow-x-auto no-scrollbar text-sm" role="tablist">
        <button
          v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
          :class="['shrink-0 rounded-full px-3 py-1', tab === t.key ? 'bg-slate-900 text-white' : t.key === 'inactive' && summary?.inactive ? 'border border-amber-300 bg-amber-50 text-amber-800' : 'border border-slate-300 bg-white']"
          @click="setTab(t.key)"
        >
          {{ t.label }}<span v-if="summary" class="ml-1 opacity-75">{{ summary[t.key] }}</span>
        </button>
      </div>
      <div class="flex flex-wrap gap-2">
        <input v-model="search" type="search" class="input min-w-48 flex-1" placeholder="🔍 Nom, téléphone ou e-mail…" aria-label="Rechercher un compte" @input="debouncedLoad">
        <select v-if="tab === 'team'" v-model="role" class="input !w-auto" aria-label="Rôle" @change="reload">
          <option value="">Tous les rôles</option>
          <option v-for="r in teamRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
        <select v-if="tab === 'team' && catalog.activities.length > 1" v-model="activity" class="input !w-auto" aria-label="Activité" @change="reload">
          <option value="">Toutes les activités</option>
          <option v-for="a in catalog.activities" :key="a.value" :value="a.value">{{ a.label }}</option>
        </select>
      </div>
      <p v-if="tab === 'inactive'" class="text-xs text-amber-800">Comptes actifs jamais connectés, ou sans connexion depuis {{ summary?.inactive_days || 30 }} jours : à relancer (lien de connexion) ou à suspendre.</p>
    </div>

    <div v-if="loadError" class="card flex flex-wrap items-center gap-3 border-red-200 bg-red-50 p-4 text-sm text-red-800">
      <span class="flex-1">La liste n'a pas pu être chargée : {{ loadError }}</span>
      <button type="button" class="btn-secondary" @click="load">Réessayer</button>
    </div>

    <div v-else class="card">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs text-gray-500">
          <tr>
            <th class="p-3">Nom</th>
            <th class="hidden p-3 md:table-cell">Téléphone</th>
            <th class="hidden p-3 sm:table-cell">{{ tab === 'merchants' ? 'Client et rôle' : 'Rôle' }}</th>
            <th class="hidden p-3 lg:table-cell">{{ tab === 'merchants' ? 'Départements' : 'Périmètre' }}</th>
            <th class="hidden p-3 md:table-cell">État</th>
            <th class="hidden p-3 lg:table-cell">Dernière connexion</th>
            <th class="p-3" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="u in users" :key="u.id" :class="u.status === 'suspended' ? 'bg-slate-50 text-gray-500' : ''">
            <td class="p-3">
              <p class="font-medium">{{ u.name }}<span v-if="u.id === auth.user.id" class="text-xs text-gray-400"> (vous)</span></p>
              <p class="text-xs text-gray-500 sm:hidden">{{ u.merchant_name ? `${u.merchant_name} · ` : '' }}{{ u.role_label }}</p>
              <p class="text-xs text-gray-500 md:hidden">{{ u.phone }}</p>
              <p class="flex flex-wrap gap-1 md:hidden"><span v-for="b in badges(u)" :key="b.text" :class="['rounded px-1.5 py-0.5 text-xs', b.cls]">{{ b.text }}</span></p>
            </td>
            <td class="hidden p-3 md:table-cell">{{ u.phone }}<p v-if="u.email" class="text-xs text-gray-500">{{ u.email }}</p></td>
            <td class="hidden p-3 sm:table-cell">
              <p v-if="u.merchant_name" class="font-medium">{{ u.merchant_name }}</p>
              {{ u.role_label }}
              <span v-if="u.role_custom" class="ml-1 rounded-full bg-indigo-50 px-1.5 text-[10px] text-indigo-700">sur mesure</span>
              <p v-if="u.extra_permissions?.length || u.denied_permissions?.length" class="text-xs text-gray-500">
                Droits ajustés<template v-if="u.extra_permissions?.length"> · +{{ u.extra_permissions.length }}</template><template v-if="u.denied_permissions?.length"> · −{{ u.denied_permissions.length }}</template>
              </p>
            </td>
            <td class="hidden p-3 lg:table-cell">
              <template v-if="u.merchant_id">
                <span v-if="!u.departments?.length" class="text-gray-400">—</span>
                <span v-for="d in u.departments" :key="d.id" class="mr-1 inline-block rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700">{{ d.name }}<template v-if="d.is_head"> · chef de service</template></span>
              </template>
              <template v-else-if="isStaffRole(u.role)">
                <span v-if="u.role === 'admin' || !scopeParts(u).length" class="text-gray-500">Tout</span>
                <span v-for="p in scopeParts(u)" v-else :key="p" class="mr-1 inline-block rounded-full bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700">{{ p }}</span>
              </template>
              <span v-else class="text-gray-400">—</span>
            </td>
            <td class="hidden p-3 md:table-cell">
              <span class="flex flex-wrap gap-1"><span v-for="b in badges(u)" :key="b.text" :class="['rounded px-1.5 py-0.5 text-xs', b.cls]">{{ b.text }}</span></span>
            </td>
            <td class="hidden p-3 text-xs text-gray-500 lg:table-cell">{{ u.last_login_at ? ago(u.last_login_at) : `créé le ${date(u.created_at)}` }}</td>
            <td class="whitespace-nowrap p-3 text-right">
              <button v-if="u.status === 'suspended' && canEdit(u)" type="button" class="mr-2 rounded-lg border border-emerald-500 px-2 py-1 text-emerald-700" @click="ask('reactivate', u)">Réactiver</button>
              <span v-if="actions(u).length" class="relative inline-block">
                <button type="button" :class="['rounded-lg border px-2 py-1', menu === u.id ? 'border-red-500 bg-red-50' : '']" :aria-label="`Actions pour ${u.name}`" :aria-expanded="menu === u.id" @click.stop="menu = menu === u.id ? null : u.id">⋯</button>
                <div v-if="menu === u.id" class="absolute right-0 top-9 z-20 w-72 rounded-xl border bg-white p-1 text-left shadow-lg" role="menu" @click.stop>
                  <template v-for="a in actions(u)" :key="a.key">
                    <hr v-if="a.danger" class="my-1">
                    <button type="button" role="menuitem" :class="['block w-full rounded-lg px-3 py-2 text-left hover:bg-slate-50', a.danger ? 'text-red-600' : '']" @click="run(a.key, u)">{{ a.label }}</button>
                  </template>
                </div>
              </span>
            </td>
          </tr>
          <tr v-if="!users.length && !loading"><td colspan="7" class="p-6 text-center text-gray-500">{{ search ? 'Personne ne correspond à cette recherche.' : 'Personne dans cet onglet.' }}</td></tr>
          <tr v-if="loading && !users.length"><td colspan="7" class="p-6 text-center text-gray-500">Chargement…</td></tr>
        </tbody>
      </table>
      <div v-if="meta && meta.total > meta.per_page" class="flex items-center justify-between border-t p-3 text-sm text-gray-500">
        <span>{{ meta.from }} à {{ meta.to }} sur {{ meta.total }}</span>
        <span class="flex gap-1">
          <button type="button" class="rounded border px-2 py-0.5 disabled:opacity-40" :disabled="meta.current_page <= 1" aria-label="Page précédente" @click="go(-1)">‹</button>
          <button type="button" class="rounded border px-2 py-0.5 disabled:opacity-40" :disabled="meta.current_page >= meta.last_page" aria-label="Page suivante" @click="go(1)">›</button>
        </span>
      </div>
      <p v-else-if="meta && meta.total" class="border-t p-3 text-sm text-gray-500">{{ meta.total }} compte{{ meta.total > 1 ? 's' : '' }}</p>
      <p v-if="tab === 'merchants'" class="border-t p-3 text-xs text-gray-500">Les rôles chez le client (propriétaire, gestionnaire, agent, lecteur) et les chefs de service se règlent dans la fiche du client, rubrique Comptes.</p>
    </div>

    <!-- Créer / modifier -->
    <Modal :open="form.open" wide :title="form.id ? `Modifier : ${form.original?.name}` : 'Nouveau compte'" @close="form.open = false">
      <form class="space-y-3" @submit.prevent="save">
        <div v-if="form.error" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ form.error }}</div>
        <div><label class="label" for="u-name">Nom *</label><input id="u-name" v-model="form.data.name" class="input" required></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label" for="u-phone">Téléphone *</label><input id="u-phone" v-model="form.data.phone" class="input" required inputmode="tel"></div>
          <div><label class="label" for="u-email">E-mail</label><input id="u-email" v-model="form.data.email" type="email" class="input" placeholder="facultatif"></div>
        </div>
        <div>
          <p class="label">Rôle *</p>
          <div class="grid gap-1.5 sm:grid-cols-2" role="radiogroup" aria-label="Rôle">
            <label
              v-for="r in assignable" :key="r.value"
              :class="['flex cursor-pointer gap-2 rounded-lg border px-3 py-2', form.data.role === r.value ? 'border-2 border-red-500 bg-red-50' : 'border-slate-200']"
            >
              <input v-model="form.data.role" type="radio" name="role" :value="r.value" class="mt-1" @change="resetTuning">
              <span>
                <b>{{ r.label }}</b><span v-if="r.custom" class="ml-1 rounded-full bg-indigo-50 px-1.5 text-[10px] text-indigo-700">sur mesure</span>
                <span class="block text-xs text-gray-500">{{ roleHelp(r) }}</span>
              </span>
            </label>
          </div>
        </div>
        <!-- Équipe : périmètre propre au compte et réglage fin des droits -->
        <template v-if="isStaffRole(form.data.role) && form.data.role !== 'admin'">
          <details class="rounded border p-3" :open="hasOwnScope">
            <summary class="cursor-pointer text-sm font-medium">
              Périmètre <span class="font-normal text-gray-500">({{ hasOwnScope ? 'propre à ce compte' : roleScopeText || 'tout' }})</span>
            </summary>
            <ScopeFields v-model="form.data.scope" class="mt-2" hint="Rien de coché : le périmètre du rôle s'applique." />
          </details>
          <details class="rounded border p-3" :open="form.tuned">
            <summary class="cursor-pointer text-sm font-medium">Droits <span class="font-normal text-gray-500">(ceux du rôle, ajustables un à un)</span></summary>
            <PermissionPicker v-model="form.data.permissions" :base="rolePermissions" class="mt-2" />
          </details>
        </template>
        <div>
          <label class="label" for="u-password">{{ form.id ? 'Nouveau mot de passe (vide : ne change pas)' : 'Mot de passe (facultatif, 8 caractères min.)' }}</label>
          <div class="flex">
            <input id="u-password" v-model="form.data.password" :type="showPassword ? 'text' : 'password'" class="input rounded-r-none" autocomplete="new-password" :placeholder="form.id ? '' : 'Vide : un lien de connexion à envoyer par WhatsApp'">
            <button type="button" class="rounded-r-lg border border-l-0 border-slate-300 px-3" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword">{{ showPassword ? '🙈' : '👁️' }}</button>
          </div>
          <p class="mt-1 text-xs text-gray-500">
            {{ form.id ? 'Changer le mot de passe déconnecte le compte de tous ses appareils.' : 'Sans mot de passe, vous obtenez un lien personnel à envoyer par WhatsApp : la personne arrive connectée et choisit son mot de passe si elle veut.' }}
          </p>
        </div>
        <button class="btn-primary w-full" :disabled="form.saving">{{ form.id ? 'Enregistrer' : (form.data.password ? 'Créer le compte' : 'Créer et préparer le lien WhatsApp') }}</button>
        <p v-if="form.id" class="text-center text-xs text-gray-500">Suspendre ou réactiver : menu ⋯ de la liste.</p>
      </form>
    </Modal>

    <!-- Confirmation : suspendre, réactiver, déconnecter -->
    <Modal :open="!!confirm" :title="confirm?.title || ''" @close="confirm = null">
      <div v-if="confirm" class="space-y-3 text-sm">
        <p class="text-gray-600">{{ confirm.text }}</p>
        <div class="flex gap-2">
          <button type="button" :class="['flex-1', confirm.danger ? 'btn-danger' : 'btn-primary']" :disabled="busy" @click="confirm.run()">{{ confirm.button }}</button>
          <button type="button" class="btn-secondary flex-1" @click="confirm = null">Annuler</button>
        </div>
      </div>
    </Modal>

    <!-- Historique du compte -->
    <Modal :open="!!history" :title="history ? `Historique : ${history.user.name}` : ''" @close="history = null">
      <div v-if="history" class="text-sm">
        <p v-if="history.loading" class="text-gray-500">Chargement…</p>
        <ul v-else-if="history.events.length" class="divide-y">
          <li v-for="e in history.events" :key="e.id" class="flex gap-3 py-2">
            <span class="w-28 shrink-0 text-xs text-gray-400">{{ dateTime(e.at) }}</span>
            <span>{{ e.text }}<template v-if="e.by">, par <b>{{ e.by }}</b></template></span>
          </li>
        </ul>
        <p v-else class="text-gray-500">Rien d'enregistré pour ce compte (l'historique commence avec cette version de la page).</p>
      </div>
    </Modal>

    <!-- Lien de connexion : l'administrateur l'envoie depuis son propre WhatsApp -->
    <Modal :open="!!invitation" title="Lien de connexion à envoyer" @close="invitation = null">
      <div v-if="invitation" class="space-y-3 text-sm">
        <p>Envoyez ce lien à <strong>{{ invitation.user.name }}</strong> ({{ invitation.user.phone }}). Il est personnel, ne sert qu'une fois et expire le {{ dateTime(invitation.expires_at) }}. En l'ouvrant, la personne arrive connectée dans son espace.</p>
        <a :href="invitation.whatsapp_link" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#25D366] py-3 font-semibold text-white hover:bg-[#1ebe5b]">💬 Envoyer par WhatsApp</a>
        <div class="flex gap-2">
          <input :value="invitation.url" readonly class="input flex-1 text-xs" aria-label="Lien de connexion" @focus="$event.target.select()">
          <button type="button" class="btn-secondary" @click="copy(invitation.url)">Copier</button>
        </div>
        <p class="text-xs text-gray-500">Pas de WhatsApp ? Donnez-lui le lien autrement, ou choisissez un mot de passe dans « Modifier ».</p>
      </div>
    </Modal>

    <AccessPreview :user-id="preview" @close="preview = null" />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import http, { apiErrorMessage } from '../../bootstrap/axios'
import Modal from '../../components/Modal.vue'
import PermissionPicker from '../../components/access/PermissionPicker.vue'
import ScopeFields from '../../components/access/ScopeFields.vue'
import AccessPreview from '../../components/access/AccessPreview.vue'
import { useAccessCatalog } from '../../composables/useAccessCatalog'
import { useAuthStore } from '../../stores/auth'
import { useToastStore } from '../../stores/toasts'
import { date, dateTime } from '../../utils/format'

/**
 * Équipe › Utilisateurs (repris de Jibiat, adapté) : compteurs, onglets, recherche, actions par compte (menu ⋯),
 * historique, lien de connexion par WhatsApp ; périmètre, droits ajustés et rôles sur mesure de Logistica.
 */
const TABS = [
  { key: 'team', label: 'Équipe' },
  { key: 'couriers', label: 'Livreurs' },
  { key: 'merchants', label: 'Comptes clients' },
  { key: 'suspended', label: 'Suspendus' },
  { key: 'inactive', label: 'À vérifier' },
]
// Explication des rôles intégrés (les rôles sur mesure gardent leur description)
const ROLE_HELP = {
  admin: 'Tout : réglages, équipe, argent, toutes les activités.',
  activity_manager: 'Tout sur ses activités (e-commerce, distribution, MAD), sauf les réglages de l\'entreprise.',
  dispatcher: 'Crée et répartit les courses, suit les livreurs. Ne voit pas la caisse.',
  cashier: 'Reçoit l\'argent des livreurs, reverse aux clients, factures. Ne répartit pas les courses.',
  hub_agent: 'Reçoit et rend les colis au dépôt, gère le stock.',
  courier: 'Application livreur : ses missions, sa caisse, son véhicule.',
}

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const { catalog, load: loadCatalog, role: findRole, isStaffRole } = useAccessCatalog()

const tab = ref(TABS.some((t) => t.key === route.query.onglet) ? route.query.onglet : 'team')
const users = ref([])
const meta = ref(null)
const summary = ref(null)
const loading = ref(false)
const loadError = ref('')
const search = ref('')
const role = ref(route.query.role || '')
const activity = ref('')
const page = ref(1)
const menu = ref(null)
const preview = ref(null)
const confirm = ref(null)
const history = ref(null)
const invitation = ref(null)
const busy = ref(false)
const showPassword = ref(false)
const form = reactive({ open: false, id: null, original: null, data: {}, error: '', saving: false, tuned: false })

// Rôles que le compte connecté peut attribuer ; l'équipe sans les livreurs pour le filtre
const assignable = computed(() => catalog.roles.filter((r) => r.assignable))
const teamRoles = computed(() => catalog.roles.filter((r) => isStaffRole(r.value)))
const currentRole = computed(() => findRole(form.data.role))
const rolePermissions = computed(() => currentRole.value?.permissions || [])
const hasOwnScope = computed(() => Object.values(form.data.scope || {}).some((v) => v.length))
const roleHelp = (r) => ROLE_HELP[r.value] || r.description || (r.permissions ? `Rôle créé par l'entreprise · ${r.permissions.length} droit(s).` : '')
const activityLabel = (a) => catalog.activities.find((x) => x.value === a)?.label || a
const zoneName = (id) => catalog.zones.find((z) => z.id === id)?.name
const hubName = (id) => catalog.hubs.find((h) => h.id === id)?.name
const scopeText = (scope) => [...(scope.activities || []).map(activityLabel), ...(scope.zone_ids || []).map(zoneName), ...(scope.hub_ids || []).map(hubName)].filter(Boolean)
const roleScopeText = computed(() => {
  const parts = currentRole.value ? scopeText(currentRole.value) : []
  return parts.length ? `celui du rôle : ${parts.join(', ')}` : ''
})
const scopeParts = (u) => scopeText(u.scope || {})

// Compteurs
const roleCount = (name, key = 'total') => summary.value?.roles?.[name]?.[key] || 0
const counters = computed(() => {
  const s = summary.value
  const team = catalog.roles.filter((r) => isStaffRole(r.value)).map((r) => [roleCount(r.value), r.label]).filter(([n]) => n)
  return [
    { key: 'team', label: 'Équipe', value: s.team, hint: team.map(([n, l]) => `${n} ${l.toLowerCase()}`).join(' · ') || '—' },
    { key: 'couriers', label: 'Livreurs', value: s.couriers, hint: `${roleCount('courier', 'active')} actif(s) · ${roleCount('courier', 'suspended')} suspendu(s)${s.mad_couriers ? ` · ${s.mad_couriers} en MAD` : ''}` },
    { key: 'merchants', label: 'Comptes clients', value: s.merchants, hint: `${roleCount('merchant_owner')} propriétaire(s) · ${roleCount('merchant_manager')} gestionnaire(s) · ${roleCount('merchant_staff')} agent(s)` },
    { key: 'suspended', label: 'Suspendus', value: s.suspended, hint: 'accès coupé' },
    { key: 'inactive', label: s.inactive ? '⚠️ À vérifier' : 'À vérifier', value: s.inactive, warn: s.inactive > 0, hint: `${s.never_logged} jamais connecté(s) · ${s.inactive_old} inactif(s) +${s.inactive_days} j` },
  ]
})

// Pastilles d'état
function badges(u) {
  const out = [u.status === 'active' ? { text: 'Actif', cls: 'bg-emerald-100 text-emerald-800' } : { text: 'Suspendu', cls: 'bg-slate-200 text-slate-600' }]
  if (u.status === 'active' && !u.last_login_at) out.push({ text: 'jamais connecté', cls: 'bg-amber-100 text-amber-800' })
  else if (u.status === 'active' && u.inactive_days >= (summary.value?.inactive_days || 30)) out.push({ text: `inactif depuis ${u.inactive_days} j`, cls: 'bg-amber-100 text-amber-800' })
  return out
}
function ago(at) {
  const minutes = Math.round((Date.now() - new Date(at).getTime()) / 60000)
  if (minutes < 2) return 'à l\'instant'
  if (minutes < 60) return `il y a ${minutes} min`
  if (minutes < 1440) return `il y a ${Math.round(minutes / 60)} h`
  const days = Math.round(minutes / 1440)
  return days === 1 ? 'hier' : days < 30 ? `il y a ${days} jours` : date(at)
}

// Un compte modifiable : jamais le sien, jamais un client (géré dans sa fiche), jamais plus puissant que soi
const canEdit = (u) => auth.can('users.manage') && u.id !== auth.user.id && !u.merchant_id && (!u.role || assignable.value.some((r) => r.value === u.role))
function actions(u) {
  const manage = canEdit(u)
  const list = []
  if (manage) list.push({ key: 'edit', label: '✏️ Modifier le compte' })
  if (isStaffRole(u.role)) list.push({ key: 'preview', label: '👁 Voir comme ce compte' })
  if (u.courier_id) list.push({ key: 'courier', label: '🛵 Fiche du livreur' })
  if (u.merchant_id) {
    list.push({ key: 'merchant', label: '🏪 Fiche du client' })
    list.push({ key: 'merchant_accounts', label: '👥 Comptes et départements du client' })
  }
  if (manage && u.status === 'active') list.push({ key: 'invite', label: '💬 Lien de connexion par WhatsApp' })
  if (auth.can('users.view')) list.push({ key: 'history', label: '🕘 Historique du compte' })
  if (auth.can('users.manage') && u.id !== auth.user.id && (manage || u.merchant_id)) list.push({ key: 'logout', label: '📵 Déconnecter de tous ses appareils' })
  if (manage && u.status === 'active') list.push({ key: 'suspend', label: '⏸ Suspendre le compte', danger: true })
  return list
}

function run(key, u) {
  menu.value = null
  ;({
    edit: () => openForm(u),
    preview: () => { preview.value = u.id },
    courier: () => router.push({ path: '/admin/livreurs', query: { id: u.courier_id } }),
    merchant: () => router.push({ path: '/admin/marchands', query: { id: u.merchant_id } }),
    merchant_accounts: () => router.push(`/admin/marchands/${u.merchant_id}/comptes`),
    invite: () => invite(u),
    history: () => openHistory(u),
    logout: () => ask('logout', u),
    suspend: () => ask('suspend', u),
  })[key]?.()
}

function ask(kind, u) {
  confirm.value = {
    suspend: { title: `Suspendre ${u.name} ?`, text: 'Le compte est déconnecté de tous ses appareils et ne peut plus se connecter. Rien n\'est effacé : vous pourrez le réactiver.', button: 'Suspendre', danger: true, run: () => patchStatus(u, 'suspended') },
    reactivate: { title: `Réactiver ${u.name} ?`, text: 'La personne pourra de nouveau se connecter avec son mot de passe, ou avec un lien de connexion.', button: 'Réactiver', run: () => patchStatus(u, 'active') },
    logout: { title: `Déconnecter ${u.name} ?`, text: 'Toutes ses sessions sont fermées (téléphone, ordinateur). Le compte reste actif : il se reconnecte avec son mot de passe.', button: 'Déconnecter partout', run: () => logout(u) },
  }[kind]
}

async function act(fn, success) {
  busy.value = true
  try {
    await fn()
    toasts.success(success)
    confirm.value = null
    reload(false)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
const patchStatus = (u, status) => act(() => http.patch(`/users/${u.id}`, { status }), status === 'active' ? 'Compte réactivé.' : 'Compte suspendu.')
const logout = (u) => act(() => http.post(`/users/${u.id}/logout`), 'Déconnecté de tous ses appareils.')

async function invite(u) {
  try {
    const { data } = await http.post(`/users/${u.id}/invitation`)
    invitation.value = { ...data.data, user: u }
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function openHistory(u) {
  history.value = { user: u, loading: true, events: [] }
  try {
    history.value.events = (await http.get(`/users/${u.id}/history`)).data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    history.value.loading = false
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toasts.success('Lien copié.')
  } catch {
    window.prompt('Copiez le lien :', text)
  }
}

// Liste
async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await http.get('/users', { params: {
      scope: tab.value, search: search.value || undefined, page: page.value, per_page: 25,
      role: tab.value === 'team' ? role.value || undefined : undefined,
      activity: tab.value === 'team' ? activity.value || undefined : undefined,
    } })
    users.value = data.data
    meta.value = data.meta
  } catch (e) {
    loadError.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
async function loadSummary() {
  summary.value = (await http.get('/users/summary')).data.data
}
function reload(resetPage = true) {
  if (resetPage) page.value = 1
  load()
  loadSummary().catch(() => {})
}
function setTab(key) {
  tab.value = key
  router.replace({ query: { ...route.query, onglet: key } })
  reload()
}
function go(step) {
  page.value += step
  load()
}
let timer = null
function debouncedLoad() {
  clearTimeout(timer)
  timer = setTimeout(() => reload(), 300)
}

// Formulaire
function resetTuning() {
  form.data.permissions = [...rolePermissions.value]
}
function openForm(user = null) {
  const r = findRole(user?.role ?? 'courier')
  const base = r?.permissions || []
  const extra = user?.extra_permissions || []
  const denied = user?.denied_permissions || []
  form.id = user?.id ?? null
  form.original = user
  form.data = {
    name: user?.name ?? '',
    phone: user?.phone ?? '',
    email: user?.email ?? '',
    role: user?.role ?? (tab.value === 'couriers' ? 'courier' : 'dispatcher'),
    password: '',
    scope: { activities: [...(user?.activities || [])], zone_ids: [...(user?.zone_ids || [])], hub_ids: [...(user?.hub_ids || [])] },
    // Droits effectifs : ceux du rôle, plus les ajoutés, moins les retirés
    permissions: [...new Set([...(user ? base : findRole(tab.value === 'couriers' ? 'courier' : 'dispatcher')?.permissions || []), ...extra])].filter((p) => !denied.includes(p)),
  }
  form.tuned = !!(extra.length || denied.length)
  form.error = ''
  showPassword.value = false
  form.open = true
}

async function save() {
  form.saving = true
  form.error = ''
  try {
    const { scope, permissions, ...fields } = form.data
    const payload = Object.fromEntries(Object.entries(fields).filter(([, v]) => v !== ''))
    const staff = isStaffRole(form.data.role) && form.data.role !== 'admin'
    // Réglage fin : l'écart avec le rôle
    payload.extra_permissions = staff ? permissions.filter((p) => !rolePermissions.value.includes(p)) : []
    payload.denied_permissions = staff ? rolePermissions.value.filter((p) => !permissions.includes(p)) : []
    payload.activities = staff ? scope.activities : []
    payload.zone_ids = staff ? scope.zone_ids : []
    payload.hub_ids = staff ? scope.hub_ids : []
    if (!form.data.email && form.id) payload.email = null
    if (form.id) {
      // Seuls les champs modifiés sont envoyés (l'historique ne retient que les vrais changements)
      const o = form.original
      for (const k of ['name', 'phone', 'role']) if (payload[k] === o[k]) delete payload[k]
      if (payload.email === (o.email || null)) delete payload.email
      await http.patch(`/users/${form.id}`, payload)
      toasts.success('Compte enregistré.')
    } else {
      const { data } = await http.post('/users', payload)
      toasts.success('Compte créé.')
      // Sans mot de passe : le lien de connexion à envoyer par WhatsApp
      if (!payload.password) await invite(data.data)
    }
    form.open = false
    reload(false)
  } catch (e) {
    form.error = apiErrorMessage(e)
  } finally {
    form.saving = false
  }
}

const closeMenu = () => { menu.value = null }
onMounted(async () => {
  document.addEventListener('click', closeMenu)
  reload()
  await loadCatalog()
})
onBeforeUnmount(() => document.removeEventListener('click', closeMenu))
</script>
