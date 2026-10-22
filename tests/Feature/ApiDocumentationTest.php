<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * La spécification OpenAPI décrit exactement les routes de l'API publique.
 */
class ApiDocumentationTest extends TestCase
{
    public function test_every_public_route_is_documented(): void
    {
        $spec = Yaml::parseFile(public_path('docs/openapi.yaml'));
        $this->assertSame('3.1.0', $spec['openapi']);

        $documented = collect($spec['paths'])
            ->flatMap(fn ($operations, $path) => collect(array_keys($operations))->map(fn ($m) => strtoupper($m).' '.$path))
            ->sort()->values()->all();

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => Str::startsWith($r->uri(), 'api/public/v1/'))
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')
                ->map(fn ($m) => $m.' /'.preg_replace('/\{trackingCode\}/', '{tracking_code}', Str::after($r->uri(), 'api/public/v1/'))))
            ->sort()->values()->all();

        $this->assertSame($routes, $documented);
    }

    public function test_documentation_page_is_served(): void
    {
        $this->get('/developpeurs/api')->assertOk()->assertSee('docs/openapi.yaml', false);
        // Fichier statique servi directement par le serveur web
        $this->assertFileExists(public_path('docs/openapi.yaml'));
    }
}
