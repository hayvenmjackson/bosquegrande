<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Intelligence;

use BosqueGrande\Core\Logger;

if (!defined('ABSPATH')) exit;

/** OpenAI adapter. Same thin contract as the others. */
final class OpenAiProvider implements ProviderInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const DEFAULT_MODEL = 'gpt-4o-mini';

    public function __construct(private string $apiKey, private Logger $logger, private string $model = '')
    {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function complete(string $system, string $prompt, array $opts = []): string
    {
        if (!$this->isConfigured()) return '';
        $messages = [];
        if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            'body' => wp_json_encode([
                'model' => (string) ($opts['model'] ?? ($this->model !== '' ? $this->model : self::DEFAULT_MODEL)),
                'max_tokens' => (int) ($opts['max_tokens'] ?? 1024),
                'messages' => $messages,
            ]),
        ]);
        if (is_wp_error($response)) {
            $this->logger->warn('intelligence', 'OpenAI call failed (transport).', ['error' => $response->get_error_message()]);
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            $this->logger->warn('intelligence', 'OpenAI call failed.', ['status' => $code]);
            return '';
        }
        return (string) ($data['choices'][0]['message']['content'] ?? '');
    }
}
