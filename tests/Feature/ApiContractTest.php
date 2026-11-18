<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    public function test_every_api_method_has_a_versioned_openapi_contract_and_local_references_resolve(): void
    {
        $documented = [];
        foreach ([base_path('docs/openapi.json'), ...glob(base_path('docs/openapi/*.json'))] as $file) {
            $document = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $this->references($document, $document);
            foreach ($document['paths'] as $path => $operations) {
                foreach ($operations as $method => $operation) {
                    if (! in_array($method, ['get', 'post', 'patch', 'delete', 'put'])) {
                        continue;
                    }
                    $key = strtoupper($method).' api/v1'.($path[0] === '/' ? $path : '/'.$path);
                    $documented[] = $key;
                    $this->assertNotEmpty($operation['responses'], $key);
                }
            }
        }
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertContains($method.' '.$route->uri(), $documented);
            }
        }
    }

    private function references(array $node, array $document): void
    {
        if (isset($node['$ref'])) {
            $this->assertStringStartsWith('#/', $node['$ref']);
            $target = $document;
            foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                $this->assertArrayHasKey($part, $target, $node['$ref']);
                $target = $target[$part];
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->references($value, $document);
            }
        }
    }
}
