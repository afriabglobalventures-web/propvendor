<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Grants;

use DateInterval;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestAccessTokenEvent;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestRefreshTokenEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Novamira\OAuth\Repositories\DeviceCodeRepository;
use Novamira\OAuth\Repositories\DeviceCodeStore;
use Psr\Http\Message\ServerRequestInterface;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * RFC 8628 device authorization grant.
 *
 * league 8.5 ships no device grant, so the token half is implemented here on AbstractGrant, which
 * keeps token issuance, refresh-token pairing and the audience-bound JWT identical to the
 * authorization-code path. The browser half lives on the verification page: this grant only reads
 * the decision the operator already recorded.
 *
 * The client authenticates with nothing but its device code (public client, no secret and no
 * redirect), so the code is treated as the credential it is: matched by hash, bound to the client
 * that requested it, expiring on its own clock, and redeemable exactly once.
 */
// @mago-expect lint:file-name -- includes/ names files in kebab-case, not after the class.
final class DeviceCodeGrant extends AbstractGrant
{
    /** Kept identical to \Novamira\OAuth\DEVICE_CODE_GRANT_TYPE, which discovery advertises. */
    public const GRANT_IDENTIFIER = 'urn:ietf:params:oauth:grant-type:device_code';

    // The analyzer does not read method bodies of a class extending league's abstract grant, so the
    // members below look unused to it. They are read in respondToAccessTokenRequest.
    // @mago-expect analysis:unused-property
    private DeviceCodeStore $device_codes;

    // @mago-expect analysis:unused-property
    private int $poll_interval;

    public function __construct(
        DeviceCodeStore $device_codes,
        RefreshTokenRepositoryInterface $refresh_tokens,
        int $poll_interval,
    ) {
        $this->device_codes = $device_codes;
        $this->poll_interval = $poll_interval;
        $this->setRefreshTokenRepository($refresh_tokens);
        $this->refreshTokenTTL = new DateInterval('P1M');
    }

    public function getIdentifier(): string
    {
        return self::GRANT_IDENTIFIER;
    }

    /**
     * @param DateInterval $accessTokenTTL
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $client = $this->validateClient($request);

        $device_code = $this->getRequestParameter('device_code', $request);
        if (!is_string($device_code) || $device_code === '') {
            throw OAuthServerException::invalidRequest('device_code');
        }

        $authorization = $this->device_codes->find_by_device_code($device_code);
        // An unknown code and a code belonging to another client answer identically: a client that
        // guesses codes learns nothing about which ones exist.
        if ($authorization === null || !hash_equals($authorization['client_id'], (string) $client->getIdentifier())) {
            throw OAuthServerException::invalidGrant('The device code is invalid');
        }
        if ($this->device_codes->is_expired($authorization['expires_at'])) {
            throw self::expiredToken();
        }
        if ($this->polled_too_fast($authorization['last_polled_at'])) {
            throw self::slowDown();
        }
        $this->device_codes->touch_polled($authorization['device_code_hash']);

        if ($authorization['status'] === DeviceCodeRepository::STATUS_PENDING) {
            throw self::authorizationPending();
        }
        if ($authorization['status'] === DeviceCodeRepository::STATUS_DENIED) {
            throw self::accessDenied();
        }
        // Claim before issuing: a code approved once must not yield a second grant to a replayed
        // or concurrent poll.
        if (
            $authorization['status'] !== DeviceCodeRepository::STATUS_APPROVED
            || !$this->device_codes->claim($authorization['device_code_hash'])
        ) {
            throw OAuthServerException::invalidGrant('The device code has already been redeemed');
        }

        $scopes = $this->validateScopes($authorization['scopes']);
        $access_token = $this->issueAccessToken($accessTokenTTL, $client, (string) $authorization['user_id'], $scopes);
        $this->getEmitter()->emit(new RequestAccessTokenEvent(
            RequestEvent::ACCESS_TOKEN_ISSUED,
            $request,
            $access_token,
        ));
        $responseType->setAccessToken($access_token);

        $refresh_token = $this->issueRefreshToken($access_token);
        if ($refresh_token !== null) {
            $this->getEmitter()->emit(new RequestRefreshTokenEvent(
                RequestEvent::REFRESH_TOKEN_ISSUED,
                $request,
                $refresh_token,
            ));
            $responseType->setRefreshToken($refresh_token);
        }

        return $responseType;
    }

    /**
     * Polls are timed against the interval the client was told to use, with one second of slack:
     * the previous poll is stored to the second, so a client waiting exactly the advertised
     * interval can measure as a fraction under it and would otherwise be slowed for obeying.
     */
    // @mago-expect analysis:unused-method
    private function polled_too_fast(?string $last_polled_at): bool
    {
        if ($last_polled_at === null) {
            return false;
        }
        $previous = strtotime($last_polled_at . ' UTC');
        if ($previous === false) {
            return false;
        }
        return (time() - $previous) < ($this->poll_interval - 1);
    }

    /**
     * The four RFC 8628 §3.5 token-endpoint errors. league 8.5 knows none of them, and the first two
     * are ordinary flow control — the client keeps polling — so they must reach the client verbatim
     * rather than as a generic invalid_grant.
     */
    public static function authorizationPending(): OAuthServerException
    {
        return new OAuthServerException('The authorization request is still pending', 9, 'authorization_pending', 400);
    }

    public static function slowDown(): OAuthServerException
    {
        return new OAuthServerException('Polling too frequently', 9, 'slow_down', 400);
    }

    public static function expiredToken(): OAuthServerException
    {
        return new OAuthServerException('The device code has expired', 9, 'expired_token', 400);
    }

    public static function accessDenied(): OAuthServerException
    {
        return new OAuthServerException('The authorization request was denied', 9, 'access_denied', 400);
    }
}
