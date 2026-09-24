<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Intelligence;

use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/**
 * Anthropic adapter (the default provider). One messages-API call,
 * text out, empty string on any failure. Failures are logged with the
 * HTTP status or transport error, never with request content.
 */
final class AnthropicProvider implements ProviderInterface
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

    public function __construct(private string $apiKey, private Logger $logger, private string $model = '')
    {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function complete(string $system, string $prompt, array $opts = []): string
    {
        if (!$this->isConfigured()) return '';
        $body = [
            'model' => (string) ($opts['model'] ?? ($this->model !== '' ? $this->model : self::DEFAULT_MODEL)),
            'max_tokens' => (int) ($opts['max_tokens'] ?? 1024),
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($system !== '') $body['system'] = $system;

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            $this->logger->warn('intelligence', 'Anthropic call failed (transport).', ['error' => $response->get_error_message()]);
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            $this->logger->warn('intelligence', 'Anthropic call failed.', ['status' => $code]);
            return '';
        }
        $out = '';
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $out .= (string) ($block['text'] ?? '');
        }
        return $out;
    }
}
