<?php

namespace App\Support\AgentApi;

use JsonException;

/** JSON decoding that keeps empty objects distinct from empty lists. */
final class AgentApiJson
{
    /** @return array<string, mixed>|null */
    public static function decodeObject(string $json): ?array
    {
        $decoded = self::decodeRaw($json);

        return is_object($decoded) ? self::objectProperties($decoded) : null;
    }

    public static function decodeValue(string $json): mixed
    {
        return self::preserveShape(self::decodeRaw($json));
    }

    public static function decodeRaw(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public static function objectProperties(object $value): array
    {
        return array_map(self::preserveShape(...), get_object_vars($value));
    }

    public static function preserveShape(mixed $value): mixed
    {
        if (is_object($value)) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }
            if (array_filter(array_keys($properties), is_int(...)) !== []) {
                $object = new \stdClass;
                foreach ($properties as $key => $property) {
                    $object->{(string) $key} = self::preserveShape($property);
                }

                return $object;
            }

            return self::objectProperties($value);
        }

        return is_array($value) ? array_map(self::preserveShape(...), $value) : $value;
    }
}
