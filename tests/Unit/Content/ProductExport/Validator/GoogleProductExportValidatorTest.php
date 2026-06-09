<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\Error\ErrorCollection;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Content\ProductExport\Error\ProviderValidationError;
use Swag\AgenticCommerce\Content\ProductExport\Validator\GoogleProductExportValidator;
use Swag\AgenticCommerce\Content\ProductExport\Validator\JsonlRowParser;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * @internal
 */
#[CoversClass(GoogleProductExportValidator::class)]
class GoogleProductExportValidatorTest extends TestCase
{
    public function testValidateDoesNothingForOtherProviders(): void
    {
        $entity = $this->createProductExportEntity('open-ai');

        $errors = new ErrorCollection();

        $this->createValidator()->validate($entity, 'not-xml', $errors);

        static::assertCount(0, $errors);
    }

    public function testValidateDoesNothingWhenProviderIsNotConfigured(): void
    {
        $entity = $this->createProductExportEntity(null);
        $errors = new ErrorCollection();

        $this->createValidator()->validate($entity, 'whatever', $errors);

        static::assertCount(0, $errors);
    }

    public function testValidateAddsErrorWhenFileFormatIsNotXml(): void
    {
        $entity = $this->createProductExportEntity();
        $entity->setFileFormat(SwagAgenticCommerce::FILE_FORMAT_JSONL);

        $errors = new ErrorCollection();

        $this->createValidator()->validate($entity, '', $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('file_format', $error->getParameters()['field']);
    }

    public function testValidateAddsErrorForMalformedXml(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $this->createValidator()->validate($entity, '<not-xml', $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('xml', $error->getParameters()['field']);
    }

    public function testValidateAddsErrorForFeedWithoutItems(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $this->createValidator()->validate(
            $entity,
            $this->wrapItems(''),
            $errors
        );

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('item', $error->getParameters()['field']);
    }

    public function testValidateDoesNotAddErrorsForValidGoogleFeed(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $this->createValidator()->validate(
            $entity,
            $this->wrapItems($this->createValidItem()),
            $errors
        );

        static::assertCount(0, $errors);
    }

    public function testValidateAddsErrorForMissingRequiredGoogleField(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['brand' => null]);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('brand', $error->getParameters()['field']);
    }

    public function testValidateAddsErrorForInvalidLink(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['link' => 'not-a-url']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('link', $error->getParameters()['field']);
    }

    public function testValidateAddsErrorForInvalidAvailability(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['availability' => 'unknown']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('availability', $error->getParameters()['field']);
    }

    public function testValidateAddsErrorForInvalidCondition(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['condition' => 'broken']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('condition', $error->getParameters()['field']);
    }

    public function testValidateAddsErrorForInvalidGender(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['gender' => 'Damen']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('gender', $error->getParameters()['field']);
    }

    public function testValidateAcceptsValidGender(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['gender' => 'female']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(0, $errors);
    }

    public function testValidateAddsErrorForInvalidSizeSystem(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['size_system' => 'EU-Größen']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('size_system', $error->getParameters()['field']);
    }

    public function testValidateAcceptsValidSizeSystem(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['size_system' => 'EU']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(0, $errors);
    }

    public function testValidateAddsErrorForInvalidAgeGroup(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['age_group' => 'Erwachsene']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('age_group', $error->getParameters()['field']);
    }

    public function testValidateAcceptsValidAgeGroup(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['age_group' => 'adult']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(0, $errors);
    }

    public function testValidateAddsErrorForInvalidPriceFormat(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['price' => 'EUR 10.99']);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('price', $error->getParameters()['field']);
    }

    public function testValidateAddsErrorWhenIdentifiersAreMissingWithoutFlag(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem(['gtin' => null, 'mpn' => null]);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('identifier_exists', $error->getParameters()['field']);
    }

    public function testValidateAcceptsIdentifierExistsNo(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $item = $this->createValidItem([
            'gtin' => null,
            'mpn' => null,
            'identifier_exists' => 'no',
        ]);

        $this->createValidator()->validate($entity, $this->wrapItems($item), $errors);

        static::assertCount(0, $errors);
    }

    public function testValidateAddsErrorForDuplicateIds(): void
    {
        $entity = $this->createProductExportEntity();
        $errors = new ErrorCollection();

        $content = $this->wrapItems($this->createValidItem().$this->createValidItem(['title' => 'Second']));

        $this->createValidator()->validate($entity, $content, $errors);

        static::assertCount(1, $errors);
        $error = $errors->first();
        static::assertInstanceOf(ProviderValidationError::class, $error);
        static::assertSame('id', $error->getParameters()['field']);
    }

    private function createProductExportEntity(?string $provider = 'google'): ProductExportEntity
    {
        $entity = new ProductExportEntity();
        $entity->setId(Uuid::randomHex());
        $entity->setFileFormat(ProductExportEntity::FILE_FORMAT_XML);

        if (null !== $provider) {
            $entity->assign(['provider' => $provider]);
        }

        return $entity;
    }

    private function createValidator(): GoogleProductExportValidator
    {
        return new GoogleProductExportValidator(new JsonlRowParser());
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function createValidItem(array $overrides = []): string
    {
        $defaults = [
            'id' => 'SKU-1',
            'title' => 'Example',
            'description' => 'Example description',
            'link' => 'https://example.com/product',
            'image_link' => 'https://example.com/image.jpg',
            'availability' => 'in_stock',
            'condition' => 'new',
            'price' => '10.99 EUR',
            'brand' => 'ACME',
            'gtin' => '0123456789012',
            'mpn' => 'MPN-1',
            'identifier_exists' => null,
        ];

        $values = array_replace($defaults, $overrides);

        $googleFields = ['id', 'image_link', 'availability', 'condition', 'price', 'brand', 'gtin', 'mpn', 'identifier_exists', 'gender', 'size_system', 'age_group'];

        $xml = '<item>';

        foreach ($values as $field => $value) {
            if (null === $value || '' === $value) {
                continue;
            }

            $tag = \in_array($field, $googleFields, true) ? 'g:'.$field : $field;
            $xml .= \sprintf('<%s>%s</%s>', $tag, htmlspecialchars((string) $value, \ENT_XML1), $tag);
        }

        $xml .= '</item>';

        return $xml;
    }

    private function wrapItems(string $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8" ?>'
            .'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'
            .'<channel>'
            .$items
            .'</channel>'
            .'</rss>';
    }
}
