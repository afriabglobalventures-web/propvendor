<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Endpoints\Device;

use Novamira\OAuth\ClientValidation;
use Novamira\OAuth\Grants\DeviceCodeGrant;
use Novamira\OAuth\Repositories\ClientRepository;
use Novamira\OAuth\Repositories\DeviceCodeRepository;
use Novamira\OAuth\Repositories\PendingDeviceCodeStore;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/** Admin page an operator opens to approve a device authorization. */
const PAGE_SLUG = 'novamira-oauth-device';

/**
 * Ten minutes, the RFC 8628 recommendation. Long enough to walk to another device, short enough
 * that a phished code stops working before the operator can be talked into approving it.
 */
const CODE_TTL = 600;

/** Seconds a client must wait between polls; anything faster is answered with `slow_down`. */
const POLL_INTERVAL = 5;

/**
 * 20 consonants: no vowels (so no code spells a word), no digits, and none of the character pairs
 * that are misread aloud or in a terminal font. Eight characters is ~34.5 bits, and a guess is only
 * useful within the code's ten-minute life against a page that already demands an authenticated
 * Novamira manager.
 */
const USER_CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

const USER_CODE_LENGTH = 8;

/**
 * Live pending authorizations one client may hold at once. A device login needs exactly one; a
 * handful of them covers an operator retrying a login they walked away from.
 */
const MAX_PENDING_PER_CLIENT = 5;

/**
 * Live pending authorizations the whole site may hold at once, so a flood spread across many
 * registered clients or source addresses still cannot grow the table without bound.
 */
const MAX_PENDING_PER_SITE = 200;

/** How many fresh user codes a single request will try before giving up on a unique one. */
const USER_CODE_ATTEMPTS = 5;

function register(): void
{
    register_rest_route('novamira/v1', route: '/oauth/device', args: [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => __NAMESPACE__ . '\\handle',
    ]);
}

function verification_uri(): string
{
    return admin_url('admin.php?page=' . PAGE_SLUG);
}

/**
 * RFC 8628 §3.1/§3.2. Mints a device code for the polling client and a user code for the operator.
 * No WordPress user is involved yet: this hands out a pending authorization, and the grant it may
 * become is decided on the verification page.
 */
function handle(WP_REST_Request $req): WP_REST_Response
{
    // Unlike the token and revoke endpoints, this one writes a row per request, so an unattributable
    // caller is throttled rather than waved through: everything a proxy stripped REMOTE_ADDR from
    // shares a single bucket. That is coarse, and deliberately so — the alternative is no limit.
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $throttle_key = $client_ip === '' ? 'unattributed' : $client_ip;
    if (!ClientValidation\within_endpoint_rate_limit('device', $throttle_key)) {
        return new WP_REST_Response(['error' => 'temporarily_unavailable'], 429);
    }

    // RFC 8707, matching the token endpoint: a request that names a resource this server does not
    // expose is refused rather than silently answered with a token for a different audience.
    $resource = (string) $req->get_param('resource');
    if (!\Novamira\OAuth\resource_request_allowed($resource, \Novamira\OAuth\resource_identifier())) {
        return new WP_REST_Response([
            'error' => 'invalid_target',
            'error_description' => 'The requested resource is not served here.',
        ], 400);
    }

    $client_id = (string) $req->get_param('client_id');
    $clients = new ClientRepository();
    if ($client_id === '' || $clients->getClientEntity($client_id) === null) {
        return new WP_REST_Response([
            'error' => 'invalid_client',
            'error_description' => 'Unknown client_id.',
        ], 401);
    }
    if (!$clients->supports_grant($client_id, DeviceCodeGrant::GRANT_IDENTIFIER)) {
        return new WP_REST_Response([
            'error' => 'unauthorized_client',
            'error_description' => 'This client is not registered for the device authorization grant.',
        ], 400);
    }

    $scope = \Novamira\OAuth\normalize_authorization_scope((string) $req->get_param('scope'));
    if ($scope === null) {
        return new WP_REST_Response([
            'error' => 'invalid_scope',
            'error_description' => 'The requested OAuth scope is invalid.',
        ], 400);
    }

    $device_codes = new DeviceCodeRepository();
    // Dead rows are dropped before they are counted, so the caps below measure authorizations that
    // can still be approved rather than the debris of every earlier burst. This is what actually
    // bounds the table on a busy site; the daily garbage collection is only the backstop.
    $device_codes->prune_expired();
    // Two caps, because either one alone is escapable: per-client stops one registration from
    // minting endlessly, site-wide stops the same flood spread across many registered clients. Both
    // count then insert, so a burst of concurrent requests can overshoot by at most the number of
    // PHP workers — bounded, which is the property that matters here.
    if (
        $device_codes->count_pending() >= MAX_PENDING_PER_SITE
        || $device_codes->count_pending($client_id) >= MAX_PENDING_PER_CLIENT
    ) {
        // Reported exactly like the rate limit: which limit a caller reached is not their business.
        return new WP_REST_Response(['error' => 'temporarily_unavailable'], 429);
    }

    $device_code = bin2hex(random_bytes(32));
    $user_code = mint_pending_authorization($device_codes, $device_code, $client_id, $scope);
    if ($user_code === null) {
        return new WP_REST_Response([
            'error' => 'server_error',
            'error_description' => 'The device authorization could not be stored.',
        ], 500);
    }

    // `verification_uri_complete` is deliberately not offered: a link that carries the code is a
    // link that can be sent to someone else, and every approval must pass through the page that
    // shows what is being granted.
    return new WP_REST_Response([
        'device_code' => $device_code,
        'user_code' => $user_code,
        'verification_uri' => verification_uri(),
        'expires_in' => CODE_TTL,
        'interval' => POLL_INTERVAL,
    ], 200);
}

/**
 * Stores the pending authorization and returns the user code to display, or null if it could not be
 * stored. The user code is unique in the table, so losing that race is not a fault and is retried
 * with a fresh code; anything else fails the request, because a device code the table never accepted
 * is a credential that can never be approved and would leave the client polling forever.
 */
function mint_pending_authorization(
    PendingDeviceCodeStore $device_codes,
    string $device_code,
    string $client_id,
    string $scope,
): ?string {
    for ($attempt = 0; $attempt < USER_CODE_ATTEMPTS; $attempt++) {
        $user_code = generate_user_code();
        $normalized = normalize_user_code($user_code);
        if ($device_codes->create($device_code, $normalized, $client_id, $scope, CODE_TTL)) {
            return $user_code;
        }
        // Only a taken user code is worth another attempt; every other failure repeats identically.
        if ($device_codes->find_by_user_code($normalized) === null) {
            return null;
        }
    }
    return null;
}

/** Formatted for reading aloud and typing; the stored form is the normalized one. */
function generate_user_code(): string
{
    $alphabet_size = strlen(USER_CODE_ALPHABET);
    $code = '';
    for ($index = 0; $index < USER_CODE_LENGTH; $index++) {
        $code .= USER_CODE_ALPHABET[random_int(0, $alphabet_size - 1)];
    }
    return substr($code, offset: 0, length: 4) . '-' . substr($code, offset: 4);
}

/**
 * Fold everything a person might type — lowercase, spaces, the separating dash, a pasted code with
 * stray punctuation — onto the single form that was stored. Characters outside the alphabet are
 * dropped rather than rejected so a correct code is never refused over its formatting.
 */
function normalize_user_code(string $value): string
{
    $upper = strtoupper(trim($value));
    $normalized = '';
    for ($index = 0; $index < strlen($upper); $index++) {
        if (!str_contains(USER_CODE_ALPHABET, $upper[$index])) {
            continue;
        }
        $normalized .= $upper[$index];
    }
    return $normalized;
}
