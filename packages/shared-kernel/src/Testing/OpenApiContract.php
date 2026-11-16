<?php

declare(strict_types=1);

namespace Mestaro\Kernel\Testing;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract checks against docs/openapi.yaml for every service's tests (needs symfony/yaml in the service's require-dev).
 * Paths carry `x-service` unless they belong to platform, so each service only checks its own.
 */
final class OpenApiContract
{
    /**
     * The container sees the contract at /var/docs; CI and the host at ../../docs from the service.
     *
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        static $spec;
        foreach ([base_path('../../docs/openapi.yaml'), '/var/docs/openapi.yaml'] as $path) {
            if (is_file($path)) {
                return $spec ??= Yaml::parseFile($path);
            }
        }
        throw new RuntimeException('docs/openapi.yaml not found.');
    }

    /**
     * "METHOD /path" of every operation documented for the service.
     *
     * @return list<string>
     */
    public static function operations(string $service): array
    {
        $owned = fn (array $item) => ($item['x-service'] ?? 'platform') === $service;
        $ops = [];
        foreach (self::spec()['paths'] as $path => $item) {
            foreach ($owned($item) ? array_keys($item) : [] as $method) {
                in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) && $ops[] = strtoupper($method).' '.$path;
            }
        }
        sort($ops);

        return $ops;
    }

    /** Null when the response matches what the operation documents for its status; otherwise why not. */
    public static function check(string $method, string $path, int $status, string $body): ?string
    {
        $spec = self::spec();
        $operation = $spec['paths'][$path][strtolower($method)] ?? null;
        if ($operation === null) {
            return "$method $path is not in openapi.yaml";
        }
        $declared = $operation['responses'][(string) $status] ?? null;
        if ($declared === null) {
            return "$method $path answers $status, which openapi.yaml does not list";
        }
        $schema = ($declared['content']['application/json']['schema'] ?? null)
            ?? (isset($declared['$ref']) ? $spec['components']['responses'][basename($declared['$ref'])]['content']['application/json']['schema'] : null);
        if ($schema === null) {
            return null;
        }
        $errors = self::errors(json_decode($body, true), $schema);

        return $errors === [] ? null : "$method $path $status: ".implode('; ', $errors);
    }

    /**
     * Minimal JSON Schema subset used by our spec: $ref, oneOf, type (incl. null), enum, pattern, required, properties, items.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function errors(mixed $value, array $schema, string $at = '$'): array
    {
        if (isset($schema['$ref'])) {
            $node = self::spec();
            foreach (explode('/', ltrim($schema['$ref'], '#/')) as $part) {
                $node = $node[$part];
            }

            return self::errors($value, $node, $at);
        }
        if (isset($schema['oneOf'])) {
            $matches = array_filter($schema['oneOf'], fn ($s) => self::errors($value, $s, $at) === []);

            return count($matches) === 1 ? [] : ["$at matches ".count($matches).' of the oneOf schemas'];
        }

        $errors = [];
        $types = (array) ($schema['type'] ?? []);
        $actual = match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => 'other',
        };
        // An empty PHP array decodes from `{}` or `[]` alike.
        if ($types !== [] && ! in_array($actual, $types, true) && ! ($value === [] && array_intersect($types, ['array', 'object']))) {
            return ["$at is $actual, expected ".implode('|', $types)];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "$at is not one of the allowed values";
        }
        if (isset($schema['pattern']) && is_string($value) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/', $value) !== 1) {
            $errors[] = "$at does not match {$schema['pattern']}";
        }
        if (is_array($value) && ! array_is_list($value)) {
            foreach ($schema['required'] ?? [] as $key) {
                array_key_exists($key, $value) || $errors[] = "$at.$key is missing";
            }
            foreach ($schema['properties'] ?? [] as $key => $sub) {
                array_key_exists($key, $value) && $errors = [...$errors, ...self::errors($value[$key], $sub, "$at.$key")];
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) as $key) {
                    $errors[] = "$at.$key is not documented";
                }
            }
        }
        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $errors = [...$errors, ...self::errors($item, $schema['items'], "$at[$i]")];
            }
        }

        return $errors;
    }
}
