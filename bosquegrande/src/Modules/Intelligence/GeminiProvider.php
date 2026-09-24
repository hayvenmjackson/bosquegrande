<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Intelligence;

use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/** Google Gemini adapter. Same thin contract as the others. */
final class GeminiProvider implements ProviderInterface
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const DEFAULT_MODEL = 'gemini-2.0-flash';

    public function __construct(private string $apiKey, private Logger $logger, private string $model = '')
    {
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function complete(string $system, string $prompt, array $opts = []): string
    {
        if (!$this->isConfigured()) return '';
        $model = (string) ($opts['model'] ?? ($this->model !== '' ? $this->model : self::DEFAULT_MODEL));
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => (int) ($opts['max_tokens'] ?? 1024)],
        ];
        if ($system !== '') $body['systemInstruction'] = ['parts' => [['text' => $system]]];

        $response = wp_remote_post(sprintf(self::ENDPOINT, rawurlencode($model)), [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey,
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            $this->logger->warn('intelligence', 'Gemini call failed (transport).', ['error' => $response->get_error_message()]);
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            $this->logger->warn('intelligence', 'Gemini call failed.', ['status' => $code]);
            return '';
        }
        $out = '';
        foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            $out .= (string) ($part['text'] ?? '');
        }
        return $out;
    }
}
