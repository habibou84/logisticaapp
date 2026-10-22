<?php

// Visuels de la boutique de démonstration de Jibiat Marchand : une image par produit et l'image d'accueil.
// Dessins faits par gen.html (node gen.cjs), ajoutés à la base locale :
//   MARCHAND=/chemin/vers/ecommerce php tools/motion/boutique-demo/attach.php
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Services\Catalog\ImageStore;
use App\Services\Storefront\ThemeSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;

$root = getenv('MARCHAND') ?: dirname(__DIR__, 4).'/ecommerce';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dir = __DIR__;
$store = Store::where('slug', 'demo')->firstOrFail();
$map = [
    'Robe wax évasée' => 'robe', 'Robe Tabaski brodée (précommande)' => 'tabaski', 'Ensemble pagne deux pièces' => 'ensemble',
    'Chemise homme bogolan' => 'chemise', 'Pagne Vlisco 6 yards' => 'pagne', 'Sac en pagne' => 'sac', 'Collier perles' => 'collier',
    'Foulard satin' => 'foulard', 'Sandales cuir' => 'sandales', 'Beurre de karité 250 g' => 'karite', 'Pack robe + sac' => 'pack',
];
$images = app(ImageStore::class);
foreach ($map as $title => $file) {
    $product = Product::forStore($store->id)->where('title', $title)->firstOrFail();
    ProductImage::withoutGlobalScopes()->where('product_id', $product->id)->get()->each(fn ($i) => [$images->delete($i->path), $i->delete()]);
    $saved = $images->store(new UploadedFile("{$dir}/{$file}.png", "{$file}.png", 'image/png', null, true), $store->id);
    ProductImage::withoutGlobalScopes()->create(['store_id' => $store->id, 'product_id' => $product->id, 'path' => $saved['path'], 'alt' => $title, 'width' => $saved['width'], 'height' => $saved['height'], 'position' => 0]);
    echo "✓ {$title}\n";
}
app(ThemeSettings::class)->setHeroImage($store, new UploadedFile("{$dir}/hero.png", 'hero.png', 'image/png', null, true));
echo "✓ image d'accueil\n";
