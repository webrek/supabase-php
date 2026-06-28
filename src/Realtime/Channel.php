<?php

declare(strict_types=1);

namespace Supabase\Realtime;

/**
 * A single Realtime topic ("realtime:<name>"). Builds the phx_join config from
 * registered postgres_changes bindings and dispatches incoming server messages
 * to the matching callbacks. Outgoing frames go through the pusher closure
 * supplied by RealtimeClient (which owns ref / join_ref allocation).
 */
final class Channel
{
    private const STATE_CLOSED = 'closed';
    private const STATE_JOINING = 'joining';
    private const STATE_JOINED = 'joined';
    private const STATE_ERRORED = 'errored';

    private string $state = self::STATE_CLOSED;

    /** @var list<array{event: string, schema: string, table: string, filter: ?string, callback: callable}> */
    private array $postgresBindings = [];

    /** @var list<array{event: string, callback: callable}> */
    private array $broadcastBindings = [];

    /** @var array<int, int> binding index => server-assigned id */
    private array $postgresIds = [];

    /** @var (callable(string): void)|null */
    private $onStatus = null;

    /**
     * @param \Closure(string, array<mixed>, bool): void $pusher fn(event, payload, isJoin)
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly string $topic,
        private readonly \Closure $pusher,
        private readonly array $params = [],
    ) {
    }

    public function onPostgresChanges(string $event, string $schema, string $table, ?string $filter, callable $callback): self
    {
        $this->postgresBindings[] = [
            'event' => $event,
            'schema' => $schema,
            'table' => $table,
            'filter' => $filter,
            'callback' => $callback,
        ];

        return $this;
    }

    public function onBroadcast(string $event, callable $callback): self
    {
        $this->broadcastBindings[] = ['event' => $event, 'callback' => $callback];

        return $this;
    }

    /**
     * @param array<mixed> $payload
     */
    public function send(string $event, array $payload): void
    {
        ($this->pusher)('broadcast', [
            'type' => 'broadcast',
            'event' => $event,
            'payload' => $payload,
        ], false);
    }

    public function subscribe(?callable $onStatus = null): self
    {
        $this->onStatus = $onStatus;
        $this->state = self::STATE_JOINING;
        ($this->pusher)('phx_join', $this->joinConfig(), true);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    private function joinConfig(): array
    {
        $pg = [];
        foreach ($this->postgresBindings as $b) {
            $entry = ['event' => $b['event'], 'schema' => $b['schema'], 'table' => $b['table']];
            if ($b['filter'] !== null) {
                $entry['filter'] = $b['filter'];
            }
            $pg[] = $entry;
        }

        $payload = [
            'config' => [
                'broadcast' => ['ack' => false, 'self' => false],
                'presence' => ['key' => '', 'enabled' => false],
                'postgres_changes' => $pg,
                'private' => false,
            ],
        ];

        if (isset($this->params['access_token']) && is_string($this->params['access_token'])) {
            $payload['access_token'] = $this->params['access_token'];
        }

        return $payload;
    }

    /**
     * @param array<mixed> $payload
     */
    public function handleMessage(string $event, array $payload, ?string $ref): void
    {
        switch ($event) {
            case 'phx_reply':
                $this->handleReply($payload);
                break;
            case 'postgres_changes':
                $this->handlePostgresChanges($payload);
                break;
            case 'broadcast':
                $this->handleBroadcast($payload);
                break;
            case 'phx_error':
                $this->state = self::STATE_ERRORED;
                $this->notify('error');
                break;
            case 'phx_close':
                $this->state = self::STATE_CLOSED;
                $this->notify('closed');
                break;
                // presence_state / presence_diff / system: ignored (presence deferred to a later release)
        }
    }

    /**
     * @param array<mixed> $payload
     */
    private function handleReply(array $payload): void
    {
        $status = isset($payload['status']) && is_string($payload['status']) ? $payload['status'] : '';
        if ($status !== 'ok') {
            $this->state = self::STATE_ERRORED;
            $this->notify('error');

            return;
        }

        $this->state = self::STATE_JOINED;
        $response = $payload['response'] ?? null;
        if (is_array($response) && isset($response['postgres_changes']) && is_array($response['postgres_changes'])) {
            $this->mapPostgresIds($response['postgres_changes']);
        }
        $this->notify('subscribed');
    }

    /**
     * @param array<mixed> $serverBindings
     */
    private function mapPostgresIds(array $serverBindings): void
    {
        $i = 0;
        foreach ($serverBindings as $sb) {
            if (is_array($sb) && isset($sb['id']) && (is_int($sb['id']) || is_string($sb['id']))) {
                $this->postgresIds[$i] = (int) $sb['id'];
            }
            $i++;
        }
    }

    /**
     * @param array<mixed> $payload
     */
    private function handlePostgresChanges(array $payload): void
    {
        $rawIds = isset($payload['ids']) && is_array($payload['ids']) ? $payload['ids'] : [];
        $ids = [];
        foreach ($rawIds as $id) {
            if (is_int($id) || is_string($id)) {
                $ids[] = (int) $id;
            }
        }

        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        foreach ($this->postgresBindings as $index => $binding) {
            $bindingId = $this->postgresIds[$index] ?? null;
            if ($bindingId !== null && in_array($bindingId, $ids, true)) {
                ($binding['callback'])($data);
            }
        }
    }

    /**
     * @param array<mixed> $payload
     */
    private function handleBroadcast(array $payload): void
    {
        $event = isset($payload['event']) && is_string($payload['event']) ? $payload['event'] : '';
        foreach ($this->broadcastBindings as $binding) {
            if ($binding['event'] === '*' || $binding['event'] === $event) {
                ($binding['callback'])($payload);
            }
        }
    }

    private function notify(string $status): void
    {
        if ($this->onStatus !== null) {
            ($this->onStatus)($status);
        }
    }

    public function state(): string
    {
        return $this->state;
    }
}
