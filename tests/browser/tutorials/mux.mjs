// Montage d'un chapitre : vidéo filmée + voix off (chaque phrase posée au début de son geste) → MP4, sous-titres
// WebVTT et image d'aperçu dans public/videos/guide.
// node tests/browser/tutorials/mux.mjs <clé> <audio.mp3> <dossier du tournage>
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import { CHAPTERS } from './chapters.mjs'

const [key, audio, raw] = process.argv.slice(2)
const chapter = CHAPTERS.find((c) => c.key === key)
const rec = JSON.parse(fs.readFileSync(`${raw}/${key}.json`, 'utf8'))
const durations = execFileSync('node', [new URL('./split.mjs', import.meta.url).pathname, audio, String(chapter.segments.length)], { encoding: 'utf8' }).trim().split(',').map(Number)
const bounds = durations.reduce((acc, d) => [...acc, acc[acc.length - 1] + d], [0])
const LEAD = 0.4 // l'écran titre apparaît 0,4 s avant la première mesure
const starts = rec.marks.map((m) => m + LEAD)
const length = starts[starts.length - 1] + durations[durations.length - 1] + 1.0
const dir = new URL('../../../public/videos/guide/', import.meta.url).pathname
fs.mkdirSync(dir, { recursive: true })

// Piste audio d'abord (chaque phrase à sa place), puis la vidéo : en une seule passe, ffmpeg se bloque
const parts = durations.map((_, i) => {
  const ms = Math.round(starts[i] * 1000)
  return `[0:a]atrim=start=${bounds[i].toFixed(3)}:end=${bounds[i + 1].toFixed(3)},asetpts=PTS-STARTPTS,adelay=${ms}|${ms}[a${i}]`
})
const filter = `${parts.join(';')};${durations.map((_, i) => `[a${i}]`).join('')}amix=inputs=${durations.length}:normalize=0,apad=whole_dur=${length.toFixed(2)}[a]`
const wav = `${raw}/${key}.wav`
execFileSync('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', '-i', audio, '-filter_complex', filter, '-map', '[a]', '-ac', '1', wav])
execFileSync('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', '-ss', rec.offset.toFixed(3), '-i', rec.video, '-i', wav,
  '-map', '0:v', '-map', '1:a', '-t', length.toFixed(2),
  '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '27', '-pix_fmt', 'yuv420p', '-r', '25',
  '-c:a', 'aac', '-b:a', '96k', '-ac', '1', '-movflags', '+faststart', `${dir}${key}.mp4`])
// Aperçu : une image du deuxième geste (la page du chapitre est alors chargée)
execFileSync('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', '-ss', (starts[1] + 3).toFixed(2), '-i', `${dir}${key}.mp4`, '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '4', `${dir}${key}.jpg`])

// Sous-titres : chaque phrase, découpée en morceaux au prorata de leur longueur
const ts = (t) => new Date(Math.max(0, t) * 1000).toISOString().slice(11, 23)
const cues = []
chapter.segments.forEach((seg, i) => {
  const pieces = seg.say.match(/[^.!?]+[.!?]+/g)?.map((s) => s.trim()) || [seg.say]
  const speech = durations[i] - (i < durations.length - 1 ? 0.9 : 0.2)
  const chars = pieces.reduce((n, p) => n + p.length, 0)
  let t = starts[i]
  for (const p of pieces) {
    const d = speech * (p.length / chars)
    cues.push(`${ts(t)} --> ${ts(t + d)}\n${p}`)
    t += d
  }
})
fs.writeFileSync(`${dir}${key}.vtt`, `WEBVTT\n\n${cues.join('\n\n')}\n`)
const size = (fs.statSync(`${dir}${key}.mp4`).size / 1e6).toFixed(1)
console.log(`${key}.mp4 : ${length.toFixed(1)} s, ${size} Mo`)
