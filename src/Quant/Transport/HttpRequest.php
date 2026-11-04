<?php

declare(strict_types=1);

namespace Quant\Transport;

/**
 * A request an exchange wants sent. The engine builds these and parses what comes back; it never
 * opens a socket itself — whoever hosts the engine (pig, a myphp app) sends them with its own
 * HTTP client and hands the body back to the exchange.
 */
final readonly class HttpRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
    ) {
    }
}
