<?php

declare(strict_types=1);

namespace Supabase\Functions;

use Supabase\Exception\FunctionsException;
use Supabase\Http\Transport;

final class FunctionsClient
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param array{body?: array<mixed>|string, headers?: array<string,string>, method?: string} $options
     */
    public function invoke(string $name, array $options = []): mixed
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Function name must not be empty or whitespace-only.');
        }

        $requestOptions = [
            'headers' => $options['headers'] ?? [],
        ];
        if (array_key_exists('body', $options)) {
            $requestOptions['body'] = $options['body'];
        }

        $response = $this->transport->request(
            $options['method'] ?? 'POST',
            '/functions/v1/' . rawurlencode($name),
            $requestOptions,
        );

        if ($response->getStatusCode() >= 400) {
            throw FunctionsException::fromResponse($response);
        }

        $body = (string) $response->getBody();
        if (str_contains($response->getHeaderLine('Content-Type'), 'application/json')) {
            if ($body === '') {
                return null;
            }

            try {
                return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new FunctionsException(
                    'Invalid JSON in response body: ' . $e->getMessage(),
                    $response->getStatusCode(),
                    null,
                    $body,
                    $e,
                );
            }
        }

        return $body;
    }
}
