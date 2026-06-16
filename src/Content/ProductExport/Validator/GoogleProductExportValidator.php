<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Validator;

use Shopware\Core\Content\ProductExport\Error\ErrorCollection;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Swag\AgenticCommerce\Content\ProductExport\Error\ProviderValidationError;

/**
 * Validates Google Merchant Center XML feeds (RSS 2.0 with the http://base.google.com/ns/1.0 namespace).
 */
class GoogleProductExportValidator extends AbstractProviderValidator
{
    private const GOOGLE_NAMESPACE = 'http://base.google.com/ns/1.0';

    /**
     * @var list<string>
     */
    private const ALLOWED_AVAILABILITY_VALUES = [
        'in_stock',
        'out_of_stock',
        'preorder',
        'backorder',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_CONDITION_VALUES = [
        'new',
        'refurbished',
        'used',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_GENDER_VALUES = [
        'male',
        'female',
        'unisex',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_SIZE_SYSTEM_VALUES = [
        'AU',
        'BR',
        'CN',
        'DE',
        'EU',
        'FR',
        'IT',
        'JP',
        'MEX',
        'UK',
        'US',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_AGE_GROUP_VALUES = [
        'newborn',
        'infant',
        'toddler',
        'kids',
        'adult',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_GOOGLE_FIELDS = [
        'id',
        'availability',
        'condition',
        'price',
        'image_link',
        'brand',
    ];

    protected function getProviderTechnicalName(): string
    {
        return 'google';
    }

    protected function validateProviderExport(ProductExportEntity $productExportEntity, string $productExportContent, ErrorCollection $errors): void
    {
        if (ProductExportEntity::FILE_FORMAT_XML !== $productExportEntity->getFileFormat()) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'file_format',
                'Google product exports must use the "xml" file format.'
            ));

            return;
        }

        if ('' === trim($productExportContent)) {
            $errors->add($this->invalidXmlError($productExportEntity));

            return;
        }

        $previous = libxml_use_internal_errors(true);
        $reader = new \XMLReader();

        if (false === $reader->XML($productExportContent, null, \LIBXML_NONET)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $errors->add($this->invalidXmlError($productExportEntity));

            return;
        }

        // Stream <item> elements one at a time instead of building a DOM for the
        // whole feed, so peak memory stays bounded to a single item on large
        // catalogs. Per-item errors are buffered and only flushed once the feed is
        // known to be well-formed and non-empty, matching the original semantics.
        $itemErrors = new ErrorCollection();
        $itemIds = [];
        $itemCount = 0;

        $continue = $reader->read();
        while ($continue) {
            if (\XMLReader::ELEMENT === $reader->nodeType && 'item' === $reader->localName && '' === $reader->namespaceURI) {
                ++$itemCount;
                $node = $reader->expand(new \DOMDocument());

                if (false !== $node) {
                    $item = simplexml_import_dom($node);

                    if ($item instanceof \SimpleXMLElement) {
                        $this->validateItem($productExportEntity, $item, $itemCount, $itemIds, $itemErrors);
                    }
                }

                $continue = $reader->next();

                continue;
            }

            $continue = $reader->read();
        }

        $libxmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $reader->close();

        if ([] !== $libxmlErrors) {
            $errors->add($this->invalidXmlError($productExportEntity));

            return;
        }

        if (0 === $itemCount) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'item',
                'The Google feed must contain at least one <item> element.'
            ));

            return;
        }

        foreach ($itemErrors as $itemError) {
            $errors->add($itemError);
        }
    }

    /**
     * @param array<string, true> $itemIds
     */
    private function validateItem(ProductExportEntity $productExportEntity, \SimpleXMLElement $item, int $line, array &$itemIds, ErrorCollection $errors): void
    {
        $googleChildren = $item->children(self::GOOGLE_NAMESPACE);

        foreach (self::REQUIRED_GOOGLE_FIELDS as $field) {
            $value = (string) ($googleChildren->{$field} ?? '');

            if ('' === trim($value)) {
                $errors->add(new ProviderValidationError(
                    $productExportEntity->getId(),
                    $this->getProviderTechnicalName(),
                    $field,
                    \sprintf('The required field "g:%s" is missing or empty.', $field),
                    $line
                ));
            }
        }

        $title = trim((string) ($item->title ?? ''));
        if ('' === $title) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'title',
                'The required field "title" is missing or empty.',
                $line
            ));
        }

        $link = trim((string) ($item->link ?? ''));
        if ('' === $link || false === filter_var($this->encodeUrl($link), \FILTER_VALIDATE_URL)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'link',
                'The field "link" must be a valid absolute URL.',
                $line
            ));
        }

        $imageLink = trim((string) ($googleChildren->image_link ?? ''));
        if ('' !== $imageLink && false === filter_var($this->encodeUrl($imageLink), \FILTER_VALIDATE_URL)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'image_link',
                'The field "g:image_link" must be a valid absolute URL.',
                $line
            ));
        }

        $availability = trim((string) ($googleChildren->availability ?? ''));
        if ('' !== $availability && !\in_array($availability, self::ALLOWED_AVAILABILITY_VALUES, true)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'availability',
                'The field "g:availability" must be one of: in_stock, out_of_stock, preorder, backorder.',
                $line
            ));
        }

        $condition = trim((string) ($googleChildren->condition ?? ''));
        if ('' !== $condition && !\in_array($condition, self::ALLOWED_CONDITION_VALUES, true)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'condition',
                'The field "g:condition" must be one of: new, refurbished, used.',
                $line
            ));
        }

        $price = trim((string) ($googleChildren->price ?? ''));
        if ('' !== $price && 1 !== preg_match('/^\d+(?:\.\d+)? [A-Z]{3}$/', $price)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'price',
                'The field "g:price" must be formatted as "<number> <ISO-4217>".',
                $line
            ));
        }

        $salePrice = trim((string) ($googleChildren->sale_price ?? ''));
        if ('' !== $salePrice && 1 !== preg_match('/^\d+(?:\.\d+)? [A-Z]{3}$/', $salePrice)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'sale_price',
                'The field "g:sale_price" must be formatted as "<number> <ISO-4217>".',
                $line
            ));
        }

        $id = trim((string) ($googleChildren->id ?? ''));
        if ('' !== $id) {
            if (isset($itemIds[$id])) {
                $errors->add(new ProviderValidationError(
                    $productExportEntity->getId(),
                    $this->getProviderTechnicalName(),
                    'id',
                    \sprintf('The g:id "%s" is not unique in the feed.', $id),
                    $line
                ));
            }

            $itemIds[$id] = true;
        }

        $gender = trim((string) ($googleChildren->gender ?? ''));
        if ('' !== $gender && !\in_array($gender, self::ALLOWED_GENDER_VALUES, true)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'gender',
                'The field "g:gender" must be one of: male, female, unisex.',
                $line
            ));
        }

        $sizeSystem = trim((string) ($googleChildren->size_system ?? ''));
        if ('' !== $sizeSystem && !\in_array($sizeSystem, self::ALLOWED_SIZE_SYSTEM_VALUES, true)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'size_system',
                'The field "g:size_system" must be one of: AU, BR, CN, DE, EU, FR, IT, JP, MEX, UK, US.',
                $line
            ));
        }

        $ageGroup = trim((string) ($googleChildren->age_group ?? ''));
        if ('' !== $ageGroup && !\in_array($ageGroup, self::ALLOWED_AGE_GROUP_VALUES, true)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'age_group',
                'The field "g:age_group" must be one of: newborn, infant, toddler, kids, adult.',
                $line
            ));
        }

        $gtin = trim((string) ($googleChildren->gtin ?? ''));
        $mpn = trim((string) ($googleChildren->mpn ?? ''));
        $identifierExists = trim((string) ($googleChildren->identifier_exists ?? ''));

        if ('' === $gtin && '' === $mpn && 'no' !== strtolower($identifierExists)) {
            $errors->add(new ProviderValidationError(
                $productExportEntity->getId(),
                $this->getProviderTechnicalName(),
                'identifier_exists',
                'When no g:gtin or g:mpn is provided, g:identifier_exists must be set to "no".',
                $line
            ));
        }
    }

    private function invalidXmlError(ProductExportEntity $productExportEntity): ProviderValidationError
    {
        return new ProviderValidationError(
            $productExportEntity->getId(),
            $this->getProviderTechnicalName(),
            'xml',
            'The Google feed must be valid XML.'
        );
    }

    /**
     * Percent-encodes URL path segments so values containing literal spaces
     * (e.g. media filenames) pass FILTER_VALIDATE_URL, while leaving scheme,
     * host, port, and query intact.
     *
     * Inlined from Shopware's UrlEncoder::encodeUrl(), which is not available on
     * the Shopware versions this plugin targets (< 6.7.10).
     */
    private function encodeUrl(string $url): string
    {
        $urlInfo = parse_url($url);

        if (!\is_array($urlInfo)) {
            return $url;
        }

        $path = implode('/', array_map('rawurlencode', explode('/', $urlInfo['path'] ?? '')));

        if (isset($urlInfo['query'])) {
            $path .= '?'.$urlInfo['query'];
        }

        $encoded = '';

        if (isset($urlInfo['scheme'])) {
            $encoded = $urlInfo['scheme'].'://';
        }

        if (isset($urlInfo['host'])) {
            $encoded .= $urlInfo['host'];
        }

        if (isset($urlInfo['port'])) {
            $encoded .= ':'.$urlInfo['port'];
        }

        return $encoded.$path;
    }
}
