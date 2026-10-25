<template>
  <div class="space-y-4">
    <div class="m-card p-5 flex items-center gap-4">
      <div class="h-14 w-14 rounded-full grid place-items-center text-xl font-bold text-white" :style="{ backgroundColor: 'var(--app-color)' }">
        {{ initials }}
      </div>
      <div class="min-w-0">
        <p class="font-semibold text-lg truncate">{{ auth.user?.name }}</p>
        <p class="text-sm text-slate-600">{{ localPhone(auth.user?.phone) }}</p>
        <p class="text-sm text-slate-600">{{ auth.user?.merchant?.business_name || auth.user?.company?.name }}</p>
      </div>
    </div>

    <InstallBanner :app="app" />

    <!-- Client : un menu court, chaque réglage sur sa propre page -->
    <template v-if="merchant">
      <div class="m-card divide-y divide-slate-100 overflow-hidden">
        <MenuRow v-if="auth.can('integrations.manage')" to="/marchand/boutique" icon="store" label="Ma boutique en ligne" sub="Lien de commande à partager" />
        <MenuRow
          to="/marchand/lieu-de-ramassage" icon="pin" :tone="merchant.has_pickup_location ? 'slate' : 'amber'" label="Lieu de ramassage"
          :sub="!merchant.has_pickup_location ? 'Position à indiquer' : merchant.pickup_location_source === 'courier' ? 'Estimée par nos livreurs : à vérifier' : 'Position enregistrée'"
          :sub-class="merchant.has_pickup_location ? '' : 'text-amber-800 font-medium'"
        />
        <MenuRow v-if="['merchant_owner', 'merchant_manager'].includes(auth.role)" to="/marchand/equipe" icon="user" tone="blue" label="Mon équipe" sub="Comptes et rôles : gestionnaire, agent, lecteur" />
        <MenuRow v-if="merchant.activities?.some((a) => a !== 'ecommerce')" to="/marchand/departements" icon="building" tone="violet" label="Mes départements" sub="Qui envoie, qui reçoit, quotas du mois" />
        <MenuRow to="/marchand/stock" icon="box" label="Mon stock" />
        <MenuRow v-if="merchant.billing_plans?.length && auth.can('finance.view')" to="/marchand/factures" icon="file" tone="red" label="Mes factures" :sub="merchant.billing_plans.map((p) => p.formula_label).join(', ')" />
      </div>

      <div class="m-card divide-y divide-slate-100 overflow-hidden">
        <MenuRow to="/marchand/mes-notifications" icon="bell" tone="amber" label="Mes notifications" sub="WhatsApp, téléphone, point d'activité" />
        <MenuRow v-if="auth.can('finance.view') && merchant.activities?.includes('ecommerce') !== false" to="/marchand/paiements" icon="wallet" tone="green" label="Mon argent" sub="Encaissements et reversements" />
        <MenuRow to="/marchand/statistiques" icon="chart" tone="blue" label="Mes statistiques" />
        <MenuRow to="/marchand/a-noter" icon="user" tone="amber" label="Noter les livreurs" sub="Vos dernières courses, votre livreur attitré" />
        <MenuRow to="/marchand/mes-livreurs" icon="user" tone="slate" label="Mes livreurs" sub="Préféré ou écarté" />
        <MenuRow v-if="auth.can('orders.create')" to="/marchand/courses/import" icon="upload" tone="blue" label="Importer des courses" sub="Fichier Excel ou CSV" />
        <MenuRow v-if="auth.can('integrations.manage')" to="/marchand/integrations" icon="plug" tone="violet" label="Intégrations" sub="Jibiat Marchand, API, webhooks" />
      </div>
    </template>

    <!-- Livreur : son véhicule ; sa note (si l'entreprise la montre) -->
    <div v-if="app === 'livreur'" class="m-card divide-y">
      <MenuRow to="/livreur/vehicule" icon="truck" tone="blue" label="Mon véhicule" sub="État des lieux, plein, papiers, entretien" />
    </div>
    <CourierScoreCard v-if="app === 'livreur'" />

    <PushSettings v-if="app === 'livreur'" />

    <h2 class="px-1 pt-2 text-sm font-semibold text-slate-600 uppercase tracking-wide">Aide</h2>
    <div class="m-card divide-y">
      <div class="p-4 flex justify-between text-sm"><span class="text-slate-600">Entreprise de livraison</span><span class="font-medium">{{ auth.user?.company?.name }}</span></div>
      <div class="p-4 flex justify-between text-sm"><span class="text-slate-600">Rôle</span><span class="font-medium">{{ auth.user?.role_label }}</span></div>
      <a v-if="companyPhone" :href="telLink(companyPhone)" class="p-4 flex justify-between text-sm active:bg-slate-50">
        <span class="text-slate-600">Contacter l'entreprise</span><span class="font-medium text-[var(--app-color)]">{{ companyPhone }}</span>
      </a>
    </div>

    <button class="m-btn-secondary text-red-700" @click="logout"><StatusIcon name="logout" class="h-5 w-5" />Se déconnecter</button>
    <p class="text-center text-xs text-slate-500">Version {{ installedLabel }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import MenuRow from '../../components/mobile/MenuRow.vue'
import CourierScoreCard from '../../components/mobile/CourierScoreCard.vue'
import StatusIcon from '../../components/mobile/StatusIcon.vue'
import PushSettings from '../../components/mobile/PushSettings.vue'
import { forgetPushDevice } from '../../composables/usePush'
import { useAuthStore } from '../../stores/auth'
import { pwa } from '../../composables/usePwa'
import { offlineQueue } from '../../composables/useOfflineQueue'
import { localPhone, telLink } from '../../utils/format'
import { appLock } from '../../native'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const app = computed(() => (route.path.startsWith('/livreur') ? 'livreur' : 'marchand'))
const merchant = computed(() => (auth.user?.merchant_id ? auth.user.merchant || {} : null))
const companyPhone = computed(() => auth.user?.company?.phone)
const initials = computed(() => (auth.user?.name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase())
const installedLabel = computed(() => (pwa.installed ? 'application installée' : 'web'))

async function logout() {
  // Actions faites hors connexion pas encore envoyées : elles attendraient la prochaine connexion
  if (offlineQueue.pending.length && !window.confirm(`${offlineQueue.pending.length} action(s) faite(s) hors connexion ne sont pas encore envoyées. Déconnecté, elles ne partiront que lorsque vous vous reconnecterez sur ce téléphone. Se déconnecter quand même ?`)) return
  await forgetPushDevice()
  // Application installée : sa propre page de connexion (le client reste dans /marchand)
  const login = appLock()?.login || (auth.role === 'courier' ? '/livreur/connexion' : '/')
  await auth.logout()
  router.push(login)
}
</script>
