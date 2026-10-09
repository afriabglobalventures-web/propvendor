<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

if (!defined('ABSPATH')) {
    exit();
}

final class UserEntity implements UserEntityInterface
{
    use EntityTrait;
}

// @mago-expect lint:single-class-per-file
final class UserRepository implements UserRepositoryInterface
{
    // We use the authorization_code + PKCE grant only. The password grant is
    // not enabled, so this method is never called in practice.
    public function getUserEntityByUserCredentials(
        mixed $username,
        mixed $password,
        mixed $grantType,
        ClientEntityInterface $clientEntity,
    ): ?UserEntityInterface {
        return null;
    }
}
