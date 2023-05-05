<?php

namespace Krak\SymfonyMessengerAutoScale\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('messenger_auto_scale');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('console_path')->defaultValue('%kernel.project_dir%/bin/console')->end()
                ->booleanNode('must_match_all_receivers')->defaultTrue()->end()
                ->arrayNode('pools')->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('worker_command')->end()
                            ->arrayNode('worker_command_options')
                                ->scalarPrototype()->end()
                            ->end()
                            ->integerNode('backed_up_alert_threshold')->end()
                            ->integerNode('heartbeat_interval')->defaultValue(60)->end()
                            ->scalarNode('receivers')->end()
                            ->arrayNode('scalers')
                                ->arrayPrototype()
                                    ->children()
                                        ->scalarNode('type')
                                            ->isRequired()
                                            ->beforeNormalization()->always()->then(fn($v) => strtolower($v))
                                            ->end()
                                        ->end()
                                        ->integerNode('min_procs')->end()   //  min-max clip
                                        ->integerNode('max_procs')->end()   //  min-max clip
                                        ->integerNode('message_rate')->defaultValue(100)->end()    //  queue size
                                        ->integerNode('scale_up_threshold_seconds')->defaultValue(5)->end()  //  debouncing
                                        ->integerNode('scale_down_threshold_seconds')->defaultValue(60)->end()    //  debouncing
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}