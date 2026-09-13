<?php

declare(strict_types=1);

namespace Supabase\Auth;

use Psr\SimpleCache\CacheInterface;
use Supabase\Exception\AuthException;

/**
 * Fetches and caches the project's JSON Web Key Set
 * (GET /auth/v1/.well-known/jwks.json). Keys are memoised per process for
 * $ttl seconds and, when a PSR-16 cache is given, shared across processes.
 */
final class Jwks
{
    public const PATH = '/.well-known/jwks.json';

    /** @var array<string, array{expires: int, keys: list<array<mixed>>}> project url => entry */
    private static array $processCache = [];

    public function __construct(
        private readonly AuthHttp $http,
        private readonly string $projectUrl,
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = 600,
    ) {
    }

    /**
     * Returns the key matching $kid, refetching the set once when it is
     * unknown so a freshly rotated key is picked up.
     *
     * @return array<mixed>|null
     */
    public function find(string $kid): ?array
    {
        return self::select($this->keys(), $kid) ?? self::select($this->keys(forceRefresh: true), $kid);
    }

    /**
     * @return list<array<mixed>>
     */
    public function keys(bool $forceRefresh = false): array
    {
        $now = time();
        $slot = self::$processCache[$this->projectUrl] ?? null;
        if (! $forceRefresh && $slot !== null && $slot['expires'] > $now) {
            return $slot['keys'];
        }

        if (! $forceRefresh && $this->cache !== null) {
            $cached = self::keyList($this->cache->get($this->cacheKey()));
            if ($cached !== null) {
                self::$processCache[$this->projectUrl] = ['expires' => $now + $this->ttl, 'keys' => $cached];

                return $cached;
            }
        }

        $keys = $this->fetch();
        self::$processCache[$this->projectUrl] = ['expires' => $now + $this->ttl, 'keys' => $keys];
        $this->cache?->set($this->cacheKey(), $keys, $this->ttl);

        return $keys;
    }

    /**
     * Drops the per-process memo. Useful in tests and when a long-running
     * worker must pick up a rotated key before the TTL elapses.
     */
    public static function clearProcessCache(): void
    {
        self::$processCache = [];
    }

    /**
     * @return list<array<mixed>>
     */
    private function fetch(): array
    {
        $data = $this->http->request('GET', self::PATH);
        $keys = self::keyList($data['keys'] ?? null);
        if ($keys === null) {
            throw new AuthException('Invalid JWKS response: missing "keys" list.');
        }

        return $keys;
    }

    /**
     * @return list<array<mixed>>|null
     */
    private static function keyList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $key) {
            if (! is_array($key)) {
                return null;
            }
            $list[] = $key;
        }

        return $list;
    }

    /**
     * @param list<array<mixed>> $keys
     * @return array<mixed>|null
     */
    private static function select(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }

        return null;
    }

    private function cacheKey(): string
    {
        return 'supabase_jwks_' . sha1($this->projectUrl);
    }
}
