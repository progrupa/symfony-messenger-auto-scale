<?php

namespace Krak\SymfonyMessengerAutoScale\Tests\Feature\Fixtures;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class TestFixtureBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface {
        return new class() extends Extension {
            public function getAlias(): string {
                return 'messenger_auto_scale_test';
            }

            public function load(array $configs, ContainerBuilder $container) {
                $loader = new PhpFileLoader($container, new FileLocator(__DIR__));
                $loader->load('services.php');
            }
        };
    }
}
