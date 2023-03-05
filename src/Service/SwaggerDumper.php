<?php

declare(strict_types=1);

namespace Packeton\Service;

use Symfony\Component\Yaml\Yaml;

class SwaggerDumper
{
    public function __construct(
        protected string $swaggerDocsDir,
    ) {
    }

    public function dump(array $replacement = []): array
    {
        $list = scandir($this->swaggerDocsDir) ?: [];
        $list = array_filter($list, fn($f) => str_ends_with($f, '.yaml'));
        sort($list);

        $spec = [];
        foreach ($list as $file) {
            $spec = array_merge_recursive(
                $spec,
                Yaml::parse(file_get_contents($this->swaggerDocsDir . '/' . $file))
            );
        }

        $spec = $this->wrapExamples($spec);

        if ($replacement) {
            $template = json_encode($spec, \JSON_UNESCAPED_SLASHES);
            str_replace(array_keys($replacement), array_values($replacement), $template);
            $spec = json_decode($template, true);
        }

        return $spec;
    }

    protected function wrapExamples(array $spec): array
    {
        $objStorage = [];
        $paths = $spec['paths'] ?? [];
        foreach ($paths as $path => $resources) {
            if (!is_array($resources)) {
                continue;
            }

            foreach ($resources as $name => $resource) {
                if (isset($resource['example'])) {
                    $example = ($isRef = str_starts_with($resource['example'], '$')) ? $resource['example'] : json_decode($resource['example']);
                    $hash = sha1(serialize($example));
                    unset($resource['example']);
                    if ($example === null) {
                        continue;
                    }

                    if ($isRef) {
                        $objName = $example = str_replace('$', '', $example);
                        $example = $spec['examples'][$example] ?? [];
                        $example = is_string($example) ? json_decode($example, true) : $example;
                    } else {
                        $objId = $objStorage[$hash] = $objStorage[$hash] ?? count($objStorage);
                        $objName = "Obj$objId";
                    }

                    $resource['requestBody']['content']['application/json']['schema']['$ref'] = "#/components/schemas/$objName";
                    $spec['components']['schemas'][$objName] = $this->dumpExample($example);
                }

                $resources[$name] = $resource;
            }

            $paths[$path] = $resources;
        }

        $spec['paths'] = $paths;
        unset($spec['examples']);

        return $spec;
    }

    protected function dumpExample($example)
    {
        $obj = [
            'type' => 'object',
            'properties' => []
        ];

        foreach ($example as $prop => $value) {
            $obj['properties'][$prop]['example'] = $value;
        }
        return $obj;
    }
}
