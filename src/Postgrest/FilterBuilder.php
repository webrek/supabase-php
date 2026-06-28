<?php

declare(strict_types=1);

namespace Supabase\Postgrest;

use Psr\Http\Message\ResponseInterface;
use Supabase\Exception\PostgrestException;
use Supabase\Http\ResponseBody;
use Supabase\Http\Transport;

class FilterBuilder
{
    /** @var list<array{0:string,1:string}> */
    private array $params = [];

    /** @var array<string,string> */
    private array $headers = [];

    private bool $maybeSingle = false;

    /**
     * @param array<mixed>|null $body
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $resourcePath,
        private readonly string $method,
        private readonly string $schema,
        private readonly ?array $body = null,
        private readonly bool $defaultReturnMinimal = true,
    ) {
        if ($this->schema !== '' && $this->schema !== 'public') {
            $header = in_array($this->method, ['GET', 'HEAD'], true) ? 'Accept-Profile' : 'Content-Profile';
            $this->headers[$header] = $this->schema;
        }

        // Table mutations default to `return=minimal`; RPC must NOT (PostgREST would
        // return 204/empty and the function result would be lost).
        if ($this->defaultReturnMinimal && ! in_array($this->method, ['GET', 'HEAD'], true)) {
            $this->mergePrefer('return=minimal');
        }
    }

    public function select(string $columns = '*'): static
    {
        $this->addParam('select', $columns);
        if (! in_array($this->method, ['GET', 'HEAD'], true)) {
            $this->setReturnPreference('representation');
        }

        return $this;
    }

    private function setReturnPreference(string $value): void
    {
        $prefs = array_filter(
            explode(',', $this->headers['Prefer'] ?? ''),
            static fn (string $p) => $p !== '' && ! str_starts_with($p, 'return='),
        );
        $prefs[] = 'return=' . $value;
        $this->headers['Prefer'] = implode(',', $prefs);
    }

    /** @internal */
    public function upsertPrefer(?string $onConflict): void
    {
        $this->mergePrefer('resolution=merge-duplicates');
        if ($onConflict !== null) {
            $this->addParam('on_conflict', $onConflict);
        }
    }

    public function eq(string $column, mixed $value): static
    {
        $this->addParam($column, 'eq.' . $this->stringify($value));

        return $this;
    }

    public function neq(string $column, mixed $value): static
    {
        $this->addParam($column, 'neq.' . $this->stringify($value));

        return $this;
    }

    public function gt(string $column, mixed $value): static
    {
        $this->addParam($column, 'gt.' . $this->stringify($value));

        return $this;
    }

    public function gte(string $column, mixed $value): static
    {
        $this->addParam($column, 'gte.' . $this->stringify($value));

        return $this;
    }

    public function lt(string $column, mixed $value): static
    {
        $this->addParam($column, 'lt.' . $this->stringify($value));

        return $this;
    }

    public function lte(string $column, mixed $value): static
    {
        $this->addParam($column, 'lte.' . $this->stringify($value));

        return $this;
    }

    public function like(string $column, string $pattern): static
    {
        $this->addParam($column, 'like.' . $pattern);

        return $this;
    }

    public function ilike(string $column, string $pattern): static
    {
        $this->addParam($column, 'ilike.' . $pattern);

        return $this;
    }

    public function is(string $column, null|bool $value): static
    {
        $this->addParam($column, 'is.' . ($value === null ? 'null' : ($value ? 'true' : 'false')));

        return $this;
    }

    /**
     * @param array<int,mixed> $values
     */
    public function in(string $column, array $values): static
    {
        $this->addParam($column, 'in.(' . $this->formatList($values) . ')');

        return $this;
    }

    public function not(string $column, string $operator, mixed $value): static
    {
        $this->addParam($column, 'not.' . $operator . '.' . $this->stringify($value));

        return $this;
    }

    public function or(string $filters, ?string $referencedTable = null): static
    {
        $key = $referencedTable !== null ? $referencedTable . '.or' : 'or';
        $this->addParam($key, '(' . $filters . ')');

        return $this;
    }

    /**
     * @param array<string,mixed> $query
     */
    public function match(array $query): static
    {
        foreach ($query as $col => $val) {
            $this->eq($col, $val);
        }

        return $this;
    }

    public function filter(string $column, string $operator, mixed $value): static
    {
        $this->addParam($column, $operator . '.' . $this->stringify($value));

        return $this;
    }

    /**
     * @param array<int,mixed>|string $value
     */
    public function contains(string $column, array|string $value): static
    {
        $this->addParam($column, 'cs.' . $this->formatArrayOrString($value));

        return $this;
    }

    /**
     * @param array<int,mixed>|string $value
     */
    public function containedBy(string $column, array|string $value): static
    {
        $this->addParam($column, 'cd.' . $this->formatArrayOrString($value));

        return $this;
    }

    public function rangeGt(string $column, string $range): static
    {
        $this->addParam($column, 'sr.' . $range);

        return $this;
    }

    public function rangeGte(string $column, string $range): static
    {
        $this->addParam($column, 'nxl.' . $range);

        return $this;
    }

    public function rangeLt(string $column, string $range): static
    {
        $this->addParam($column, 'sl.' . $range);

        return $this;
    }

    public function rangeLte(string $column, string $range): static
    {
        $this->addParam($column, 'nxr.' . $range);

        return $this;
    }

    public function rangeAdjacent(string $column, string $range): static
    {
        $this->addParam($column, 'adj.' . $range);

        return $this;
    }

    /**
     * @param array<int,mixed>|string $value
     */
    public function overlaps(string $column, array|string $value): static
    {
        $this->addParam($column, 'ov.' . $this->formatArrayOrString($value));

        return $this;
    }

    public function textSearch(string $column, string $query, ?string $config = null, string $type = 'fts'): static
    {
        $cfg = $config !== null ? '(' . $config . ')' : '';
        $this->addParam($column, $type . $cfg . '.' . $query);

        return $this;
    }

    public function order(string $column, bool $ascending = true, bool $nullsFirst = false): static
    {
        $this->addParam('order', $column . '.' . ($ascending ? 'asc' : 'desc') . '.' . ($nullsFirst ? 'nullsfirst' : 'nullslast'));
        return $this;
    }

    public function limit(int $count): static
    {
        $this->addParam('limit', (string) $count);
        return $this;
    }

    public function range(int $from, int $to): static
    {
        $this->addParam('offset', (string) $from);
        $this->addParam('limit', (string) ($to - $from + 1));
        return $this;
    }

    public function single(): static
    {
        $this->headers['Accept'] = 'application/vnd.pgrst.object+json';
        return $this;
    }

    public function maybeSingle(): static
    {
        $this->markMaybeSingle();
        return $this;
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('Filter value must be scalar or null.');
    }

    /**
     * @param array<int,mixed> $values
     */
    private function formatList(array $values): string
    {
        $parts = [];
        foreach ($values as $v) {
            if (is_string($v)) {
                $parts[] = '"' . str_replace('"', '\\"', $v) . '"';
            } else {
                $parts[] = $this->stringify($v);
            }
        }

        return implode(',', $parts);
    }

    /**
     * @param array<int,mixed>|string $value
     */
    private function formatArrayOrString(array|string $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return '{' . $this->formatList($value) . '}';
    }

    /**
     * @return array<mixed>|null
     */
    public function execute(): array|null
    {
        $response = $this->send($this->method);

        return $this->decode($response);
    }

    public function count(string $type = 'exact'): int
    {
        $this->mergePrefer('count=' . $type);
        $response = $this->send('HEAD');
        if ($response->getStatusCode() >= 400) {
            throw PostgrestException::fromResponse($response);
        }

        $range = $response->getHeaderLine('Content-Range');
        $slash = strrpos($range, '/');
        if ($slash === false) {
            return 0;
        }

        $total = substr($range, $slash + 1);

        return is_numeric($total) ? (int) $total : 0;
    }

    protected function addParam(string $key, string $value): void
    {
        $this->params[] = [$key, $value];
    }

    protected function mergePrefer(string $value): void
    {
        $existing = $this->headers['Prefer'] ?? '';
        $this->headers['Prefer'] = $existing === '' ? $value : $existing . ',' . $value;
    }

    protected function markMaybeSingle(): void
    {
        $this->maybeSingle = true;
    }

    /**
     * @return array{headers: array<string,string>, query: list<array{0:string,1:string}>, body?: array<mixed>}
     */
    protected function buildOptions(): array
    {
        $options = [
            'headers' => $this->headers,
            'query' => $this->params,
        ];
        if ($this->body !== null) {
            $options['body'] = $this->body;
        }

        return $options;
    }

    protected function send(string $method): ResponseInterface
    {
        return $this->transport->request($method, $this->resourcePath, $this->buildOptions());
    }

    /**
     * @return array<mixed>|null
     */
    protected function decode(ResponseInterface $response): array|null
    {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw PostgrestException::fromResponse($response);
        }

        $body = ResponseBody::read($response->getBody());
        if ($body === '') {
            return null;
        }

        /** @var array<mixed>|null $decoded */
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return null;
        }

        if ($this->maybeSingle) {
            /** @var list<array<string,mixed>> $decoded */
            return $decoded[0] ?? null;
        }

        return $decoded;
    }
}
