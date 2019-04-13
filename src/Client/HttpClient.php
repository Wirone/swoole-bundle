<?php

declare(strict_types=1);

namespace K911\Swoole\Client;

use K911\Swoole\Client\Exception\ClientConnectionErrorException;
use K911\Swoole\Client\Exception\InvalidContentTypeException;
use K911\Swoole\Client\Exception\InvalidHttpMethodException;
use K911\Swoole\Client\Exception\MissingContentTypeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

/**
 * Mainly used for server tests.
 *
 * @internal Class API is not stable, nor it is guaranteed to exists in next releases, use at own risk
 */
final class HttpClient
{
    private const SUPPORTED_HTTP_METHODS = [
        Http::METHOD_GET,
        Http::METHOD_HEAD,
        Http::METHOD_POST,
        Http::METHOD_DELETE,
        Http::METHOD_PATCH,
        Http::METHOD_TRACE,
        Http::METHOD_OPTIONS,
    ];

    private const ACCEPTABLE_CONNECTING_EXIT_CODES = [
        111 => true,
        61 => true,
        60 => true,
    ];

    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param int   $timeout seconds
     * @param float $step    microseconds
     *
     * @return bool Success
     */
    public function connect(int $timeout = 3, float $step = 0.1): bool
    {
        $start = \microtime(true);
        $max = $start + $timeout;

        do {
            try {
                $this->send('/', Http::METHOD_HEAD);

                return true;
            } catch (\RuntimeException $ex) {
                if (!isset(self::ACCEPTABLE_CONNECTING_EXIT_CODES[$ex->getCode()])) {
                    throw $ex;
                }
            }
            Coroutine::sleep($step);
            $now = \microtime(true);
        } while ($now < $max);

        return false;
    }

    public function send(string $path, string $method = Http::METHOD_GET, array $headers = [], $data = null, int $timeout = 3): array
    {
        $this->validHttpMethod($method);

        $this->client->setMethod($method);
        $this->client->setHeaders($headers);
        $this->client->execute($path);

        if (null !== $data) {
            if (\is_string($data)) {
                $this->client->setData($data);
            } else {
                $this->serializeRequestData($this->client, $data);
            }
        }

        return $this->resolveResponse($this->client, $timeout);
    }

    public static function fromDomain(string $host, int $port = 443, bool $ssl = true, array $options = []): self
    {
        $client = new Client(
            $host, $port, $ssl
        );

        if (!empty($options)) {
            $client->set($options);
        }

        return new self($client);
    }

    public function __destruct()
    {
        if ($this->client->connected) {
            $this->client->close();
        }
    }

    private function serializeRequestData(Client $client, $data): void
    {
        $options = \defined('JSON_THROW_ON_ERROR') ? \JSON_THROW_ON_ERROR : 0;
        $json = \json_encode($data, $options);

        // TODO: Drop on PHP 7.3 Migration
        if (!\defined('JSON_THROW_ON_ERROR') && false === $json) {
            throw new \RuntimeException(\json_last_error_msg(), \json_last_error());
        }

        $client->headers[Http::HEADER_CONTENT_TYPE] = Http::CONTENT_TYPE_APPLICATION_JSON;
        $client->setData($json);
    }

    /**
     * @param Client $client
     *
     * @return string|array
     */
    private function resolveResponseBody(Client $client)
    {
        if (204 === $client->statusCode || '' === $client->body) {
            return [];
        }

        $this->hasContentType($client);
        $fullContentType = $client->headers[Http::HEADER_CONTENT_TYPE];
        $contentType = \explode(';', $fullContentType)[0];

        switch ($contentType) {
            case Http::CONTENT_TYPE_APPLICATION_JSON:
                // TODO: Drop on PHP 7.3 Migration
                if (!\defined('JSON_THROW_ON_ERROR')) {
                    $data = \json_decode($client->body, true);
                    if (null === $data) {
                        throw new \RuntimeException(\json_last_error_msg(), \json_last_error());
                    }

                    return $data;
                }

                return \json_decode($client->body, true, 512, JSON_THROW_ON_ERROR);
            case Http::CONTENT_TYPE_TEXT_PLAIN:
                return $client->body;
            default:
                throw InvalidContentTypeException::forContentType($contentType);
        }
    }

    private function resolveResponse(Client $client, int $timeout): array
    {
        $client->recv($timeout);

        $this->validStatusCode($client);

        return [
            'request' => [
                'method' => $client->requestMethod,
                'headers' => $client->requestHeaders,
                'body' => $client->requestBody,
                'cookies' => $client->set_cookie_headers,
                'uploadFiles' => $client->uploadFiles,
            ],
            'response' => [
                'cookies' => $client->cookies,
                'headers' => $client->headers,
                'statusCode' => $client->statusCode,
                'body' => $this->resolveResponseBody($client),
                'downloadFile' => $client->downloadFile,
                'downloadOffset' => $client->downloadOffset,
            ],
        ];
    }

    private function validStatusCode(Client $client): void
    {
        if ($client->statusCode >= 0) {
            return;
        }

        switch ($client->statusCode) {
            case -1:
                throw ClientConnectionErrorException::failed($client->errCode);
            case -2:
                throw ClientConnectionErrorException::requestTimeout($client->errCode);
            case -3:
                throw ClientConnectionErrorException::serverReset($client->errCode);
            default:
                throw ClientConnectionErrorException::unknown($client->errCode);
        }
    }

    private function validHttpMethod(string $method): void
    {
        if (true === \in_array($method, self::SUPPORTED_HTTP_METHODS)) {
            return;
        }

        throw InvalidHttpMethodException::forMethod($method, self::SUPPORTED_HTTP_METHODS);
    }

    private function hasContentType(Client $client): void
    {
        if (true === \array_key_exists(Http::HEADER_CONTENT_TYPE, $client->headers)) {
            return;
        }

        throw MissingContentTypeException::create();
    }
}