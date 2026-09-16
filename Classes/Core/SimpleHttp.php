<?php

declare(strict_types=1);

namespace Calmfox\Watch\Core;

/**
 * Minimalny klient HTTP bez zależności od Flow. Powód jest praktyczny: endpoint
 * stanu ma odpowiedzieć także wtedy, gdy połowa aplikacji leży, więc im mniej
 * warstw po drodze, tym lepiej. Twardy maksymalny czas odpowiedzi jest tu
 * najważniejszym parametrem: check nie może zamulić strony.
 *
 * Przekierowań nie idziemy świadomie. Przekierowanie z adresu API albo
 * z klastra wyszukiwarki to sygnał, że trafiliśmy nie tam, gdzie chcieliśmy.
 */
final class SimpleHttp
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, error: string}
     */
    public static function request(string $method, string $url, ?string $body = null, array $headers = [], float $timeout = 5.0): array
    {
        if (\function_exists('curl_init')) {
            return self::viaCurl($method, $url, $body, $headers, $timeout);
        }

        return self::viaStream($method, $url, $body, $headers, $timeout);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, error: string}
     */
    private static function viaCurl(string $method, string $url, ?string $body, array $headers, float $timeout): array
    {
        $handle = curl_init($url);
        if (false === $handle) {
            return ['status' => 0, 'body' => '', 'error' => 'nie udało się otworzyć połączenia'];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        curl_setopt_array($handle, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_CONNECTTIMEOUT => (int) max(1, ceil($timeout)),
            \CURLOPT_TIMEOUT => (int) max(1, ceil($timeout)),
            \CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (null !== $body) {
            curl_setopt($handle, \CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        return ['status' => $status, 'body' => \is_string($response) ? $response : '', 'error' => $error];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, error: string}
     */
    private static function viaStream(string $method, string $url, ?string $body, array $headers, float $timeout): array
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name.': '.$value."\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $lines,
            'content' => $body ?? '',
            'timeout' => $timeout,
            'follow_location' => 0,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($url, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (1 === preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [
            'status' => $status,
            'body' => \is_string($response) ? $response : '',
            'error' => false === $response ? 'brak odpowiedzi w wyznaczonym czasie' : '',
        ];
    }
}
