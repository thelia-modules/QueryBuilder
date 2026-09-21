<?php

declare(strict_types=1);

namespace QueryBuilder;

use OpenStudio\QueryBuilderBundle\Form\QueryBuilderType;
use OpenStudio\QueryBuilderBundle\Service\FormOptionsNormalizer;
use Propel\Runtime\Connection\ConnectionInterface;
use QueryBuilder\Service\DiscountCatalogPriceResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Install\Database;
use Thelia\Core\TheliaKernel;
use Thelia\Module\BaseModule;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class QueryBuilder extends BaseModule
{
    /** @var string */
    public const DOMAIN_NAME = 'querybuilder';

    public const MINIMUM_CORE_VERSION = '3.0.0';

    /**
     * The <thelia> bound of module.xml is not enforced by every 2.x core: a checkout
     * of this line dropped into a Thelia 2 shop must refuse to activate, with a message.
     */
    public function preActivation(?ConnectionInterface $con = null): bool
    {
        self::assertSupportedCoreVersion(self::coreVersion());

        return true;
    }

    public function postActivation(?ConnectionInterface $con = null): void
    {
        if (!self::getConfigValue('is_initialized', false)) {
            $database = new Database($con);

            $database->insertSql(null, [__DIR__ . '/Config/TheliaMain.sql']);

            self::setConfigValue('is_initialized', true);
        }
    }

    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        $updateDir = __DIR__ . DS . 'Config' . DS . 'update';

        if (!is_dir($updateDir)) {
            return;
        }

        $finder = Finder::create()
            ->name('*.sql')
            ->depth(0)
            ->sortByName()
            ->in($updateDir);

        $database = new Database($con);

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode() . '\\', __DIR__)
            ->exclude([
                __DIR__ . '/I18n/*',
                __DIR__ . '/Config/**/*',
                // Propel models and queries: instantiated by the ORM, never services.
                __DIR__ . '/Model/*',
                __DIR__ . '/templates/**/*',
                __DIR__ . '/tests/*',
                // Dev dependencies of a checkout linked into a shop: not module classes.
                __DIR__ . '/vendor/',
                __DIR__ . '/QueryBuilder.php',
                // Registered below, outside the scan: see there.
                __DIR__ . '/Service/DiscountCatalogPriceResolver.php',
            ])
            ->autowire(true)
            ->autoconfigure(true);

        //The core aliases CatalogPriceResolverInterface to its own resolver through the
        //"singly implemented interface" rule of the loader, which spans the core and the
        //modules of one container build: a second implementation found by the scan above
        //removes the alias, and every reader of a price loses its resolver. Declared as
        //a plain definition, the decorator is not counted and the alias it decorates stays.
        $servicesConfigurator->set(DiscountCatalogPriceResolver::class)
            ->autowire(true)
            ->autoconfigure(true);

        //Thelia builds its forms from its own factory builder, fed with the types tagged
        //thelia.form.type only: the bundle type, tagged form.type by Symfony, is registered
        //there too so the module BaseForm subclasses can add it
        $servicesConfigurator->set('querybuilder.form.type.query_builder', QueryBuilderType::class)
            ->args([service(FormOptionsNormalizer::class), param('kernel.default_locale')])
            ->tag('thelia.form.type');
    }

    /** @throws \RuntimeException when the running core is older than Thelia 3 or unknown */
    public static function assertSupportedCoreVersion(?string $coreVersion): void
    {
        if ($coreVersion === null || version_compare($coreVersion, self::MINIMUM_CORE_VERSION, '<')) {
            throw new \RuntimeException(sprintf(
                'The QueryBuilder module %s requires Thelia %s or later, this shop runs Thelia %s. The Thelia 2 line of the module is not published.',
                self::currentModuleVersion(),
                self::MINIMUM_CORE_VERSION,
                $coreVersion ?? 'unknown'
            ));
        }
    }

    private static function coreVersion(): ?string
    {
        if (\defined(TheliaKernel::class . '::THELIA_VERSION')) {
            return TheliaKernel::THELIA_VERSION;
        }

        //Thelia 2 exposes its version on Thelia\Core\Thelia, a class Thelia 3 removed
        $legacyKernel = 'Thelia\\Core\\Thelia';

        return \defined($legacyKernel . '::THELIA_VERSION') ? \constant($legacyKernel . '::THELIA_VERSION') : null;
    }

    private static function currentModuleVersion(): string
    {
        $moduleXml = @simplexml_load_file(__DIR__ . '/Config/module.xml');

        return $moduleXml instanceof \SimpleXMLElement ? (string) $moduleXml->version : 'dev';
    }
}
