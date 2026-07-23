<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\System\SystemConfig;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\System\SystemConfig\Util\ConfigReader;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\System\SystemConfig\CompatConfigReader;

/**
 * Regression guard for the "Element 'subtitle': This element is not expected" failure
 * on Settings -> Basic Information.
 *
 * The plugin replaces the core system-config ConfigReader with CompatConfigReader to
 * work around a Shopware 6.5-only libxml2 issue. When that reader imposed its bundled,
 * frozen schema on a newer line it rejected valid core config (6.7 added <subtitle> to
 * basicInformation.xml). This validates core's own system-config files with the reader,
 * pinned to the Shopware version the integration suite runs against. It fails the moment
 * the reader validates a real core config file against the wrong schema — on 6.5, 6.6,
 * 6.7 or trunk alike:
 *   6.5   -> bundled compat schema
 *   6.6+  -> core's real, current schema
 *
 * @internal
 */
final class SystemConfigSchemaReadableTest extends TestCase
{
    public function testCoreSystemConfigFilesValidateOnThisLane(): void
    {
        // The runtime version comes from the same source the wired service uses
        // (kernel.shopware_version); InstalledVersions is unreliable in some setups.
        $version = (string) KernelLifecycleManager::getKernel()->getContainer()->getParameter('kernel.shopware_version');

        $reader = new CompatConfigReader(new ShopwareVersionDetector($version));

        $files = glob($this->coreSystemConfigDir().'/*.xml');
        static::assertIsArray($files);

        $validated = 0;
        foreach ($files as $file) {
            // Only the system-config "card" files use config.xsd. The same directory
            // also holds routing/DI XMLs (e.g. routes.xml) with a different schema, so
            // filter by the <config> root element.
            $xml = @simplexml_load_file($file);
            if (!$xml instanceof \SimpleXMLElement || 'config' !== $xml->getName()) {
                continue;
            }

            ++$validated;

            // read() validates the XML against the reader's chosen xsd and throws on a
            // schema mismatch (the exact failure being guarded). A returned array = OK.
            static::assertIsArray(
                $reader->read($file),
                \sprintf('Core system-config file "%s" failed schema validation on this lane.', basename($file))
            );
        }

        static::assertGreaterThan(0, $validated, 'No core system-config files were validated.');
    }

    private function coreSystemConfigDir(): string
    {
        $readerFile = (new \ReflectionClass(ConfigReader::class))->getFileName();
        static::assertIsString($readerFile);

        // .../System/SystemConfig/Util/ConfigReader.php -> .../System/Resources/config
        return \dirname($readerFile, 3).'/Resources/config';
    }
}
