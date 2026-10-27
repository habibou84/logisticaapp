/**
 * Photos prises dans l'application : lues dans le bon sens (orientation du téléphone), réduites à une taille
 * raisonnable et recompressées en JPEG avant l'envoi. Une photo de 4 à 12 Mo passe à quelques centaines de Ko.
 */

/** Image du fichier, tournée selon l'orientation de la prise de vue */
export async function loadBitmap(file) {
  if (window.createImageBitmap) {
    try {
      return await createImageBitmap(file, { imageOrientation: 'from-image' })
    } catch {
      // Navigateur ancien : chargement par une balise image
    }
  }
  const url = URL.createObjectURL(file)
  try {
    const img = new Image()
    img.src = url
    await img.decode()
    return img
  } finally {
    URL.revokeObjectURL(url)
  }
}

/** Pixels de l'image, grand côté ramené à maxSide au plus */
export function pixels(bitmap, maxSide) {
  const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height))
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(bitmap.width * scale)
  canvas.height = Math.round(bitmap.height * scale)
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  ctx.imageSmoothingQuality = 'high'
  ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  return ctx.getImageData(0, 0, canvas.width, canvas.height)
}

/** Fichier JPEG à partir de pixels { data, width, height } ou d'une image */
export function toJpeg(source, name, quality = 0.88) {
  const canvas = document.createElement('canvas')
  canvas.width = source.width
  canvas.height = source.height
  const ctx = canvas.getContext('2d')
  if (source.data) ctx.putImageData(new ImageData(source.data, source.width, source.height), 0, 0)
  else ctx.drawImage(source, 0, 0)
  return new Promise((resolve, reject) => canvas.toBlob(
    (blob) => (blob ? resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() })) : reject(new Error('Photo illisible.'))),
    'image/jpeg',
    quality,
  ))
}

/**
 * Photo réduite (grand côté maxSide) et recompressée ; en cas d'échec, le fichier d'origine.
 * @returns {Promise<File>}
 */
export async function shrinkPhoto(file, { maxSide = 2000, quality = 0.85, name = 'photo.jpg' } = {}) {
  try {
    const bitmap = await loadBitmap(file)
    const data = pixels(bitmap, maxSide)
    bitmap.close?.()
    const out = await toJpeg(data, name, quality)
    return out.size < file.size || file.type !== 'image/jpeg' ? out : file
  } catch {
    return file
  }
}
