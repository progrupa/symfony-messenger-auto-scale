<?php

namespace Krak\SymfonyMessengerAutoScale;

use Krak\SymfonyMessengerAutoScale\AutoScale\AutoScalerFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class MessengerAutoScaleBundle extends Bundle
{
    const TAG_RAISE_ALERTS = 'messenger_auto_scale.raise_alerts';
    const TAG_SCALER_FACTORY = 'messenger_auto_scale.scaler_factory';

    public function build(ContainerBuilder $container) {
        parent::build($container);

        $container->addCompilerPass(new DependencyInjection\BuildSupervisorPoolConfigCompilerPass());
        $container->registerForAutoconfiguration(RaiseAlerts::class)->addTag(self::TAG_RAISE_ALERTS);
        $container->registerForAutoconfiguration(AutoScalerFactory::class)->addTag(self::TAG_SCALER_FACTORY);
    }
}
