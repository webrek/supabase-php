<?php

declare(strict_types=1);

namespace Supabase\Postgrest;

use Supabase\Http\Transport;

final class QueryBuilder
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $table,
        private readonly string $schema,
    ) {
    }

    public function select(string $columns = '*'): FilterBuilder
    {
        return $this->builder('GET')->select($columns);
    }

    /**
     * @param array<mixed>|null $body
     */
    private function builder(string $method, ?array $body = null): FilterBuilder
    {
        return new FilterBuilder(
            $this->transport,
            '/rest/v1/' . rawurlencode($this->table),
            $method,
            $this->schema,
            $body,
        );
    }
}
