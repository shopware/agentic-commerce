<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Provider;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Content\ProductExport\Provider\AbstractAgenticCommerceProductExportProvider;
use Swag\AgenticCommerce\Content\ProductExport\Validator\AbstractProviderValidator;

/**
 * The acceptance suite's `Setup` project checks the other half of the chain: every template
 * registered here has expectations in `tests/acceptance/tests/UcpContent/feedProviders.ts`.
 *
 * @internal
 */
#[CoversNothing]
final class EveryProviderShipsATemplateTest extends TestCase
{
    private const PROVIDER_TAG = 'swag_agentic_commerce.product_export.provider';

    public function testEveryProviderIsTaggedAndShipsATemplateAndAValidator(): void
    {
        $providerClasses = $this->concreteSubclassesIn('Provider', AbstractAgenticCommerceProductExportProvider::class);
        static::assertNotEmpty($providerClasses);

        $templateProviderNames = $this->templateProviderNames();
        $validatedProviderNames = $this->validatedProviderNames();
        $services = (string) file_get_contents(\dirname(__DIR__, 5).'/src/Resources/config/services.php');

        foreach ($providerClasses as $providerClass) {
            /** @var AbstractAgenticCommerceProductExportProvider $provider */
            $provider = (new \ReflectionClass($providerClass))->newInstanceWithoutConstructor();
            $providerName = $provider->getTechnicalName();
            $shortName = (new \ReflectionClass($providerClass))->getShortName();

            static::assertMatchesRegularExpression(
                '/->set\('.preg_quote($shortName, '/').'::class\)[^;]*->tag\(\''.preg_quote(self::PROVIDER_TAG, '/').'\'\)/',
                $services,
                \sprintf('%s is not tagged %s in services.php.', $shortName, self::PROVIDER_TAG),
            );
            static::assertContains(
                $providerName,
                $templateProviderNames,
                \sprintf('Provider "%s" has no template under agentic-product-export-templates/ registering it as providerName.', $providerName),
            );
            static::assertContains(
                $providerName,
                $validatedProviderNames,
                \sprintf('Provider "%s" has no validator extending AbstractProviderValidator.', $providerName),
            );
        }
    }

    public function testEveryTemplateBelongsToAProvider(): void
    {
        $providerNames = array_map(
            static fn (string $providerClass): string => (new \ReflectionClass($providerClass))->newInstanceWithoutConstructor()->getTechnicalName(),
            $this->concreteSubclassesIn('Provider', AbstractAgenticCommerceProductExportProvider::class),
        );

        static::assertEqualsCanonicalizing($providerNames, $this->templateProviderNames());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $parentClass
     *
     * @return list<class-string<T>>
     */
    private function concreteSubclassesIn(string $directory, string $parentClass): array
    {
        $namespace = 'Swag\\AgenticCommerce\\Content\\ProductExport\\'.$directory.'\\';
        $concreteSubclasses = [];

        foreach (glob(\dirname(__DIR__, 5).'/src/Content/ProductExport/'.$directory.'/*.php') ?: [] as $file) {
            $candidateClass = $namespace.basename($file, '.php');

            if (is_subclass_of($candidateClass, $parentClass) && !(new \ReflectionClass($candidateClass))->isAbstract()) {
                $concreteSubclasses[] = $candidateClass;
            }
        }

        return $concreteSubclasses;
    }

    /**
     * @return list<string>
     */
    private function templateProviderNames(): array
    {
        $providerNames = [];
        $templatesDir = \dirname(__DIR__, 5).'/src/Resources/app/administration/src/extension/sw-sales-channel/agentic-product-export-templates';

        foreach (glob($templatesDir.'/*/index.js') ?: [] as $registration) {
            if (1 === preg_match("/providerName:\\s*'([^']+)'/", (string) file_get_contents($registration), $match)) {
                $providerNames[] = $match[1];
            }
        }

        return $providerNames;
    }

    /**
     * @return list<string>
     */
    private function validatedProviderNames(): array
    {
        return array_map(
            static function (string $validatorClass): string {
                $validator = (new \ReflectionClass($validatorClass))->newInstanceWithoutConstructor();
                $providerName = (new \ReflectionMethod($validator, 'getProviderTechnicalName'))->invoke($validator);
                \assert(\is_string($providerName));

                return $providerName;
            },
            $this->concreteSubclassesIn('Validator', AbstractProviderValidator::class),
        );
    }
}
