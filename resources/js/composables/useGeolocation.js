// Position GPS du téléphone, attachée aux changements de statut quand elle est disponible.
// withAccuracy : ajoute la précision en mètres (historique des trajets)
export function currentPosition(timeout = 5000, withAccuracy = false) {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve(null)
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({
        lat: Number(p.coords.latitude.toFixed(7)),
        lng: Number(p.coords.longitude.toFixed(7)),
        ...(withAccuracy && p.coords.accuracy ? { accuracy: Math.round(p.coords.accuracy) } : {}),
      }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout, maximumAge: 60000 },
    )
  })
}
