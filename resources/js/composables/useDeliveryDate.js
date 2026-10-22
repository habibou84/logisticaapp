import { computed, ref, watch } from 'vue'
import http from '../bootstrap/axios'

/** AAAA-MM-JJ local, décalé de quelques jours */
export function isoDay(offsetDays = 0, from = null) {
  const d = from ? new Date(`${from}T12:00:00`) : new Date()
  d.setDate(d.getDate() + offsetDays)
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 10)
}

/** « mardi 20 octobre » */
export const longDay = (iso) => new Date(`${iso}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })

/** « mar. 20 » */
const shortDay = (iso) => new Date(`${iso}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' })

/**
 * Jour de livraison d'une nouvelle course : Aujourd'hui / Demain / Autre date, choix obligatoire.
 * « Aujourd'hui » n'est pas proposé quand l'agence a fermé les livraisons du jour (heure limite
 * passée ou journée fermée par le dispatch, voir GET /delivery-day).
 */
export function useDeliveryDate(form) {
  const day = ref(null)
  const when = ref(null)

  const today = computed(() => day.value?.today || isoDay(0))
  const tomorrow = computed(() => isoDay(1, today.value))
  const closed = computed(() => !!day.value?.closed)
  const closedReason = computed(() => (day.value?.reason === 'cutoff'
    ? `Livraisons d'aujourd'hui closes depuis ${day.value.cutoff_time}`
    : 'Livraisons d\'aujourd\'hui fermées par l\'agence'))

  const options = computed(() => [
    { value: 'today', label: 'Aujourd\'hui', description: closed.value ? 'fermé' : shortDay(today.value), disabled: closed.value },
    { value: 'tomorrow', label: 'Demain', description: shortDay(tomorrow.value) },
    { value: 'other', label: 'Autre', description: 'calendrier' },
  ])

  // Premier jour possible dans le calendrier
  const minDate = computed(() => (closed.value ? tomorrow.value : today.value))

  watch(when, (value) => {
    if (value === 'today') form.delivery_scheduled_date = today.value
    if (value === 'tomorrow') form.delivery_scheduled_date = tomorrow.value
    if (value === 'other' && [today.value, tomorrow.value].includes(form.delivery_scheduled_date)) form.delivery_scheduled_date = null
    if (value === null) form.delivery_scheduled_date = null
  })

  // Journée fermée pendant la saisie : « Aujourd'hui » n'est plus possible
  watch(closed, (isClosed) => {
    if (isClosed && (when.value === 'today' || (when.value === 'other' && form.delivery_scheduled_date && form.delivery_scheduled_date < tomorrow.value))) {
      when.value = null
    }
  })

  // Date retenue, rappelée en toutes lettres sous les choix
  const summary = computed(() => {
    if (!when.value) return null
    if (!form.delivery_scheduled_date) return 'Choisissez le jour dans le calendrier.'
    return `📅 Votre course sera livrée le ${longDay(form.delivery_scheduled_date)}.`
  })

  // Choix fait et, pour « Autre date », jour choisi
  const isChosen = computed(() => !!when.value && !!form.delivery_scheduled_date)

  async function load() {
    try {
      day.value = (await http.get('/delivery-day')).data.data
    } catch {
      day.value = null
    }
  }

  function reset() {
    when.value = null
    form.delivery_scheduled_date = null
  }

  return { day, when, options, closed, closedReason, minDate, summary, isChosen, load, reset }
}
