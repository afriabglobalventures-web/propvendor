<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Repositories;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The device-authorization state the token grant reads. Narrow on purpose: the grant needs to find
 * an authorization, know whether it is still alive, record a poll, and claim it exactly once —
 * everything else (minting, the operator's decision) belongs to the endpoint and the page.
 */
interface DeviceCodeStore
{
    /**
     * @return array{
     *     device_code_hash: string,
     *     client_id: string,
     *     user_id: int,
     *     scopes: string,
     *     status: string,
     *     expires_at: string,
     *     last_polled_at: string|null,
     * }|null
     */
    public function find_by_device_code(string $device_code): ?array;

    public function is_expired(string $expires_at): bool;

    public function touch_polled(string $device_code_hash): void;

    public function claim(string $device_code_hash): bool;
}

/**
 * The device endpoint's view of the same table: mint a pending authorization, resolve a user code,
 * and keep the table from growing on requests that never authenticated. Separate from
 * DeviceCodeStore because the token grant needs none of it and must not be able to write a new
 * authorization.
 */
// @mago-expect lint:single-class-per-file
// Both interfaces are views of the repository below and are kept beside it.
interface PendingDeviceCodeStore
{
    public function create(
        string $device_code,
        string $user_code,
        string $client_id,
        string $scopes,
        int $expires_in,
    ): bool;

    /**
     * @return array{
     *     device_code_hash: string,
     *     client_id: string,
     *     user_id: int,
     *     scopes: string,
     *     status: string,
     *     expires_at: string,
     *     last_polled_at: string|null,
     * }|null
     */
    public function find_by_user_code(string $user_code): ?array;

    public function count_pending(?string $client_id = null): int;

    public function prune_expired(): void;
}

// @mago-expect lint:single-class-per-file
// The interfaces above are the grant's and the endpoint's views of this repository.
/**
 * Storage for RFC 8628 device authorizations.
 *
 * Neither code is stored in the clear: the device code authenticates the polling client and the
 * user code authorizes the grant, so both are kept as SHA-256 hashes and matched by hash, exactly
 * like authorization codes and access tokens.
 *
 * A row moves pending -> approved|denied (the operator decides on the verification page) and then
 * approved -> redeemed (the token endpoint claims it). Redemption is a compare-and-swap so a
 * repeated or concurrent poll cannot mint a second grant from one approval.
 */
final class DeviceCodeRepository implements DeviceCodeStore, PendingDeviceCodeStore
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    public const STATUS_REDEEMED = 'redeemed';

    /**
     * A row nobody can act on any more is worthless, and the only thing that keeps the table from
     * growing on unauthenticated requests alone is deleting it. One hour past expiry leaves room for
     * a late poll to still be answered `expired_token` rather than `invalid_grant`.
     */
    public const EXPIRED_RETENTION = 3_600;

    /**
     * Returns false when the row was not written. The caller must treat that as a failed
     * authorization: a device code the table never accepted can never be approved, so answering with
     * one would leave the client polling a grant that does not exist.
     */
    public function create(
        string $device_code,
        string $user_code,
        string $client_id,
        string $scopes,
        int $expires_in,
    ): bool {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $inserted = $wpdb->insert($wpdb->prefix . 'novamira_oauth_device_codes', [
            'device_code_hash' => hash('sha256', $device_code),
            'user_code_hash' => hash('sha256', $user_code),
            'client_id' => $client_id,
            'user_id' => 0,
            'scopes' => $scopes,
            'status' => self::STATUS_PENDING,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $expires_in),
            'last_polled_at' => null,
        ]);
        return $inserted === 1;
    }

    /**
     * Live pending authorizations, site-wide or for one client. Rows past their expiry are excluded
     * so a burst that has already died cannot hold the caps closed against a legitimate login.
     */
    public function count_pending(?string $client_id = null): int
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'novamira_oauth_device_codes';
        $now = gmdate('Y-m-d H:i:s');
        $sql = $client_id === null
            // @mago-expect analysis:possibly-invalid-argument
            ? $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE status = %s AND expires_at > %s",
                self::STATUS_PENDING,
                $now,
            )
            // @mago-expect analysis:possibly-invalid-argument
            : $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE status = %s AND expires_at > %s AND client_id = %s",
                self::STATUS_PENDING,
                $now,
                $client_id,
            );
        return is_string($sql) ? (int) $wpdb->get_var($sql) : 0;
    }

    /** Drops rows that expired more than EXPIRED_RETENTION ago, whatever they were decided to be. */
    public function prune_expired(): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'novamira_oauth_device_codes';
        // @mago-expect analysis:possibly-invalid-argument
        $sql = $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE expires_at < %s",
            gmdate('Y-m-d H:i:s', time() - self::EXPIRED_RETENTION),
        );
        if (is_string($sql)) {
            $wpdb->query($sql);
        }
    }

    /**
     * @return array{
     *     device_code_hash: string,
     *     client_id: string,
     *     user_id: int,
     *     scopes: string,
     *     status: string,
     *     expires_at: string,
     *     last_polled_at: string|null,
     * }|null
     */
    public function find_by_device_code(string $device_code): ?array
    {
        return $this->find('device_code_hash', hash('sha256', $device_code));
    }

    /**
     * @return array{
     *     device_code_hash: string,
     *     client_id: string,
     *     user_id: int,
     *     scopes: string,
     *     status: string,
     *     expires_at: string,
     *     last_polled_at: string|null,
     * }|null
     */
    public function find_by_user_code(string $user_code): ?array
    {
        return $this->find('user_code_hash', hash('sha256', $user_code));
    }

    /**
     * @return array{
     *     device_code_hash: string,
     *     client_id: string,
     *     user_id: int,
     *     scopes: string,
     *     status: string,
     *     expires_at: string,
     *     last_polled_at: string|null,
     * }|null
     */
    private function find(string $column, string $value): ?array
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'novamira_oauth_device_codes';
        // @mago-expect analysis:possibly-invalid-argument
        $sql = $wpdb->prepare("SELECT device_code_hash, client_id, user_id, scopes, status, expires_at, last_polled_at
             FROM `{$table}` WHERE `{$column}` = %s", $value);
        if (!is_string($sql)) {
            return null;
        }
        // @mago-expect analysis:non-existent-constant
        // @mago-expect analysis:mixed-argument
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return [
            'device_code_hash' => (string) $row['device_code_hash'],
            'client_id' => (string) $row['client_id'],
            'user_id' => (int) $row['user_id'],
            'scopes' => (string) $row['scopes'],
            'status' => (string) $row['status'],
            'expires_at' => (string) $row['expires_at'],
            'last_polled_at' => $row['last_polled_at'] === null ? null : (string) $row['last_polled_at'],
        ];
    }

    public function is_expired(string $expires_at): bool
    {
        // An unreadable timestamp counts as expired: a row we cannot date must not authorize. The
        // deadline itself is expired — a code lives *until* expires_at, not through the second it
        // names — so the boundary second cannot be raced into an approval.
        $deadline = strtotime($expires_at . ' UTC');
        return $deadline === false || $deadline <= time();
    }

    /**
     * Record this poll so the next one can be measured against the advertised interval. Kept
     * separate from redemption: a client that polls too fast is slowed, not denied.
     */
    public function touch_polled(string $device_code_hash): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->update(
            $wpdb->prefix . 'novamira_oauth_device_codes',
            ['last_polled_at' => gmdate('Y-m-d H:i:s')],
            ['device_code_hash' => $device_code_hash],
        );
    }

    /**
     * Approve or deny a pending authorization. The compare-and-swap on the pending status means a
     * decision is recorded once: a second approval of an already-redeemed code changes nothing.
     */
    // @mago-expect lint:no-boolean-flag-parameter
    public function decide(string $device_code_hash, bool $approved, int $user_id): bool
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $updated = $wpdb->update(
            $wpdb->prefix . 'novamira_oauth_device_codes',
            [
                'status' => $approved ? self::STATUS_APPROVED : self::STATUS_DENIED,
                'user_id' => $approved ? $user_id : 0,
            ],
            ['device_code_hash' => $device_code_hash, 'status' => self::STATUS_PENDING],
        );
        return $updated === 1;
    }

    /**
     * Claim an approved authorization for a single token issuance. Serialized by InnoDB's row lock,
     * so only the first poll after approval wins and a replayed device code mints nothing.
     */
    public function claim(string $device_code_hash): bool
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $claimed = $wpdb->update(
            $wpdb->prefix . 'novamira_oauth_device_codes',
            ['status' => self::STATUS_REDEEMED],
            ['device_code_hash' => $device_code_hash, 'status' => self::STATUS_APPROVED],
        );
        return $claimed === 1;
    }
}
