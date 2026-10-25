// Durée de chaque phrase de la voix off : coupure au milieu des N-1 plus longues pauses.
// node tests/browser/tutorials/split.mjs <audio.mp3> <nombre de segments>  → « 7.4,9.9,… »
import { execFileSync } from 'node:child_process'

const [file, countArg] = process.argv.slice(2)
const count = Number(countArg)
const log = execFileSync('ffmpeg', ['-hide_banner', '-i', file, '-af', 'silencedetect=noise=-35dB:d=0.6', '-f', 'null', '-'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).toString()
  + ''
const err = execFileSync('sh', ['-c', `ffmpeg -hide_banner -i "${file}" -af silencedetect=noise=-35dB:d=0.6 -f null - 2>&1`], { encoding: 'utf8' })
const total = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', file], { encoding: 'utf8' }).trim())
const silences = []
let start = null
for (const line of err.split('\n')) {
  const s = line.match(/silence_start: ([\d.]+)/)
  const e = line.match(/silence_end: ([\d.]+) \| silence_duration: ([\d.]+)/)
  if (s) start = Number(s[1])
  if (e && start !== null) { silences.push({ start, end: Number(e[1]), d: Number(e[2]) }); start = null }
}
const cuts = silences.filter((s) => s.end < total - 0.3).sort((a, b) => b.d - a.d).slice(0, count - 1)
  .map((s) => (s.start + s.end) / 2).sort((a, b) => a - b)
if (cuts.length !== count - 1) throw new Error(`${cuts.length + 1} phrases trouvées au lieu de ${count}`)
const bounds = [0, ...cuts, total]
console.log(bounds.slice(1).map((b, i) => (b - bounds[i]).toFixed(2)).join(','))
void log
