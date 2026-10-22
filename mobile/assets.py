"""Icônes et écrans de démarrage Android et iOS des applications, à partir des icônes du site.

    python3 mobile/assets.py

Source : public/app-icons/<icône>-maskable-512.png (symbole dans la zone sûre, fond plein).
"""
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent
ICONS = ROOT.parent / 'public' / 'app-icons'
APPS = {'livreur': 'livreur', 'ecommercant': 'marchand'}
DENSITIES = {'mdpi': 1, 'hdpi': 1.5, 'xhdpi': 2, 'xxhdpi': 3, 'xxxhdpi': 4}


def square(source: Image.Image, size: int) -> Image.Image:
    return source.resize((size, size), Image.LANCZOS)


def round_mask(image: Image.Image) -> Image.Image:
    mask = Image.new('L', image.size, 0)
    from PIL import ImageDraw
    ImageDraw.Draw(mask).ellipse((0, 0, image.size[0] - 1, image.size[1] - 1), fill=255)
    out = Image.new('RGBA', image.size, (0, 0, 0, 0))
    out.paste(image, (0, 0), mask)
    return out


for app, icon in APPS.items():
    res = ROOT / app / 'android' / 'app' / 'src' / 'main' / 'res'
    maskable = Image.open(ICONS / f'{icon}-maskable-512.png').convert('RGBA')
    background = maskable.getpixel((2, 2))[:3]
    hex_color = '#%02X%02X%02X' % background

    for name, scale in DENSITIES.items():
        folder = res / f'mipmap-{name}'
        legacy = round(48 * scale)
        # Icône classique (anciens Android) : carré aux coins arrondis par le lanceur, et ronde
        square(maskable, legacy).save(folder / 'ic_launcher.png')
        round_mask(square(maskable, legacy)).save(folder / 'ic_launcher_round.png')
        # Icône adaptative : premier plan de 108 dp dont le lanceur ne montre que le centre (72 dp)
        layer = round(108 * scale)
        inner = round(layer * 0.8)
        foreground = Image.new('RGBA', (layer, layer), background + (255,))
        foreground.paste(square(maskable, inner), ((layer - inner) // 2, (layer - inner) // 2))
        foreground.save(folder / 'ic_launcher_foreground.png')

    (res / 'values' / 'ic_launcher_background.xml').write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
        f'    <color name="ic_launcher_background">{hex_color}</color>\n</resources>\n'
    )

    # Écrans de démarrage : couleur de l'application et symbole au centre, aux tailles du modèle
    for splash in res.glob('drawable*/splash.png'):
        width, height = Image.open(splash).size
        canvas = Image.new('RGB', (width, height), background)
        size = round(min(width, height) * 0.45)
        canvas.paste(square(maskable, size).convert('RGB'), ((width - size) // 2, (height - size) // 2))
        canvas.save(splash)

    # iOS : icône de 1024 px sans transparence et écran de démarrage universel
    xcassets = ROOT / app / 'ios' / 'App' / 'App' / 'Assets.xcassets'
    if xcassets.exists():
        square(maskable, 1024).convert('RGB').save(xcassets / 'AppIcon.appiconset' / 'AppIcon-512@2x.png')
        for splash in (xcassets / 'Splash.imageset').glob('*.png'):
            width, height = Image.open(splash).size
            canvas = Image.new('RGB', (width, height), background)
            size = round(min(width, height) * 0.25)
            canvas.paste(square(maskable, size).convert('RGB'), ((width - size) // 2, (height - size) // 2))
            canvas.save(splash)

    print(app, hex_color)
