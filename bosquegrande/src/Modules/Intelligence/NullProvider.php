<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Intelligence;

if (!defined('ABSPATH')) exit;

/**
 * The provider that answers when assisted automation is off or unconfigured. Every call
 * returns empty, so callers' deterministic fallbacks always engage and
 * no code path ever needs to ask "is assisted automation on" twice.
 */
final class NullProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function complete(string $system, string $prompt, array $opts = []): string
    {
        return '';
    }
}
