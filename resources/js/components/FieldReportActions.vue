<template>
  <div v-if="actions.length" class="rounded-xl bg-emerald-50 p-3 ring-1 ring-emerald-200 space-y-2">
    <p class="text-sm font-semibold text-emerald-900">Que faire ?</p>
    <div class="grid gap-2 sm:grid-cols-2">
      <template v-for="(a, i) in actions" :key="a.key">
        <!-- Action qui demande une date : le choix s'ouvre sous le bouton -->
        <div v-if="a.date && picking === a.key" class="sm:col-span-2 rounded-lg bg-white p-2.5 ring-1 ring-emerald-300 space-y-2">
          <label class="label" :for="`fr-date-${report.id}`">{{ a.label }} : quel jour ?</label>
          <div class="flex flex-wrap gap-2">
            <input :id="`fr-date-${report.id}`" v-model="day" type="date" :min="today()" class="input w-auto">
            <button type="button" class="btn-success" :disabled="busy || !day" @click="run(a)">{{ busy ? '…' : 'Confirmer' }}</button>
            <button type="button" class="btn-secondary" @click="picking = null">Annuler</button>
          </div>
        </div>
        <component
          :is="a.href ? 'a' : a.to ? RouterLink : 'button'"
          v-else
          v-bind="a.href ? { href: a.href, target: '_blank', rel: 'noopener' } : a.to ? { to: a.to } : { type: 'button', disabled: busy }"
          :class="['flex items-start gap-2.5 rounded-lg px-3 py-2 text-left text-sm ring-1 transition', i === 0 ? 'bg-emerald-700 text-white ring-emerald-700 hover:bg-emerald-800' : 'bg-white ring-emerald-200 hover:ring-emerald-400']"
          @click="click(a)"
        >
          <span class="mt-0.5 shrink-0" aria-hidden="true"><Icone v-if="ICONS[a.icon]" :nom="ICONS[a.icon]" taille="petite" /><template v-else>•</template></span>
          <span class="min-w-0">
            <span class="block font-semibold">{{ a.label }}</span>
            <span :class="['block text-xs', i === 0 ? 'text-emerald-50' : 'text-slate-600']">{{ a.sub }}</span>
          </span>
        </component>
      </template>
    </div>
  </div>
</template>

<script setup>
import Icone from './ui/Icone.vue'
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiErrorMessage } from '../bootstrap/axios'
import { useToastStore } from '../stores/toasts'
import { telLink, today } from '../utils/format'

/**
 * « Que faire ? » d'une remontée terrain : actions proposées selon le motif de l'échec (liste « suggestions » du
 * serveur, la plus courante d'abord). Retour, relivraison et décision demandée au client sont faits sur la
 * course et marquent la remontée comme traitée ; appel, WhatsApp et position préremplissent le commentaire.
 */
const props = defineProps({ report: { type: Object, required: true } })
const emit = defineEmits(['done', 'prefill'])

const toasts = useToastStore()
const busy = ref(false)
const picking = ref(null)
const tomorrow = () => {
  const d = new Date()
  d.setDate(d.getDate() + 1)
  return d.toISOString().slice(0, 10)
}
const day = ref(tomorrow())
// Icônes de la charte (utils/icones.js)
const ICONS = { retour: 'retour', date: 'date', marchand: 'marchand', appeler: 'appeler', whatsapp: 'message', position: 'adresse', ouvrir: 'course' }

const wa = (text) => {
  const phone = String(props.report.order?.recipient_phone || '').replace(/\D/g, '')
  return phone ? `https://wa.me/${phone}?text=${encodeURIComponent(text)}` : null
}

const actions = computed(() => {
  const r = props.report
  const o = r.order || {}
  const client = o.recipient_name ? o.recipient_name.split(' ')[0] : ''
  const hello = client ? `Bonjour ${client}` : 'Bonjour'
  const exhausted = o.return_cause === 'max_attempts'
  const all = {
    return: { icon: 'retour', label: 'Organiser le retour', sub: `Le colis sera rendu à ${o.merchant || 'son client'}`, server: true },
    redeliver: { icon: 'date', label: 'Reprogrammer', sub: 'Nouvelle date de livraison', server: true, date: true },
    reopen: exhausted
      ? { icon: 'date', label: 'Accorder une tentative de plus', sub: `${o.attempts_count} tentative(s) faite(s) : relivrer à une date`, server: true, date: true }
      : { icon: 'date', label: 'Relivrer', sub: 'Le destinataire a changé d\'avis : annule le retour', server: true, date: true },
    ask_merchant: { icon: 'marchand', label: 'Demander la décision au client', sub: 'Il choisit une nouvelle date ou le retour', server: true },
    call: { icon: 'appeler', label: 'Appeler le destinataire', sub: `${o.recipient_name || 'Destinataire'} · ${o.recipient_phone || ''}`, href: telLink(o.recipient_phone), comment: 'Destinataire appelé : ' },
    whatsapp: { icon: 'whatsapp', label: 'Écrire au destinataire sur WhatsApp', sub: 'Message prêt à envoyer', comment: 'Message WhatsApp envoyé au destinataire',
      href: wa(`${hello}, votre livreur n'a pas pu vous joindre pour votre colis ${o.merchant || ''}. Quand pouvons-nous vous livrer ?`) },
    location: { icon: 'position', label: 'Demander sa position au destinataire', sub: 'Lien de suivi avec « Envoyer ma position »', comment: 'Position demandée au destinataire',
      href: wa(`${hello}, votre livreur ne trouve pas votre adresse. Ouvrez ce lien et touchez « Envoyer ma position » : ${o.tracking_url || ''}`) },
    open: { icon: 'ouvrir', label: 'Ouvrir la course', sub: o.status_label || '', to: `/admin/courses/${o.id}` },
  }

  return (r.suggestions || []).filter((k) => all[k] && (!['call', 'whatsapp', 'location'].includes(k) || all[k].href)).map((k) => ({ key: k, ...all[k] }))
})

function click(a) {
  if (a.server) {
    if (a.date) {
      day.value = tomorrow()
      picking.value = a.key
    } else {
      run(a)
    }
  } else if (a.comment) {
    emit('prefill', a.comment)
  }
}

async function run(a) {
  busy.value = true
  try {
    const { data } = await http.post(`/field-reports/${props.report.id}/act`, { action: a.key, date: a.date ? day.value : undefined })
    picking.value = null
    toasts.success(`${data.data.review?.comment || 'Fait'}. Remontée traitée.`)
    emit('done', data)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>
