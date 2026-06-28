<?php

declare(strict_types=1);

namespace Supabase\Exception;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Supabase\Http\ResponseBody;
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

    /**
     * Returns the HTTP response body captured when this exception was created.
     *
     * Subclasses may redact parts of the body (e.g. AuthException scrubs token
     * fields), so this is not always the verbatim server response.
     *
     * WARNING: The body may still contain sensitive data (e.g. credentials or
     * personally identifiable information returned by Auth or Storage responses).
     * Do NOT log or expose this value verbatim in error tracking systems or
     * server-side logs without first scrubbing its contents.
     */
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
        $body = ResponseBody::read($response->getBody());

        $message = $response->getReasonPhrase() !== ''
            ? $response->getReasonPhrase()
            : 'HTTP error ' . $status;
        $code = null;

        $decoded = null;
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (is_array($decoded)) {
            foreach (['message', 'msg', 'error_description', 'error'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    $message = $decoded[$key];
                    break;
                }
            }
            foreach (['code', 'error_code'] as $key) {
                if (isset($decoded[$key]) && (is_string($decoded[$key]) || is_int($decoded[$key]) || is_float($decoded[$key]))) {
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
        $redactedBody = $responseBody !== null ? static::redactResponseBody($responseBody) : null;
        return new $class($message, $statusCode, $errorCode, $redactedBody);
    }

    /** @return list<string> */
    protected static function extraRedactionKeys(): array
    {
        return [];
    }

    protected static function redactResponseBody(string $body): string
    {
        $keys = array_merge(
            ['apikey', 'access_token', 'refresh_token', 'token', 'password', 'secret'],
            static::extraRedactionKeys(),
        );

        $pattern = '/"(' . implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), $keys)) . ')"\s*:\s*"[^"]*"/i';

        return preg_replace($pattern, '"$1":"***redacted***"', $body) ?? $body;
    }
}
