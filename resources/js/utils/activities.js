// Activités de l'entreprise : e-commerce, distribution, mise à disposition de livreurs (MAD)
export const ACTIVITIES = [
  { value: 'ecommerce', label: 'E-commerce', short: 'E-com', badge: 'bg-sky-50 text-sky-700' },
  { value: 'distribution', label: 'Distribution', short: 'Distrib.', badge: 'bg-amber-50 text-amber-800' },
  { value: 'mad', label: 'Mise à disposition (MAD)', short: 'MAD', badge: 'bg-violet-50 text-violet-700' },
]

export const activityLabel = (value) => ACTIVITIES.find((a) => a.value === value)?.label ?? value
export const activityShort = (value) => ACTIVITIES.find((a) => a.value === value)?.short ?? value
export const activityBadge = (value) => ACTIVITIES.find((a) => a.value === value)?.badge ?? 'bg-slate-100 text-slate-700'

// Activités que voit ce compte : toutes pour l'administrateur et un périmètre vide, sinon son périmètre
export function myActivities(user) {
  const scope = user && user.role !== 'admin' && !user.merchant_id ? (user.activities || []) : []
  return scope.length ? ACTIVITIES.filter((a) => scope.includes(a.value)) : ACTIVITIES
}
