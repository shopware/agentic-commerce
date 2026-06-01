<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

final readonly class DoctrineDbalUcpOAuthStore
{
    private const ACCESS_TOKEN_TTL = 3600;
    private const AUTHORIZATION_CODE_TTL = 600;
    private const REFRESH_TOKEN_TTL = 2592000;

    public function __construct(
        private Connection $connection,
    ) {
        UcpOAuthSchema::ensure($this->connection);
    }

    public function saveAuthorizationCode(
        string $salesChannelId,
        string $code,
        string $clientId,
        string $redirectUri,
        string $subject,
        string $scope,
        string $codeChallenge,
        string $codeChallengeMethod,
    ): void {
        $payload = [
            'code_hash' => $this->hashBytes($code),
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'subject' => $subject,
            'scope' => $scope,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'expires_at' => time() + self::AUTHORIZATION_CODE_TTL,
            'consumed_at' => null,
            'created_at' => $this->now(),
        ];

        try {
            $this->connection->insert(UcpOAuthSchema::CODE_TABLE, $payload);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update(UcpOAuthSchema::CODE_TABLE, $payload, ['code_hash' => $payload['code_hash']]);
        }
    }

    public function consumeAuthorizationCode(string $code, string $salesChannelId): ?OAuthAuthorization
    {
        $codeHash = $this->hashBytes($code);
        $salesChannelIdBytes = Uuid::fromHexToBytes($salesChannelId);
        $now = time();

        return $this->connection->transactional(function () use ($codeHash, $salesChannelIdBytes, $now): ?OAuthAuthorization {
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id, client_id, redirect_uri, subject, scope, code_challenge, code_challenge_method FROM `%s` WHERE code_hash = :codeHash AND sales_channel_id = :salesChannelId AND consumed_at IS NULL AND expires_at >= :now', UcpOAuthSchema::CODE_TABLE),
                ['codeHash' => $codeHash, 'salesChannelId' => $salesChannelIdBytes, 'now' => $now],
            );

            if (false === $row) {
                return null;
            }

            $updated = $this->connection->executeStatement(
                \sprintf('UPDATE `%s` SET consumed_at = :consumedAt WHERE code_hash = :codeHash AND sales_channel_id = :salesChannelId AND consumed_at IS NULL AND expires_at >= :now', UcpOAuthSchema::CODE_TABLE),
                ['consumedAt' => $this->now(), 'codeHash' => $codeHash, 'salesChannelId' => $salesChannelIdBytes, 'now' => $now],
            );

            if (1 !== $updated) {
                return null;
            }

            return new OAuthAuthorization(
                (string) $row['sales_channel_id'],
                (string) $row['client_id'],
                (string) $row['redirect_uri'],
                (string) $row['subject'],
                (string) $row['scope'],
                (string) $row['code_challenge'],
                (string) $row['code_challenge_method'],
            );
        });
    }

    public function issueTokenSet(string $salesChannelId, string $clientId, string $subject, string $scope): OAuthTokenSet
    {
        $accessToken = 'ucp_access_'.bin2hex(random_bytes(24));
        $refreshToken = 'ucp_refresh_'.bin2hex(random_bytes(24));
        $refreshTokenHash = $this->hashBytes($refreshToken);
        $now = $this->now();

        $this->connection->insert(UcpOAuthSchema::REFRESH_TOKEN_TABLE, [
            'token_hash' => $refreshTokenHash,
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'client_id' => $clientId,
            'subject' => $subject,
            'scope' => $scope,
            'expires_at' => time() + self::REFRESH_TOKEN_TTL,
            'revoked_at' => null,
            'created_at' => $now,
        ]);

        $this->connection->insert(UcpOAuthSchema::ACCESS_TOKEN_TABLE, [
            'token_hash' => $this->hashBytes($accessToken),
            'refresh_token_hash' => $refreshTokenHash,
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'client_id' => $clientId,
            'subject' => $subject,
            'scope' => $scope,
            'expires_at' => time() + self::ACCESS_TOKEN_TTL,
            'created_at' => $now,
        ]);

        return new OAuthTokenSet($accessToken, $refreshToken, self::ACCESS_TOKEN_TTL, $scope);
    }

    public function refreshTokenSet(string $refreshToken, ?string $clientId = null, ?string $salesChannelId = null): ?OAuthTokenSet
    {
        $refreshTokenHash = $this->hashBytes($refreshToken);
        $criteria = [
            'tokenHash' => $refreshTokenHash,
            'now' => time(),
        ];
        $salesChannelCondition = '';
        if (null !== $salesChannelId && '' !== $salesChannelId) {
            $salesChannelCondition = ' AND sales_channel_id = :salesChannelId';
            $criteria['salesChannelId'] = Uuid::fromHexToBytes($salesChannelId);
        }

        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id, client_id, subject, scope FROM `%s` WHERE token_hash = :tokenHash%s AND revoked_at IS NULL AND expires_at >= :now', UcpOAuthSchema::REFRESH_TOKEN_TABLE, $salesChannelCondition),
            $criteria,
        );

        if (false === $row) {
            return null;
        }

        if (null !== $clientId && '' !== $clientId && !hash_equals((string) $row['client_id'], $clientId)) {
            return null;
        }

        return $this->issueTokenSet(
            (string) $row['sales_channel_id'],
            (string) $row['client_id'],
            (string) $row['subject'],
            (string) $row['scope'],
        );
    }

    private function hashBytes(string $value): string
    {
        return hash('sha256', $value, true);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
