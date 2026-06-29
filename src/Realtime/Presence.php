<?php

declare(strict_types=1);

namespace Supabase\Realtime;

/**
 * Phoenix presence state tracking (port of realtime-js RealtimePresence).
 *
 * State is a map of key => list of presences. Each presence is the user payload
 * plus a `presence_ref` string (the raw wire field is `phx_ref`; `phx_ref_prev`
 * is dropped, and the `metas` wrapper is flattened).
 *
 * @phpstan-type PresenceEntry array<string, mixed>
 * @phpstan-type PresenceList list<array<string, mixed>>
 */
final class Presence
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $state = [];

    /** @return array<string, list<array<string, mixed>>> */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * Apply a full presence_state snapshot.
     *
     * @param array<mixed> $rawState
     * @param (callable(string, list<array<string, mixed>>, list<array<string, mixed>>): void)|null $onJoin
     * @param (callable(string, list<array<string, mixed>>, list<array<string, mixed>>): void)|null $onLeave
     */
    public function syncState(array $rawState, ?callable $onJoin = null, ?callable $onLeave = null): void
    {
        $newState = self::transform($rawState);

        $joins = [];
        $leaves = [];

        foreach ($this->state as $key => $presences) {
            if (! array_key_exists($key, $newState)) {
                $leaves[$key] = $presences;
            }
        }

        foreach ($newState as $key => $newPresences) {
            if (array_key_exists($key, $this->state)) {
                $curRefs = self::refs($this->state[$key]);
                $newRefs = self::refs($newPresences);
                $joined = array_values(array_filter($newPresences, fn (array $p): bool => ! in_array(self::ref($p), $curRefs, true)));
                $left = array_values(array_filter($this->state[$key], fn (array $p): bool => ! in_array(self::ref($p), $newRefs, true)));
                if ($joined !== []) {
                    $joins[$key] = $joined;
                }
                if ($left !== []) {
                    $leaves[$key] = $left;
                }
            } else {
                $joins[$key] = $newPresences;
            }
        }

        $this->applyDiff($joins, $leaves, $onJoin, $onLeave);
    }

    /**
     * Apply an incremental presence_diff.
     *
     * @param array<mixed> $rawJoins
     * @param array<mixed> $rawLeaves
     * @param (callable(string, list<array<string, mixed>>, list<array<string, mixed>>): void)|null $onJoin
     * @param (callable(string, list<array<string, mixed>>, list<array<string, mixed>>): void)|null $onLeave
     */
    public function syncDiff(array $rawJoins, array $rawLeaves, ?callable $onJoin = null, ?callable $onLeave = null): void
    {
        $this->applyDiff(self::transform($rawJoins), self::transform($rawLeaves), $onJoin, $onLeave);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $joins
     * @param array<string, list<array<string, mixed>>> $leaves
     * @param (callable(string, list<array<string, mixed>>, list<array<string, mixed>>): void)|null $onJoin
     * @param (callable(string, list<array<string, mixed>>, list<array<string, mixed>>): void)|null $onLeave
     */
    private function applyDiff(array $joins, array $leaves, ?callable $onJoin, ?callable $onLeave): void
    {
        foreach ($joins as $key => $newPresences) {
            $current = $this->state[$key] ?? [];
            $newRefs = self::refs($newPresences);
            $survivors = array_values(array_filter($current, fn (array $p): bool => ! in_array(self::ref($p), $newRefs, true)));
            $this->state[$key] = array_merge($survivors, $newPresences);
            if ($onJoin !== null) {
                $onJoin($key, $current, $newPresences);
            }
        }

        foreach ($leaves as $key => $leftPresences) {
            if (! isset($this->state[$key])) {
                continue;
            }
            $removeRefs = self::refs($leftPresences);
            $remaining = array_values(array_filter($this->state[$key], fn (array $p): bool => ! in_array(self::ref($p), $removeRefs, true)));
            $this->state[$key] = $remaining;
            if ($onLeave !== null) {
                $onLeave($key, $remaining, $leftPresences);
            }
            if ($remaining === []) {
                unset($this->state[$key]);
            }
        }
    }

    /**
     * Transform raw {key:{metas:[{phx_ref,...}]}} into {key:[{presence_ref,...}]}.
     *
     * @param array<mixed> $raw
     * @return array<string, list<array<string, mixed>>>
     */
    private static function transform(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)) {
                continue;
            }
            $metas = isset($entry['metas']) && is_array($entry['metas']) ? $entry['metas'] : [];
            $list = [];
            foreach ($metas as $meta) {
                if (! is_array($meta)) {
                    continue;
                }
                $ref = $meta['phx_ref'] ?? null;
                unset($meta['phx_ref'], $meta['phx_ref_prev']);
                /** @var array<string, mixed> $presence */
                $presence = array_merge(['presence_ref' => is_string($ref) ? $ref : ''], $meta);
                $list[] = $presence;
            }
            $out[$key] = $list;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $presences
     * @return list<string>
     */
    private static function refs(array $presences): array
    {
        $refs = [];
        foreach ($presences as $p) {
            $refs[] = self::ref($p);
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $presence
     */
    private static function ref(array $presence): string
    {
        $ref = $presence['presence_ref'] ?? null;

        return is_string($ref) ? $ref : '';
    }
}
