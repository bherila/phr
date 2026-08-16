<?php

namespace App\Services\Mcp;

use Mcp\Capability\Discovery\SchemaValidator;

/** Restores JSON's empty-object shape before the SDK validates tool arguments. */
final class AgentMcpSchemaValidator extends SchemaValidator
{
    /**
     * @param  array<string, mixed>|object  $schema
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
    {
        $restored = is_array($schema) ? $this->restoreEmptyObjects($data, $schema) : $data;

        return parent::validateAgainstJsonSchema($restored, $schema);
    }

    /** @param array<string, mixed> $schema */
    private function restoreEmptyObjects(mixed $data, array $schema): mixed
    {
        // The SDK decodes JSON associatively, so nested {} and [] both arrive
        // as []. The schema is the only reliable discriminator at this point.
        if ($data === [] && $this->acceptsObject($schema)) {
            return (object) [];
        }
        if (! is_array($data)) {
            return $data;
        }
        if (array_is_list($data)) {
            $items = $schema['items'] ?? null;
            if (! is_array($items)) {
                return $data;
            }

            return array_map(fn (mixed $item): mixed => $this->restoreEmptyObjects($item, $items), $data);
        }

        $properties = $schema['properties'] ?? null;
        if (! is_array($properties)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            $propertySchema = $properties[$key] ?? null;
            if (is_array($propertySchema)) {
                $data[$key] = $this->restoreEmptyObjects($value, $propertySchema);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $schema */
    private function acceptsObject(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if ($type === 'object' || (is_array($type) && in_array('object', $type, true))) {
            return true;
        }
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $alternatives = $schema[$keyword] ?? null;
            if (is_array($alternatives)) {
                foreach ($alternatives as $alternative) {
                    if (is_array($alternative) && $this->acceptsObject($alternative)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
