/* Scanner de documents : OpenCV.js chargé dans ce worker au premier usage (environ 3,5 Mo compressés, gardés
 * ensuite en cache par l'application), pour ne jamais figer l'écran pendant le traitement. */
import opencvUrl from '@techstark/opencv-js/dist/opencv.js?url'
import { detect, render } from './core.js'

let loading = null

// OpenCV.js est un objet « thenable » : on le passe enveloppé, jamais tel quel à une promesse
function opencv() {
  loading ??= new Promise((resolve, reject) => {
    try {
      self.importScripts(opencvUrl)
    } catch (e) {
      reject(e)
      return
    }
    const done = () => {
      delete self.cv.then
      resolve({ cv: self.cv })
    }
    if (self.cv?.Mat) done()
    else self.cv.onRuntimeInitialized = done
  }).catch((e) => {
    loading = null
    throw e
  })
  return loading
}

self.onmessage = async ({ data: { id, type, image, corners, mode, maxSide } }) => {
  try {
    const { cv } = await opencv()
    if (type === 'load') {
      self.postMessage({ id, ok: true })
    } else if (type === 'detect') {
      self.postMessage({ id, ok: true, corners: detect(cv, image) })
    } else if (type === 'render') {
      const result = render(cv, image, corners, mode, maxSide)
      self.postMessage({ id, ok: true, ...result }, [result.image.data.buffer])
    }
  } catch (e) {
    self.postMessage({ id, ok: false, error: String(e?.message || e) })
  }
}
