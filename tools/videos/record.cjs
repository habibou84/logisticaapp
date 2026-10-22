// Enregistre une vidéo de démonstration : node tools/videos/record.cjs <scénario> [dossier de travail]
// Puis montage : python3 tools/videos/build.py <dossier de travail>
const fs = require('fs');
const path = require('path');
const { segment } = require('./lib.cjs');

(async () => {
  const name = process.argv[2];
  if (!name) {
    console.log('Scénarios : ' + fs.readdirSync(path.join(__dirname, 'scenarios')).map((f) => f.replace('.cjs', '')).join(', '));
    process.exit(1);
  }
  const scenario = require(path.join(__dirname, 'scenarios', name + '.cjs'));
  const work = path.resolve(process.argv[3] || path.join(__dirname, '.work', name));
  fs.rmSync(work, { recursive: true, force: true });
  fs.mkdirSync(work, { recursive: true });

  const segments = [];
  await scenario.record({
    segment: async (id, options) => {
      const seg = await segment(work, id, options);
      segments.push({ id, layout: options.device, address: options.address || null });
      return seg;
    },
  });
  fs.writeFileSync(path.join(work, 'spec.json'), JSON.stringify({ ...scenario.spec, segments }, null, 1));
  console.log(`Enregistré dans ${work}`);
})().catch((e) => { console.error(e); process.exit(1); });
