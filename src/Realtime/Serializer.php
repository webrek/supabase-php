<?php

declare(strict_types=1);

namespace Supabase\Realtime;

use Supabase\Exception\RealtimeException;

/**
 * Phoenix channels serializer (vsn 1.0.0): messages are JSON objects shaped
 * {"topic":..,"event":..,"payload":..,"ref":..,"join_ref":..}. join_ref is
 * omitted when null (e.g. heartbeats and server-initiated messages).
 */
final class Serializer
{
    /**
     * @param array<mixed> $payload
     */
    public function encode(?string $joinRef, string $ref, string $topic, string $event, array $payload): string
    {
        $message = [
            'topic' => $topic,
            'event' => $event,
            'payload' => $payload === [] ? new \stdClass() : $payload,
            'ref' => $ref,
        ];
        if ($joinRef !== null) {
            $message['join_ref'] = $joinRef;
        }

        try {
            return json_encode($message, JSON_THROW_ON_ERROR);
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

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RealtimeException('Unexpected Realtime message shape.');
        }

        $topic = $decoded['topic'] ?? null;
        $event = $decoded['event'] ?? null;
        $payload = $decoded['payload'] ?? null;
        $ref = $decoded['ref'] ?? null;
        $joinRef = $decoded['join_ref'] ?? null;

        return [
            'joinRef' => is_string($joinRef) ? $joinRef : null,
            'ref' => is_string($ref) ? $ref : null,
            'topic' => is_string($topic) ? $topic : '',
            'event' => is_string($event) ? $event : '',
            'payload' => is_array($payload) ? $payload : [],
        ];
    }
}
