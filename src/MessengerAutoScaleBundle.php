<?php

namespace Krak\SymfonyMessengerAutoScale;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class MessengerAutoScaleBundle extends Bundle
{
    const TAG_RAISE_ALERTS = 'messenger_auto_scale.raise_alerts';

    public function build(ContainerBuilder $container) {
        parent::build($container);

        $container->addCompilerPass(new DependencyInjection\BuildSupervisorPoolConfigCompilerPass());
        $container->registerForAutoconfiguration(RaiseAlerts::class)->addTag(self::TAG_RAISE_ALERTS);
    }
}
