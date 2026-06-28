<?php

declare(strict_types=1);

namespace Supabase\Realtime;

use Supabase\Exception\RealtimeException;

/**
 * Phoenix channels serializer (vsn 1.0.0): messages are JSON arrays shaped
 * [join_ref, ref, topic, event, payload].
 */
final class Serializer
{
    /**
     * @param array<mixed> $payload
     */
    public function encode(?string $joinRef, string $ref, string $topic, string $event, array $payload): string
    {
        try {
            return json_encode(
                [$joinRef, $ref, $topic, $event, $payload === [] ? new \stdClass() : $payload],
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            throw new RealtimeException('Failed to encode Realtime message: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array{joinRef: ?string, ref: ?string, topic: string, event: string, payload: array<mixed>}
     */
    public function decode(string $raw): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RealtimeException('Failed to decode Realtime message: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) !== 5) {
            throw new RealtimeException('Unexpected Realtime message shape.');
        }

        [$joinRef, $ref, $topic, $event, $payload] = $decoded;

        return [
            'joinRef' => is_string($joinRef) ? $joinRef : null,
            'ref' => is_string($ref) ? $ref : null,
            'topic' => is_string($topic) ? $topic : '',
            'event' => is_string($event) ? $event : '',
            'payload' => is_array($payload) ? $payload : [],
        ];
    }
}
