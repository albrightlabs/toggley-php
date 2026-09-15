<?php

namespace Toggley\Transport;

/**
 * HttpTransport uses curl when available, PHP streams otherwise.
 */
final class HttpTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        return function_exists('curl_init')
            ? $this->curl($method, $url, $headers, $body, $timeout)
            : $this->stream($method, $url, $headers, $body, $timeout);
    }

    private function curl(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $responseHeaders = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) min($timeout * 1000, 2000),
            CURLOPT_HTTPHEADER => array_map(fn ($name, $value) => "{$name}: {$value}", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $content = curl_exec($handle);
        if ($content === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException("Couldn't reach Toggley: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new Response($status, $responseHeaders, (string) $content);
    }

    private function stream(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", array_map(fn ($name, $value) => "{$name}: {$value}", array_keys($headers), $headers)),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            throw new \RuntimeException("Couldn't reach Toggley: " . (error_get_last()['message'] ?? 'request failed'));
        }

        $status = 0;
        $responseHeaders = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return new Response($status, $responseHeaders, $content);
    }
}
