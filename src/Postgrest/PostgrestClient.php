<?php

declare(strict_types=1);

namespace Supabase\Postgrest;

use Supabase\Http\Transport;

final class PostgrestClient
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $schema = 'public',
    ) {
    }

    public function from(string $table): QueryBuilder
    {
        return new QueryBuilder($this->transport, $table, $this->schema);
    }

    /**
     * @param array<mixed> $params
     */
    public function rpc(string $function, array $params = []): FilterBuilder
    {
        return new FilterBuilder(
            $this->transport,
            '/rest/v1/rpc/' . rawurlencode($function),
            'POST',
            $this->schema,
            $params,
        );
    }
}
