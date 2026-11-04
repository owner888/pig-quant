<?php

declare(strict_types=1);

namespace PigQuant;

use Closure;
use Pig\Async\Loop;
use Pig\CodingAgent\Config as PigConfig;
use Pig\CodingAgent\Logger;

/**
 * The one process that runs the engine, and how the others find it.
 *
 * `pig web` is a server process plus a `pig --mode rpc` child per conversation, and the terminal
 * is a process of its own; each loads this extension. One engine, not one per process: whoever
 * starts it first listens on a loopback port and writes `~/.pig/agent/quant/runner.json`
 * (`{pid, port, token, startedAt}`); every other process reads that file and forwards its
 * `/quant`, `quant_*` and panel actions there (`Hub`). The file goes when the owner shuts down;
 * a file whose process is gone is ignored and replaced.
 *
 * The listener is the smallest HTTP/1.1 that serves one JSON request per connection, on pig's
 * event loop, and answers only requests carrying the token — the port is local, the token keeps
 * another local program from driving the wallet.
 */
final class Owner
{
    private const int MAX_REQUEST_BYTES = 65536;

    /** @var resource|null */
    private $server = null;

    private ?string $watcher = null;

    /** @var array<int, string> watcher id by client stream id */
    private array $clients = [];

    private int $port = 0;

    private string $token = '';

    /**
     * @param Closure(string, string, array<string, mixed>): mixed $handle `(method, path, body)` to a JSON-encodable
     *        result; a throw is an error answer with the message
     */
    public function __construct(private readonly Closure $handle)
    {
    }

    public static function path(): string
    {
        return PigConfig::home() . '/quant/runner.json';
    }

    public function isListening(): bool
    {
        return $this->server !== null;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function token(): string
    {
        return $this->token;
    }

    /** Listen on a free loopback port and claim the file. */
    public function listen(): void
    {
        if ($this->server !== null) {
            return;
        }

        set_error_handler(static fn () => true);

        try {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        } finally {
            restore_error_handler();
        }

        if ($server === false) {
            throw new \RuntimeException("Could not listen on a loopback port for the engine: {$errstr} ({$errno})");
        }

        stream_set_blocking($server, false);
        $name = stream_socket_get_name($server, false);
        $this->port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        $this->token = bin2hex(random_bytes(16));
        $this->server = $server;
        $this->watcher = Loop::get()->onReadable($server, function (): void {
            set_error_handler(static fn () => true);

            try {
                $client = stream_socket_accept($this->server, 0);
            } finally {
                restore_error_handler();
            }

            if ($client !== false) {
                stream_set_blocking($client, false);
                $this->serve($client);
            }
        });

        self::write(['pid' => getmypid(), 'port' => $this->port, 'token' => $this->token, 'startedAt' => time()]);
        Logger::info("[quant] engine owner: pid " . getmypid() . " on 127.0.0.1:{$this->port}");
    }

    /** Stop listening and let the file go, if it is still this process's. */
    public function release(): void
    {
        if ($this->watcher !== null) {
            Loop::get()->cancel($this->watcher);
            $this->watcher = null;
        }

        foreach ($this->clients as $watcher) {
            Loop::get()->cancel($watcher);
        }

        $this->clients = [];

        if ($this->server !== null) {
            fclose($this->server);
            $this->server = null;
        }

        $record = self::read();

        if ($record !== null && $record['pid'] === getmypid() && !unlink(self::path())) {
            throw new \RuntimeException('Could not remove ' . self::path());
        }
    }

    /**
     * The record of a live owner that is not this process, or null: no file, a file this process
     * wrote, or a file whose process is gone.
     *
     * @return array{pid: int, port: int, token: string, startedAt: int}|null
     */
    public static function other(): ?array
    {
        $record = self::read();

        if ($record === null || $record['pid'] === getmypid() || !self::alive($record['pid'])) {
            return null;
        }

        return $record;
    }

    /** @return array{pid: int, port: int, token: string, startedAt: int}|null */
    public static function read(): ?array
    {
        $path = self::path();

        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (!is_array($data) || !isset($data['pid'], $data['port'], $data['token'])) {
            return null;
        }

        return ['pid' => (int) $data['pid'], 'port' => (int) $data['port'], 'token' => (string) $data['token'], 'startedAt' => (int) ($data['startedAt'] ?? 0)];
    }

    private static function alive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0) || posix_get_last_error() === 1; // EPERM: alive, somebody else's
        }

        exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $code);

        return $code === 0;
    }

    /** @param array<string, mixed> $record */
    private static function write(array $record): void
    {
        $path = self::path();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }

        if (file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT) . "\n") === false) {
            throw new \RuntimeException("Could not write {$path}.");
        }

        chmod($path, 0o600);
    }

    /** @param resource $client */
    private function serve($client): void
    {
        $buffer = '';
        $id = (int) $client;
        $this->clients[$id] = Loop::get()->onReadable($client, function () use ($client, $id, &$buffer): void {
            $chunk = fread($client, 65536);

            if ($chunk === false || ($chunk === '' && feof($client))) {
                $this->finish($client, $id);

                return;
            }

            $buffer .= $chunk;

            if (strlen($buffer) > self::MAX_REQUEST_BYTES) {
                $this->answer($client, $id, 413, ['ok' => false, 'error' => 'Request too large.']);

                return;
            }

            $request = self::parse($buffer);

            if ($request === null) {
                return; // more to come
            }

            if ($request['token'] !== $this->token) {
                $this->answer($client, $id, 401, ['ok' => false, 'error' => 'Wrong token.']);

                return;
            }

            try {
                $result = ($this->handle)($request['method'], $request['path'], $request['body']);
                $this->answer($client, $id, 200, ['ok' => true, 'result' => $result]);
            } catch (\Throwable $error) {
                $this->answer($client, $id, 200, ['ok' => false, 'error' => $error->getMessage()]);
            }
        });
    }

    /**
     * A whole request out of the bytes so far, or null while it is incomplete.
     *
     * @return array{method: string, path: string, token: string, body: array<string, mixed>}|null
     */
    public static function parse(string $buffer): ?array
    {
        $end = strpos($buffer, "\r\n\r\n");

        if ($end === false) {
            return null;
        }

        $head = substr($buffer, 0, $end);
        $lines = explode("\r\n", $head);
        $parts = explode(' ', array_shift($lines));
        $method = strtoupper($parts[0] ?? '');
        $path = $parts[1] ?? '/';
        $headers = [];

        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        $length = (int) ($headers['content-length'] ?? 0);
        $body = substr($buffer, $end + 4);

        if (strlen($body) < $length) {
            return null;
        }

        $decoded = $length > 0 ? json_decode(substr($body, 0, $length), true) : [];

        return [
            'method' => $method,
            'path' => $path,
            'token' => $headers['x-quant-token'] ?? '',
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }

    /** @param resource $client @param array<string, mixed> $payload */
    private function answer($client, int $id, int $status, array $payload): void
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $response = "HTTP/1.1 {$status} " . ($status === 200 ? 'OK' : 'Error') . "\r\n"
            . "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n" . $json;

        if (isset($this->clients[$id])) {
            Loop::get()->cancel($this->clients[$id]);
            unset($this->clients[$id]);
        }

        $written = 0;
        $writer = null;
        $writer = Loop::get()->onWritable($client, static function () use ($client, $response, &$written, &$writer): void {
            $n = @fwrite($client, substr($response, $written));

            if ($n === false || $n === 0) {
                Loop::get()->cancel((string) $writer);
                fclose($client);

                return;
            }

            $written += $n;

            if ($written >= strlen($response)) {
                Loop::get()->cancel((string) $writer);
                fclose($client);
            }
        });
    }

    /** @param resource $client */
    private function finish($client, int $id): void
    {
        if (isset($this->clients[$id])) {
            Loop::get()->cancel($this->clients[$id]);
            unset($this->clients[$id]);
        }

        fclose($client);
    }
}
