<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Intelligence;

if (!defined('ABSPATH')) exit;

/**
 * The contract every service provider adapter fulfills. Adapters are thin:
 * one HTTP call, one string back, empty string on any failure (the
 * caller logs and degrades; assisted automation never blocks a deterministic path).
 */
interface ProviderInterface
{
    /** Machine name: anthropic, openai, gemini, none. */
    public function name(): string;

    /** True when a usable key is on file for this provider. */
    public function isConfigured(): bool;

    /**
     * One completion. $system frames the task, $prompt carries it.
     * Returns the model's text, or '' on refusal, error, or timeout.
     * @param array{model?: string, max_tokens?: int} $opts
     */
    public function complete(string $system, string $prompt, array $opts = []): string;
}
