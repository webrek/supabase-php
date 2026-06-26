<?php

declare(strict_types=1);

namespace Supabase\Exception;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class SupabaseException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /**
     * @return static
     */
    public static function fromResponse(ResponseInterface $response): static
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        $message = $response->getReasonPhrase() !== ''
            ? $response->getReasonPhrase()
            : 'HTTP error ' . $status;
        $code = null;

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach (['message', 'msg', 'error_description', 'error'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    $message = $decoded[$key];
                    break;
                }
            }
            foreach (['code', 'error_code'] as $key) {
                if (isset($decoded[$key]) && (is_string($decoded[$key]) || is_int($decoded[$key]))) {
                    $code = (string) $decoded[$key];
                    break;
                }
            }
        }

        return static::create(static::class, $message, $status, $code, $body);
    }

    /**
     * @template T of SupabaseException
     * @param class-string<T> $class
     * @return T
     */
    protected static function create(string $class, string $message, ?int $statusCode, ?string $errorCode, ?string $responseBody): SupabaseException
    {
        return new $class($message, $statusCode, $errorCode, $responseBody);
    }
}
