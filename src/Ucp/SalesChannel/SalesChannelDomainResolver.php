<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * @phpstan-type DomainCandidate array{id: string, salesChannelId: string, url: string, languageId: string|null, currencyId: string|null}
 *
 * @internal
 */
final class SalesChannelDomainResolver
{
    public const CACHE_TAG = 'swag-agentic-commerce.sales-channel-domain-resolver';

    private const CACHE_KEY_PREFIX = 'swag-agentic-commerce.sales-channel-domain-resolver.';

    /**
     * @param EntityRepository<SalesChannelDomainCollection> $domainRepository
     */
    public function __construct(
        private readonly EntityRepository $domainRepository,
        private readonly ?TagAwareCacheInterface $cache = null,
    ) {
    }

    public function resolveByAbsoluteUri(string $absoluteUri, ?Context $context = null): ?SalesChannelResolution
    {
        $requestUrls = $this->normalizedRequestUrls($absoluteUri);
        if ([] === $requestUrls) {
            return null;
        }

        return $this->resolveFromNormalizedRequestUrls($requestUrls, $this->hostVariantsFromUri($absoluteUri), $context);
    }

    public function resolveByBaseUri(string $baseUri, ?Context $context = null): ?SalesChannelResolution
    {
        return $this->resolveByAbsoluteUri($baseUri, $context);
    }

    public function resolveByHostAndPath(string $host, string $path = '/', ?Context $context = null): ?SalesChannelResolution
    {
        $absoluteUri = 'http://'.$host.('' !== $path && '/' === $path[0] ? $path : '/'.$path);
        $hostVariants = $this->hostVariantsFromUri($absoluteUri);
        if ([] === $hostVariants) {
            return null;
        }

        $path = $this->normalizePath($path);
        $bestMatch = null;
        $bestLength = -1;

        foreach ($this->loadDomainCandidates($hostVariants, $context ?? Context::createDefaultContext()) as $candidate) {
            $domainHost = parse_url($candidate['url'], \PHP_URL_HOST);
            if (!\is_string($domainHost) || !\in_array(strtolower($domainHost), $hostVariants, true)) {
                continue;
            }

            $domainPath = $this->normalizePath((string) (parse_url($candidate['url'], \PHP_URL_PATH) ?: '/'));
            $normalizedDomainPath = rtrim($domainPath, '/').'/';
            $normalizedRequestPath = rtrim($path, '/').'/';
            if ('/' !== $normalizedDomainPath && !str_starts_with($normalizedRequestPath, $normalizedDomainPath)) {
                continue;
            }

            if (\strlen($normalizedDomainPath) <= $bestLength) {
                continue;
            }

            $bestMatch = $this->resolutionFromCandidate($candidate);
            $bestLength = \strlen($normalizedDomainPath);
        }

        return $bestMatch;
    }

    /**
     * @param list<string> $requestUrls
     * @param list<string> $hostVariants
     */
    private function resolveFromNormalizedRequestUrls(array $requestUrls, array $hostVariants, ?Context $context): ?SalesChannelResolution
    {
        if ([] === $hostVariants) {
            return null;
        }

        $candidates = $this->loadDomainCandidates($hostVariants, $context ?? Context::createDefaultContext());

        foreach ($candidates as $candidate) {
            foreach ($this->normalizedDomainUrls($candidate['url']) as $domainUrl) {
                if (\in_array($domainUrl, $requestUrls, true)) {
                    return $this->resolutionFromCandidate($candidate);
                }
            }
        }

        $bestMatch = null;
        $bestLength = -1;

        foreach ($candidates as $candidate) {
            foreach ($this->normalizedDomainUrls($candidate['url']) as $domainUrl) {
                foreach ($requestUrls as $requestUrl) {
                    if (!str_starts_with($requestUrl, $domainUrl) || \strlen($domainUrl) <= $bestLength) {
                        continue;
                    }

                    $bestMatch = $this->resolutionFromCandidate($candidate);
                    $bestLength = \strlen($domainUrl);
                }
            }
        }

        return $bestMatch;
    }

    /**
     * @param list<string> $hostVariants
     *
     * @return list<DomainCandidate>
     */
    private function loadDomainCandidates(array $hostVariants, Context $context): array
    {
        $hostVariants = array_values(array_unique($hostVariants));
        if ([] === $hostVariants) {
            return [];
        }

        if (null === $this->cache) {
            return $this->searchDomainCandidates($hostVariants, $context);
        }

        $cacheKey = self::CACHE_KEY_PREFIX.rtrim(strtr(base64_encode(implode('|', $hostVariants)), '+/', '-_'), '=');

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($hostVariants, $context): array {
            $item->tag(self::CACHE_TAG);

            return $this->searchDomainCandidates($hostVariants, $context);
        });
    }

    /**
     * @param list<string> $hostVariants
     *
     * @return list<DomainCandidate>
     */
    private function searchDomainCandidates(array $hostVariants, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(
            MultiFilter::CONNECTION_OR,
            array_map(
                static fn (string $host): ContainsFilter => new ContainsFilter('url', '://'.$host),
                $hostVariants,
            ),
        ));

        $domains = $this->domainRepository->search($criteria, $context)->getEntities()->getElements();
        $candidates = [];

        foreach ($domains as $domain) {
            if (!$domain instanceof SalesChannelDomainEntity) {
                continue;
            }

            $candidate = $this->candidateFromDomain($domain);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @return DomainCandidate|null
     */
    private function candidateFromDomain(SalesChannelDomainEntity $domain): ?array
    {
        $id = $domain->getId();
        $salesChannelId = $domain->getSalesChannelId();
        $url = $domain->getUrl();

        if ('' === $url) {
            return null;
        }

        return [
            'id' => $id,
            'salesChannelId' => $salesChannelId,
            'url' => $url,
            'languageId' => $domain->getLanguageId(),
            'currencyId' => $domain->getCurrencyId(),
        ];
    }

    /**
     * @param DomainCandidate $candidate
     */
    private function resolutionFromCandidate(array $candidate): SalesChannelResolution
    {
        return new SalesChannelResolution(
            $candidate['salesChannelId'],
            rtrim($candidate['url'], '/'),
            $candidate['id'],
            $candidate['languageId'],
            $candidate['currencyId'],
        );
    }

    /**
     * @return list<string>
     */
    private function normalizedRequestUrls(string $absoluteUri): array
    {
        $scheme = parse_url($absoluteUri, \PHP_URL_SCHEME);
        $host = parse_url($absoluteUri, \PHP_URL_HOST);

        if (!\is_string($scheme) || '' === $scheme || !\is_string($host) || '' === $host) {
            return [];
        }

        $port = parse_url($absoluteUri, \PHP_URL_PORT);
        $path = (string) (parse_url($absoluteUri, \PHP_URL_PATH) ?: '/');

        return $this->normalizedUrls($scheme, $host, \is_int($port) ? $port : null, $path);
    }

    /**
     * @return list<string>
     */
    private function normalizedDomainUrls(string $domainUrl): array
    {
        $scheme = parse_url($domainUrl, \PHP_URL_SCHEME);
        $host = parse_url($domainUrl, \PHP_URL_HOST);

        if (!\is_string($scheme) || '' === $scheme || !\is_string($host) || '' === $host) {
            return [];
        }

        $port = parse_url($domainUrl, \PHP_URL_PORT);
        $path = (string) (parse_url($domainUrl, \PHP_URL_PATH) ?: '/');

        return $this->normalizedUrls($scheme, $host, \is_int($port) ? $port : null, $path);
    }

    /**
     * @return list<string>
     */
    private function normalizedUrls(string $scheme, string $host, ?int $port, string $path): array
    {
        $scheme = strtolower($scheme);
        $path = $this->normalizePath($path);
        $portPart = $this->normalizePort($scheme, $port);
        $urls = [];

        foreach ($this->hostVariants($host) as $hostVariant) {
            $urls[] = rtrim($scheme.'://'.$hostVariant.$portPart.$path, '/').'/';
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<string>
     */
    private function hostVariantsFromUri(string $absoluteUri): array
    {
        $host = parse_url($absoluteUri, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return [];
        }

        return $this->hostVariants($host);
    }

    /**
     * @return list<string>
     */
    private function hostVariants(string $host): array
    {
        $host = strtolower(trim($host));
        $variants = [$host];

        if (!str_contains($host, ':')) {
            $unicodeHost = idn_to_utf8($host);
            if (\is_string($unicodeHost) && '' !== $unicodeHost) {
                $variants[] = strtolower($unicodeHost);
            }

            $asciiHost = idn_to_ascii($host);
            if (\is_string($asciiHost) && '' !== $asciiHost) {
                $variants[] = strtolower($asciiHost);
            }
        }

        return array_values(array_unique($variants));
    }

    private function normalizePath(string $path): string
    {
        if ('' === $path || '/' === $path) {
            return '/';
        }

        return '/'.ltrim($path, '/');
    }

    private function normalizePort(string $scheme, ?int $port): string
    {
        if (null === $port || ('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port)) {
            return '';
        }

        return ':'.$port;
    }
}
