<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sylius\Bundle\ThemeBundle\DependencyInjection;

use Sylius\Bundle\ThemeBundle\Configuration\ConfigurationSourceFactoryInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @author Kamil Kokot <kamil.kokot@lakion.com>
 */
final class SyliusThemeExtension extends Extension implements PrependExtensionInterface
{
    /**
     * @var ConfigurationSourceFactoryInterface[]
     */
    private $configurationSourceFactories = [];

    /**
     * @internal
     *
     * {@inheritdoc}
     */
    public function load(array $config, ContainerBuilder $container)
    {
        $config = $this->processConfiguration($this->getConfiguration($config, $container), $config);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config/services'));
        $loader->load('services.xml');

        if ($config['assets']['enabled']) {
            $loader->load('support/assets.xml');
        }

        if ($config['templating']['enabled']) {
            $loader->load('support/templating.xml');
        }

        if ($config['translations']['enabled']) {
            $loader->load('support/translations.xml');
        }

        $this->resolveConfigurationSources($container, $config);

        $container->setAlias('sylius.context.theme', $config['context']);
    }

    /**
     * @internal
     *
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config/services'));

        $this->prependSyliusSettings($container, $loader);
    }

    /**
     * @api
     *
     * @param ConfigurationSourceFactoryInterface $configurationSourceFactory
     */
    public function addConfigurationSourceFactory(ConfigurationSourceFactoryInterface $configurationSourceFactory)
    {
        $this->configurationSourceFactories[$configurationSourceFactory->getName()] = $configurationSourceFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        $configuration = new Configuration($this->configurationSourceFactories);

        $container->addObjectResource($configuration);

        return $configuration;
    }

    /**
     * @param ContainerBuilder $container
     * @param LoaderInterface $loader
     */
    private function prependSyliusSettings(ContainerBuilder $container, LoaderInterface $loader)
    {
        if (!$container->hasExtension('sylius_settings')) {
            return;
        }

        $loader->load('integration/settings.xml');
    }

    /**
     * @param ContainerBuilder $container
     * @param array $config
     *
     * @return mixed
     */
    private function resolveConfigurationSources(ContainerBuilder $container, array $config)
    {
        $configurationProviders = [];
        foreach ($this->configurationSourceFactories as $configurationSourceFactory) {
            $sourceName = $configurationSourceFactory->getName();
            if (isset($config['sources'][$sourceName]) && $config['sources'][$sourceName]['enabled']) {
                $sourceConfig = $config['sources'][$sourceName];

                $configurationProvider = $configurationSourceFactory->initializeSource($container, $sourceConfig);

                if (!$configurationProvider instanceof Reference && !$configurationProvider instanceof Definition) {
                    throw new \InvalidArgumentException(sprintf(
                        'Source factory "%s" was expected to return an instance of "%s" or "%s", "%s" found',
                        $configurationSourceFactory->getName(),
                        Reference::class,
                        Definition::class,
                        is_object($configurationProvider) ? get_class($configurationProvider) : gettype($configurationProvider)
                    ));
                }

                $configurationProviders[] = $configurationProvider;
            }
        }

        $compositeConfigurationProvider = $container->getDefinition('sylius.theme.configuration.provider');
        $compositeConfigurationProvider->replaceArgument(0, $configurationProviders);

        foreach ($this->configurationSourceFactories as $configurationSourceFactory) {
            $container->addObjectResource($configurationSourceFactory);
        }
    }
}
