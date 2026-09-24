<?php

declare(strict_types=1);

namespace BosqueGrande\Modules\Lgl;

use BosqueGrande\Core\Container;
use BosqueGrande\Core\ModuleInterface;

if (!defined('ABSPATH')) exit;

/**
 * Registers the LGL client in the container ('lgl'). The client reads
 * the encrypted API key from BosqueGrande settings.
 */
final class LglModule implements ModuleInterface
{
    public function name(): string
    {
        return 'lgl_client';
    }

    public function label(): string
    {
        return 'LGL Client';
    }

    public function activate(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $container->set('lgl', fn(Container $c) => new LglClient(
            $c->get('settings'),
            $c->get('logger'),
        ));
    }

    public function deactivate(Container $container): void
    {
    }
}
