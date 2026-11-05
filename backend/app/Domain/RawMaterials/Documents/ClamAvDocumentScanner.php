<?php

namespace App\Domain\RawMaterials\Documents;

use Illuminate\Contracts\Config\Repository;

final class ClamAvDocumentScanner implements DocumentScanner
{
    private const MAX_RESPONSE_BYTES = 4096;

    private const CHUNK_SIZE = 8192;

    public function __construct(private readonly Repository $config) {}

    public function scan(string $contents): DocumentScanOutcome
    {
        [$host, $port, $connectTimeout, $timeout] = $this->configuration();
        $scanResponse = $this->exchange($host, $port, $connectTimeout, $timeout, $contents);

        if (preg_match('/\Astream: (.+) FOUND\z/', $scanResponse, $matches) === 1) {
            [$scannerVersion, $signatureVersion] = $this->scannerVersions(
                $host,
                $port,
                $connectTimeout,
                $timeout,
            );

            return DocumentScanOutcome::infected(
                $scannerVersion,
                $signatureVersion,
                $this->safeValue($matches[1], 255),
            );
        }

        if ($scanResponse !== 'stream: OK') {
            throw new DocumentScannerException(
                'CLAMAV_SCAN_FAILED',
                'Usługa ClamAV nie zwróciła poprawnego wyniku skanowania.',
            );
        }

        [$scannerVersion, $signatureVersion] = $this->scannerVersions(
            $host,
            $port,
            $connectTimeout,
            $timeout,
        );

        return DocumentScanOutcome::clean($scannerVersion, $signatureVersion);
    }

    private function configuration(): array
    {
        $host = $this->config->get('services.clamav.host');
        $port = $this->config->get('services.clamav.port', 3310);
        $connectTimeout = $this->config->get('services.clamav.connect_timeout', 2);
        $timeout = $this->config->get('services.clamav.timeout', 30);

        if (! is_string($host)
            || preg_match('/\A(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?)\z/', $host) !== 1
            || ! is_int($port) || $port < 1 || $port > 65535
            || (! is_int($connectTimeout) && ! is_float($connectTimeout))
            || $connectTimeout <= 0 || $connectTimeout > 10
            || (! is_int($timeout) && ! is_float($timeout))
            || $timeout <= 0 || $timeout > 120) {
            throw new DocumentScannerException(
                'CLAMAV_CONFIGURATION_INVALID',
                'Usługa skanowania dokumentów nie jest poprawnie skonfigurowana.',
            );
        }

        return [$host, $port, (float) $connectTimeout, (float) $timeout];
    }

    private function exchange(
        string $host,
        int $port,
        float $connectTimeout,
        float $timeout,
        ?string $contents,
    ): string {
        $socket = $this->connect($host, $port, $connectTimeout, $timeout);

        try {
            $this->writeAll($socket, $contents === null ? "VERSION\0" : "zINSTREAM\0");
            if ($contents !== null) {
                for ($offset = 0, $length = strlen($contents); $offset < $length; $offset += self::CHUNK_SIZE) {
                    $chunk = substr($contents, $offset, self::CHUNK_SIZE);
                    $this->writeAll($socket, pack('N', strlen($chunk)).$chunk);
                }
                $this->writeAll($socket, pack('N', 0));
            }

            return $this->readResponse($socket);
        } finally {
            fclose($socket);
        }
    }

    private function scannerVersions(
        string $host,
        int $port,
        float $connectTimeout,
        float $timeout,
    ): array {
        $version = $this->exchange($host, $port, $connectTimeout, $timeout, null);
        if (preg_match('/\AClamAV ([^\/\s]{1,63})\/([^\/\s]{1,127})\//', $version, $matches) !== 1) {
            throw new DocumentScannerException(
                'CLAMAV_VERSION_UNAVAILABLE',
                'Nie można potwierdzić wersji silnika i bazy sygnatur ClamAV.',
            );
        }

        return [$matches[1], $matches[2]];
    }

    private function connect(string $host, int $port, float $connectTimeout, float $timeout)
    {
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errorNumber,
            $errorMessage,
            $connectTimeout,
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($socket)) {
            throw new DocumentScannerException(
                'CLAMAV_UNAVAILABLE',
                'Nie można połączyć się z usługą ClamAV.',
            );
        }

        stream_set_timeout($socket, (int) ceil($timeout));

        return $socket;
    }

    private function writeAll(mixed $socket, string $payload): void
    {
        for ($offset = 0, $length = strlen($payload); $offset < $length;) {
            $written = fwrite($socket, substr($payload, $offset));
            if (! is_int($written) || $written <= 0) {
                throw new DocumentScannerException(
                    'CLAMAV_CONNECTION_FAILED',
                    'Przesyłanie danych do usługi ClamAV nie powiodło się.',
                );
            }
            $offset += $written;
        }
    }

    private function readResponse(mixed $socket): string
    {
        $response = '';
        while (! str_contains($response, "\0") && strlen($response) <= self::MAX_RESPONSE_BYTES) {
            $byte = fread($socket, 1);
            if ($byte === false) {
                $metadata = stream_get_meta_data($socket);
                throw new DocumentScannerException(
                    ! empty($metadata['timed_out']) ? 'CLAMAV_TIMEOUT' : 'CLAMAV_CONNECTION_FAILED',
                    'Usługa ClamAV nie zakończyła poprawnie odpowiedzi.',
                );
            }
            if ($byte === '') {
                $metadata = stream_get_meta_data($socket);
                if (! empty($metadata['timed_out'])) {
                    throw new DocumentScannerException(
                        'CLAMAV_TIMEOUT',
                        'Usługa ClamAV nie zakończyła poprawnie odpowiedzi.',
                    );
                }
                if (feof($socket) && $response !== '') {
                    $response = rtrim($response, "\r\n");
                    if ($response !== '' && strlen($response) <= self::MAX_RESPONSE_BYTES) {
                        return $response;
                    }
                }
                throw new DocumentScannerException(
                    'CLAMAV_CONNECTION_FAILED',
                    'Usługa ClamAV nie zakończyła poprawnie odpowiedzi.',
                );
            }
            $response .= $byte;
            if ($byte === "\n") {
                break;
            }
        }

        if (str_ends_with($response, "\0")) {
            $response = substr($response, 0, -1);
        } elseif (str_ends_with($response, "\n")) {
            $response = rtrim($response, "\r\n");
        } else {
            throw new DocumentScannerException(
                'CLAMAV_RESPONSE_INVALID',
                'Odpowiedź usługi ClamAV jest nieprawidłowa.',
            );
        }

        if ($response === '' || strlen($response) > self::MAX_RESPONSE_BYTES) {
            throw new DocumentScannerException(
                'CLAMAV_RESPONSE_INVALID',
                'Odpowiedź usługi ClamAV jest nieprawidłowa.',
            );
        }

        return $response;
    }

    private function safeValue(string $value, int $maxLength): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '', 0, $maxLength);
    }
}
