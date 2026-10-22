<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The mapping contracts this installation runs, from config `phr.ehi_mappings`
 * (class names resolved through the container, in configured order).
 *
 * Refuses, at first use, a configuration it cannot run deterministically: two
 * mappings with one name, a name or version the proposal columns cannot hold, or
 * two mappings expecting different primary keys for the same source table.
 * Entities are shared per (source, table, key), so one table has one key.
 */
final class EhiMappingRegistry
{
    private const int MAX_NAME_BYTES = 64;

    private const int MAX_VERSION_BYTES = 32;

    /** @var array<string, EhiMapping>|null */
    private ?array $mappings = null;

    public function __construct(private readonly Container $container) {}

    /** @return array<string, EhiMapping> keyed by name, in configured order */
    public function all(): array
    {
        if ($this->mappings !== null) {
            return $this->mappings;
        }

        $configured = config('phr.ehi_mappings', []);
        if (! is_array($configured)) {
            throw new LogicException('EHI mapping registry: phr.ehi_mappings must be a list of class names.');
        }

        $mappings = [];
        $keys = [];
        foreach ($configured as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, EhiMapping::class)) {
                throw new LogicException('EHI mapping registry: every entry must name a class implementing EhiMapping.');
            }
            $mapping = $this->container->make($class);
            assert($mapping instanceof EhiMapping);

            $name = $mapping->name();
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || strlen($name) > self::MAX_NAME_BYTES) {
                throw new LogicException(sprintf('EHI mapping registry: a mapping name must be 1-%d lower-case identifier bytes.', self::MAX_NAME_BYTES));
            }
            if (isset($mappings[$name])) {
                throw new LogicException("EHI mapping registry: two mappings are named {$name}.");
            }
            $version = $mapping->version();
            if ($version === '' || strlen($version) > self::MAX_VERSION_BYTES) {
                throw new LogicException(sprintf('EHI mapping registry: mapping %s needs a 1-%d byte version.', $name, self::MAX_VERSION_BYTES));
            }
            foreach ($mapping->expectedKeys() as $table => $key) {
                if ($key === []) {
                    throw new LogicException("EHI mapping registry: mapping {$name} declares an empty key.");
                }
                if (isset($keys[$table]) && $keys[$table] !== $key) {
                    throw new LogicException("EHI mapping registry: mappings disagree on the primary key of one source table ({$name}).");
                }
                $keys[$table] = $key;
            }
            $mappings[$name] = $mapping;
        }

        return $this->mappings = $mappings;
    }

    public function get(string $name): ?EhiMapping
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Every source table any mapping projects, with its expected key, sorted by table name.
     *
     * @return array<string, list<string>>
     */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->all() as $mapping) {
            foreach ($mapping->expectedKeys() as $table => $key) {
                $tables[$table] = $key;
            }
        }
        ksort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * Digest of every mapping's name, version, and expected keys. A projection
     * made under another fingerprint is re-run before a preview relies on it.
     */
    public function fingerprint(): string
    {
        $canonical = [];
        foreach ($this->all() as $name => $mapping) {
            $canonical[$name] = ['version' => $mapping->version(), 'keys' => $mapping->expectedKeys()];
        }

        return hash('sha256', EhiCanonicalJson::encode($canonical));
    }
}
