<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache\Http;

use IndianOpinion\CommentsThroughCache\PurgeException;

/**
 * HttpClient on top of the WordPress HTTP API.
 */
final class WpHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 3): HttpResponse
    {
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'redirection' => 0,
            'user-agent' => 'comments-through-cache',
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new PurgeException('HTTP request failed: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_headers($response);
        $headers = [];
        if (is_iterable($raw)) {
            foreach ($raw as $name => $value) {
                $headers[(string) $name] = is_array($value) ? array_values(array_map('strval', $value)) : (string) $value;
            }
        }
        return new HttpResponse($status, $headers, (string) wp_remote_retrieve_body($response));
    }
}
