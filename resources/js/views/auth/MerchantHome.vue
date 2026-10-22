<template>
  <div class="min-h-screen bg-slate-50 text-slate-900" :style="{ '--app-color': spaceColor('marchand') }">
    <!-- En-tête -->
    <header v-if="!nativeApp" class="bg-white/90 backdrop-blur border-b border-slate-200 sticky top-0 z-10">
      <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-3">
        <BrandLogo size="md" />
        <a href="#suivi" class="shrink-0 rounded-full border border-[var(--app-color)] text-[var(--app-color)] px-4 py-2 text-sm font-semibold hover:bg-slate-50">📍 Suivre un colis</a>
      </div>
    </header>

    <!-- Présentation et connexion -->
    <!-- Application : la connexion seule, en plein écran -->
    <section :class="['text-white', { 'min-h-screen': nativeApp }]" :style="{ paddingTop: nativeApp ? 'env(safe-area-inset-top)' : null, background: `linear-gradient(135deg, rgba(0,0,0,.12), rgba(255,255,255,.08)), ${spaceColor('marchand')}` }">
      <div class="max-w-6xl mx-auto px-4 py-10 md:py-16 grid md:grid-cols-2 gap-10 items-center">
        <div v-if="!nativeApp" class="space-y-6">
          <p class="inline-block rounded-full bg-white/15 px-3 py-1 text-sm font-medium">Espace e-commerçants</p>
          <h1 class="text-3xl md:text-5xl font-extrabold leading-tight">
            {{ branding.tagline || 'Vos colis livrés vite, vos clients satisfaits.' }}
          </h1>
          <p class="text-lg text-white/90 max-w-xl">
            Créez vos courses en une minute, suivez chaque livraison en temps réel et recevez l'argent encaissé
            auprès de vos clients sans courir après.
          </p>
          <ul class="grid sm:grid-cols-2 gap-3 max-w-xl">
            <li v-for="b in benefits" :key="b.title" class="flex gap-3 rounded-2xl bg-white/10 p-3">
              <span class="text-2xl" aria-hidden="true">{{ b.icon }}</span>
              <span>
                <span class="block font-semibold">{{ b.title }}</span>
                <span class="block text-sm text-white/80">{{ b.text }}</span>
              </span>
            </li>
          </ul>
        </div>

        <div class="w-full max-w-md md:justify-self-end">
          <div class="rounded-3xl bg-white text-slate-900 shadow-2xl p-6 md:p-8 space-y-6">
            <div class="text-center space-y-2">
              <BrandLogo size="lg" :with-name="false" class="justify-center" />
              <h2 class="text-2xl font-bold">Connexion</h2>
              <p class="text-sm text-slate-500">Accédez à vos courses, à vos encaissements et à vos reversements.</p>
            </div>
            <LoginForm tone="merchant" />
            <InstallBanner app="marchand" class="md:hidden" />
            <NativeCompanySwitch />
            <div v-if="branding.signup_open" class="border-t border-slate-200 pt-5 text-center space-y-2">
              <p class="text-sm text-slate-600">Pas encore client ?</p>
              <RouterLink to="/inscription" class="block w-full rounded-xl border-2 border-[var(--app-color)] py-3 font-semibold text-[var(--app-color)] hover:bg-slate-50">Créer mon compte gratuitement</RouterLink>
            </div>
            <p v-else class="text-center text-sm text-slate-600">
              Pas encore client ?
              <a v-if="contactLink" :href="contactLink" target="_blank" rel="noopener" class="font-semibold text-[var(--app-color)] hover:underline">Ouvrez votre compte</a>
              <span v-else class="font-semibold">Contactez-nous</span>
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- Comment ça marche -->
    <section v-if="!nativeApp" class="max-w-6xl mx-auto px-4 py-12 md:py-16">
      <h2 class="text-2xl md:text-3xl font-bold text-center">Comment ça marche ?</h2>
      <ol class="mt-8 grid md:grid-cols-3 gap-6">
        <li v-for="(s, i) in steps" :key="s.title" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <span class="grid h-10 w-10 place-items-center rounded-full bg-[var(--app-color)] font-bold text-white">{{ i + 1 }}</span>
          <h3 class="mt-4 font-semibold text-lg">{{ s.title }}</h3>
          <p class="mt-1 text-slate-600">{{ s.text }}</p>
        </li>
      </ol>
    </section>

    <!-- Suivi d'un colis (destinataires) -->
    <section v-if="!nativeApp" id="suivi" class="bg-white border-y border-slate-200">
      <div class="max-w-3xl mx-auto px-4 py-12 text-center space-y-4">
        <h2 class="text-2xl font-bold">Vous attendez un colis ?</h2>
        <p class="text-slate-600">Entrez le code de suivi reçu par WhatsApp ou SMS (il commence par LV-).</p>
        <form class="flex flex-col sm:flex-row gap-3 max-w-lg mx-auto" @submit.prevent="track">
          <label class="sr-only" for="tracking-code">Code de suivi</label>
          <input id="tracking-code" v-model.trim="trackingCode" class="flex-1 rounded-xl border border-slate-300 px-4 py-3 text-base uppercase focus:outline-none focus:ring-2 focus:ring-[var(--app-color)]" placeholder="LV-XXXX-XXXX" required>
          <button class="rounded-xl bg-slate-900 px-6 py-3 font-semibold text-white hover:bg-slate-700">Suivre</button>
        </form>
      </div>
    </section>

    <!-- Contact -->
    <footer v-if="!nativeApp" class="bg-slate-900 text-slate-300">
      <div class="max-w-6xl mx-auto px-4 py-10 grid md:grid-cols-3 gap-6 items-start">
        <div class="space-y-2">
          <BrandLogo size="sm" dark />
          <p v-if="branding.address" class="text-sm">{{ branding.address }}</p>
        </div>
        <div class="space-y-2 text-sm">
          <p class="font-semibold text-white">Nous contacter</p>
          <p v-if="branding.phone"><a :href="`tel:${branding.phone}`" class="hover:text-white">📞 {{ branding.phone }}</a></p>
          <p v-if="contactLink"><a :href="contactLink" target="_blank" rel="noopener" class="hover:text-white">💬 WhatsApp</a></p>
          <p v-if="branding.email"><a :href="`mailto:${branding.email}`" class="hover:text-white">✉️ {{ branding.email }}</a></p>
        </div>
        <div class="space-y-2 text-sm md:text-right">
          <p>Application mobile : ouvrez ce site sur votre téléphone ; la page vous guide pour l'installer.</p>
          <p class="text-slate-500">© {{ year }} {{ branding.name }}</p>
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import BrandLogo from '../../components/auth/BrandLogo.vue'
import LoginForm from '../../components/auth/LoginForm.vue'
import InstallBanner from '../../components/mobile/InstallBanner.vue'
import NativeCompanySwitch from '../../components/auth/NativeCompanySwitch.vue'
import { nativeApp } from '../../native'
import { branding, loadBranding, whatsappLink, spaceColor } from '../../composables/useBranding'

const router = useRouter()
const trackingCode = ref('')
const year = new Date().getFullYear()

const benefits = [
  { icon: '⚡', title: 'Course en 1 minute', text: 'Depuis votre téléphone, WhatsApp ou un fichier Excel.' },
  { icon: '📍', title: 'Suivi en direct', text: 'Chaque étape, chaque livreur, chaque incident.' },
  { icon: '💵', title: 'Encaissements reversés', text: 'Relevés clairs et reversements réguliers.' },
  { icon: '💬', title: 'Clients informés', text: 'Messages WhatsApp à chaque étape importante.' },
]

const steps = [
  { title: 'Créez la course', text: 'Destinataire, commune, montant à encaisser : le tarif s\'affiche aussitôt.' },
  { title: 'Nous ramassons et livrons', text: 'Un livreur passe chez vous, puis livre votre client et encaisse si besoin.' },
  { title: 'Vous êtes payé', text: 'Suivez vos encaissements et recevez vos reversements avec leur relevé.' },
]

const contactLink = computed(() => whatsappLink(branding.phone, `Bonjour, je souhaite ouvrir un compte e-commerçant chez ${branding.name}.`))

function track() {
  router.push(`/suivi/${trackingCode.value.toUpperCase()}`)
}

onMounted(() => loadBranding())
</script>
