"""Montage d'une vidéo de démonstration Jibiat (MP4 H.264) à partir d'un enregistrement.

    python3 tools/videos/build.py tools/videos/.work/<scénario> [logo.png]

Entrées : spec.json et un dossier par segment (frames/ + timeline.json), écrits par record.cjs.
Sortie : <dossier>/out/<nom>.mp4 — 1080 × 1920 (format « vertical ») ou 1920 × 1080 (« horizontal »).
"""
import json
import subprocess
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

WORK = Path(sys.argv[1]).resolve()
LOGO = Path(sys.argv[2]) if len(sys.argv) > 2 else Path(__file__).resolve().parents[2] / 'public/marketing/logo.png'
SPEC = json.loads((WORK / 'spec.json').read_text())
OUT = WORK / 'out'
TMP = WORK / 'tmp'
OUT.mkdir(exist_ok=True)
TMP.mkdir(exist_ok=True)

VERTICAL = SPEC.get('format', 'vertical') == 'vertical'
W, H = (1080, 1920) if VERTICAL else (1920, 1080)
FPS = 30
NAVY = (11, 17, 32)
ORANGE = (249, 111, 2)
FLAME = (241, 52, 14)
PEACH = (255, 173, 112)
FONT = '/usr/share/fonts/opentype/inter/Inter-{}.otf'
OUTRO = [['title', 'Toute votre entreprise de livraison dans une seule application'], ['pill', 'Essai gratuit 30 jours'], ['url', 'jibiat.com']]


def font(weight, size):
    return ImageFont.truetype(FONT.format(weight), size)


def run(cmd):
    result = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    if result.returncode:
        sys.exit(result.stderr.decode()[-3000:])


def background():
    """Fond bleu nuit avec halos orange, comme le site."""
    img = Image.new('RGB', (W, H), NAVY)
    glow = Image.new('RGB', (W, H), (0, 0, 0))
    g = ImageDraw.Draw(glow)
    if VERTICAL:
        g.ellipse((560, -260, 1400, 580), fill=(150, 60, 6))
        g.ellipse((-420, 1320, 420, 2160), fill=(120, 30, 10))
    else:
        g.ellipse((1250, -350, 2250, 450), fill=(150, 60, 6))
        g.ellipse((-450, 650, 450, 1450), fill=(120, 30, 10))
    glow = glow.filter(ImageFilter.GaussianBlur(160))
    return Image.blend(img, Image.composite(glow, img, Image.new('L', (W, H), 255)), 0.85)


def paste_logo(img, size, xy):
    logo = Image.open(LOGO).convert('RGBA').resize((size, size), Image.LANCZOS)
    img.paste(logo, xy, logo)


def wrap(draw, text, fnt, max_width):
    lines, line = [], ''
    for word in text.split():
        trial = f'{line} {word}'.strip()
        if draw.textlength(trial, font=fnt) <= max_width or not line:
            line = trial
        else:
            lines.append(line)
            line = word
    return lines + [line]


def text_block(draw, text, y, fnt, fill, width, x0=0, align='center', spacing=12):
    """Texte sur plusieurs lignes dans la bande [x0, x0 + width] ; renvoie le bas du bloc."""
    for ln in wrap(draw, text, fnt, width):
        w = draw.textlength(ln, font=fnt)
        x = x0 + (width - w) / 2 if align == 'center' else x0
        draw.text((x, y), ln, font=fnt, fill=fill)
        y += fnt.size + spacing
    return y


def step_pill(draw, text, x, y, size, center=False):
    sf = font('Bold', size)
    tw = draw.textlength(text, font=sf) + size * 1.3
    h = size * 1.75
    if center:
        x -= tw / 2
    draw.rounded_rectangle((x, y, x + tw, y + h), radius=h / 2, fill=ORANGE)
    draw.text((x + size * 0.65, y + (h - size) / 2 - size * 0.12), text, font=sf, fill='white')
    return y + h


# --- Mises en page --------------------------------------------------------
class Layout:
    """Où placer l'écran filmé et les sous-titres, selon l'appareil et le format."""

    def __init__(self, device, address=None):
        self.device = device
        self.address = address
        if device == 'phone':
            if VERTICAL:
                self.sh = 1340
                self.sx, self.sy = None, 440
            else:
                self.sh = 940
                self.sx, self.sy = None, 70
            self.sw = round(self.sh * 780 / 1688)
            self.sx = (W - self.sw) // 2 if VERTICAL else 1390 - self.sw // 2
        else:
            if VERTICAL:
                raise SystemExit('Un segment « ordinateur » demande le format horizontal.')
            self.sw, self.sh = 1600, 900
            self.sx, self.sy = (W - 1600) // 2, 164

    def background(self):
        bg = background()
        d = ImageDraw.Draw(bg)
        if self.device == 'phone':
            r = 74 if VERTICAL else 54
            d.rounded_rectangle((self.sx - 16, self.sy - 16, self.sx + self.sw + 16, self.sy + self.sh + 16), radius=r, fill=(22, 26, 38), outline=(60, 66, 84), width=3)
        else:
            # Fenêtre de navigateur : barre de titre et adresse
            top = self.sy - 46
            d.rounded_rectangle((self.sx - 2, top - 2, self.sx + self.sw + 2, self.sy + self.sh + 2), radius=16, fill=(60, 66, 84))
            d.rounded_rectangle((self.sx, top, self.sx + self.sw, self.sy + 20), radius=14, fill=(30, 35, 48))
            for i, c in enumerate([(255, 95, 87), (254, 188, 46), (40, 200, 64)]):
                d.ellipse((self.sx + 18 + i * 22, top + 17, self.sx + 30 + i * 22, top + 29), fill=c)
            if self.address:
                af = font('Medium', 20)
                aw = max(d.textlength(self.address, font=af) + 60, 520)
                ax = self.sx + (self.sw - aw) / 2
                d.rounded_rectangle((ax, top + 9, ax + aw, top + 37), radius=14, fill=(18, 22, 33))
                d.text((ax + (aw - d.textlength(self.address, font=af)) / 2, top + 11), self.address, font=af, fill=(203, 213, 225))

        # Marque et adresse du site
        if VERTICAL:
            paste_logo(bg, 64, (64, 70))
            d.text((142, 82), 'Jibiat', font=font('Bold', 40), fill='white')
            uf = font('SemiBold', 34)
            d.text(((W - d.textlength('jibiat.com', font=uf)) / 2, H - 82), 'jibiat.com', font=uf, fill=PEACH)
        else:
            paste_logo(bg, 52, (44, 40))
            d.text((108, 48), 'Jibiat', font=font('Bold', 34), fill='white')
            uf = font('SemiBold', 28)
            if self.device == 'phone':
                d.text((120, H - 84), 'jibiat.com', font=uf, fill=PEACH)
            else:
                d.text((W - 44 - d.textlength('jibiat.com', font=uf), 52), 'jibiat.com', font=uf, fill=PEACH)
        return bg

    def mask(self):
        m = Image.new('L', (self.sw, self.sh), 0)
        md = ImageDraw.Draw(m)
        if self.device == 'phone':
            md.rounded_rectangle((0, 0, self.sw - 1, self.sh - 1), radius=58 if VERTICAL else 40, fill=255)
        else:
            md.rounded_rectangle((0, 0, self.sw - 1, self.sh - 1), radius=14, fill=255)
            md.rectangle((0, 0, self.sw - 1, 20), fill=255)
        return m

    def caption(self, text, step):
        """Image transparente du sous-titre et sa position."""
        if self.device == 'phone' and VERTICAL:
            img = Image.new('RGBA', (W, 270), (0, 0, 0, 0))
            d = ImageDraw.Draw(img)
            y = 10
            if step:
                y = step_pill(d, step, W / 2, y, 30, center=True) + 22
            text_block(d, text, y, font('ExtraBold', 58), 'white', W - 140, x0=70, spacing=10)
            return img, (0, 150)
        if self.device == 'phone':
            img = Image.new('RGBA', (860, 620), (0, 0, 0, 0))
            d = ImageDraw.Draw(img)
            y = 0
            if step:
                y = step_pill(d, step, 0, y, 30) + 30
            bottom = text_block(d, text, y, font('ExtraBold', 68), 'white', 860, align='left', spacing=14)
            img = img.crop((0, 0, 860, int(bottom)))
            return img, (130, (H - img.height) // 2)
        img = Image.new('RGBA', (1320, 150), (0, 0, 0, 0))
        d = ImageDraw.Draw(img)
        fnt = font('ExtraBold', 46)
        lines = wrap(d, text, fnt, 1320 - (200 if step else 0))
        if step and len(lines) == 1:
            # Étape et texte sur une ligne
            sf = font('Bold', 26)
            pw = d.textlength(step, font=sf) + 34
            tw = d.textlength(lines[0], font=fnt)
            x = (1320 - pw - 22 - tw) / 2
            step_pill(d, step, x, 48, 26)
            d.text((x + pw + 22, 40), lines[0], font=fnt, fill='white')
        else:
            y = 8 if len(lines) > 1 else 40
            if step:
                y = step_pill(d, step, 660, 0, 22, center=True) + 8
            text_block(d, text, y, fnt, 'white', 1320, spacing=6)
        return img, ((W - 1320) // 2, 4)


# --- Un segment : écran filmé + mise en page + sous-titres -----------------
def build_segment(k, seg):
    timeline = json.loads((WORK / seg['id'] / 'timeline.json').read_text())
    frames, marks = timeline['frames'], timeline['marks']
    end = marks[-1]['t'] / 1000
    layout = Layout(seg['layout'], seg.get('address'))
    tmp = TMP / seg['id']
    tmp.mkdir(exist_ok=True)

    # Images à leur instant réel (durée minimale infime : les images arrivent jusqu'à 60 par seconde)
    lines = []
    for i, f in enumerate(frames):
        nxt = frames[i + 1]['t'] / 1000 if i + 1 < len(frames) else end
        lines += [f"file '{f['file']}'", f'duration {max(nxt - f["t"] / 1000, 0.001):.4f}']
    lines.append(f"file '{frames[-1]['file']}'")
    (tmp / 'frames.txt').write_text('\n'.join(lines))
    run(['ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', str(tmp / 'frames.txt'), '-vf', f'fps={FPS},scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p',
         '-c:v', 'libx264', '-crf', '14', '-preset', 'medium', str(tmp / 'screen.mp4')])

    layout.background().save(tmp / 'bg.png')
    layout.mask().save(tmp / 'mask.png')

    captions = []
    for i, m in enumerate(marks[:-1]):
        text = SPEC['captions'].get(m['id'])
        if not text:
            continue
        img, pos = layout.caption(text, SPEC.get('steps', {}).get(m['id']))
        path = tmp / f'cap{i}.png'
        img.save(path)
        captions.append((path, pos, m['t'] / 1000, marks[i + 1]['t'] / 1000))

    loop = ['-loop', '1', '-t', f'{end:.3f}', '-i']
    inputs = [*loop, str(tmp / 'bg.png'), '-i', str(tmp / 'screen.mp4'), *loop, str(tmp / 'mask.png')]
    graph = [f'[1]scale={layout.sw}:{layout.sh}:flags=lanczos,format=rgba[s]', f'[2]format=gray,scale={layout.sw}:{layout.sh}[m]',
             '[s][m]alphamerge[sm]', f'[0][sm]overlay={layout.sx}:{layout.sy}:shortest=1[v0]']
    last = 'v0'
    for c, (path, (x, y), a, b) in enumerate(captions):
        inputs += [*loop, str(path)]
        graph.append(f'[{3 + c}]format=rgba,fade=in:st={a:.2f}:d=0.3:alpha=1,fade=out:st={max(b - 0.25, a):.2f}:d=0.25:alpha=1[c{c}]')
        graph.append(f'[{last}][c{c}]overlay={x}:{y}[v{c + 1}]')
        last = f'v{c + 1}'
    # Fondu entre deux segments
    graph.append(f'[{last}]fps={FPS},format=yuv420p,fade=in:st=0:d=0.3,fade=out:st={end - 0.3:.2f}:d=0.3[out]')
    target = TMP / f'main{k}.mp4'
    run(['ffmpeg', '-y', *inputs, '-filter_complex', ';'.join(graph), '-map', '[out]', '-t', f'{end:.3f}',
         '-c:v', 'libx264', '-crf', '18', '-preset', 'medium', str(target)])
    return target, end


# --- Ouverture et fin ------------------------------------------------------
def card(lines_spec, path):
    img = background()
    d = ImageDraw.Draw(img)
    logo = 260 if VERTICAL else 200
    paste_logo(img, logo, ((W - logo) // 2, 520 if VERTICAL else 180))
    y = 840 if VERTICAL else 430
    width = W - 140 if VERTICAL else 1400
    x0 = (W - width) / 2
    for kind, text in lines_spec:
        if kind == 'title':
            y = text_block(d, text, y, font('ExtraBold', 76 if VERTICAL else 72), 'white', width, x0) + 30
        elif kind == 'subtitle':
            y = text_block(d, text, y, font('Medium', 42 if VERTICAL else 38), (203, 213, 225), width, x0) + 40
        elif kind == 'pill':
            pf = font('Bold', 44 if VERTICAL else 38)
            pw = d.textlength(text, font=pf) + 70
            ph = pf.size + 44
            d.rounded_rectangle(((W - pw) / 2, y, (W + pw) / 2, y + ph), radius=ph / 2, fill=FLAME)
            d.text(((W - pw) / 2 + 35, y + 18), text, font=pf, fill='white')
            y += ph + 50
        elif kind == 'url':
            y = text_block(d, text, y, font('Bold', 52 if VERTICAL else 46), PEACH, width, x0) + 20
    img.save(path)


def still(png, seconds, mp4):
    run(['ffmpeg', '-y', '-loop', '1', '-t', str(seconds), '-i', str(png), '-vf',
         f'fps={FPS},format=yuv420p,fade=in:st=0:d=0.4,fade=out:st={seconds - 0.4}:d=0.4',
         '-c:v', 'libx264', '-crf', '18', str(mp4)])


intro_s, outro_s = SPEC.get('intro_seconds', 2.8), SPEC.get('outro_seconds', 3.6)
card(SPEC['intro'], TMP / 'intro.png')
card(SPEC.get('outro', OUTRO), TMP / 'outro.png')
still(TMP / 'intro.png', intro_s, TMP / 'intro.mp4')
still(TMP / 'outro.png', outro_s, TMP / 'outro.mp4')

parts = [TMP / 'intro.mp4']
total = intro_s + outro_s
for k, seg in enumerate(SPEC['segments']):
    path, seconds = build_segment(k, seg)
    parts.append(path)
    total += seconds
parts.append(TMP / 'outro.mp4')

# --- Assemblage, piste son muette (certains réseaux l'exigent) -------------
inputs = [arg for p in parts for arg in ('-i', str(p))]
n = len(parts)
target = OUT / f"{SPEC['name']}.mp4"
run(['ffmpeg', '-y', *inputs, '-f', 'lavfi', '-t', f'{total:.2f}', '-i', 'anullsrc=r=44100:cl=stereo',
     '-filter_complex', ''.join(f'[{i}:v]' for i in range(n)) + f'concat=n={n}:v=1:a=0[v]', '-map', '[v]', '-map', f'{n}:a',
     '-c:v', 'libx264', '-crf', '20', '-preset', 'slow', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
     '-c:a', 'aac', '-b:a', '64k', '-shortest', '-movflags', '+faststart', str(target)])
print(target, f'{total:.1f} s')
