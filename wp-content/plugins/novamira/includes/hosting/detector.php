<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Hosting;

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit();
}

/**
 * Detect hosting environments whose edge security can reject machine-to-machine OAuth traffic.
 *
 * Detection intentionally uses runtime markers instead of reverse DNS or a remote lookup. Hosting
 * providers can be extended through the novamira_detected_hosting filter without changing the
 * configuration UI.
 */
// A single registry coordinates small provider-specific matchers so their precedence stays explicit.
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:kan-defect
// @mago-expect lint:too-many-methods
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class Detector
{
    /**
     * Detect the current hosting environment.
     *
     * @return array{id: string, name: string, recommendation: 'oauth'|'password', edge: string}|null
     */
    public static function current(): ?array
    {
        $signals = self::runtime_signals();
        $detected = self::detect($signals);

        /** @var mixed $filtered */
        $filtered = apply_filters('novamira_detected_hosting', $detected, $signals);
        return self::normalize_detection($filtered);
    }

    /**
     * Match a normalized set of runtime signals. Public so detection remains independently testable.
     *
     * @param array{hostname?: string, server?: array<string, string>, constants?: list<string>, mu_plugins?: list<string>, functions?: array<string, bool>, files?: list<string>} $signals
     * @return array{id: string, name: string, recommendation: 'oauth'|'password', edge: string}|null
     */
    public static function detect(array $signals): ?array
    {
        foreach ([
            self::detect_kinsta($signals),
            self::detect_wp_engine($signals),
            self::detect_hostinger($signals),
            self::detect_siteground($signals),
            self::detect_pantheon($signals),
            self::detect_cloudflare($signals),
        ] as $detected) {
            if ($detected !== null) {
                return $detected;
            }
        }

        return null;
    }

    /**
     * Collect only the narrow runtime facts needed by detect().
     *
     * @return array{hostname: string, server: array<string, string>, constants: list<string>, mu_plugins: list<string>, functions: array<string, bool>, files: list<string>}
     */
    private static function runtime_signals(): array
    {
        $hostname = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        $server = [];
        foreach ([
            'KINSTA_CACHE_ZONE',
            'PANTHEON_ENVIRONMENT',
            'H_PLATFORM',
            'HTTP_CF_RAY',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CF_IPCOUNTRY',
        ] as $key) {
            $value = $_SERVER[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $server[$key] = $value;
        }

        $constants = [];
        foreach (['KINSTA_CACHE_ZONE', 'KINSTAMU_CUSTOM_MUPLUGIN_URL', 'PANTHEON_ENVIRONMENT'] as $constant) {
            if (!defined($constant)) {
                continue;
            }
            $constants[] = $constant;
        }

        $mu_plugin_dir = self::mu_plugin_dir();
        $mu_plugins = [];
        if ($mu_plugin_dir !== '') {
            foreach ([
                $mu_plugin_dir . '/kinsta-mu-plugins.php',
                $mu_plugin_dir . '/kinsta-mu-plugins/kinsta-mu-plugins.php',
                $mu_plugin_dir . '/wpengine-common/plugin.php',
            ] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $mu_plugins[] = $path;
            }
        }

        $is_wpe = false;
        if (function_exists('is_wpe')) {
            $is_wpe = in_array((string) call_user_func('is_wpe'), ['1'], strict: true);
        }

        $files = [];
        // SiteGround's own WordPress plugins use these two files together as their hosting check.
        // Avoid probing outside open_basedir, which would emit warnings on restricted hosts.
        if ((string) ini_get('open_basedir') === '') {
            foreach (['/etc/yum.repos.d/baseos.repo', '/Z'] as $path) {
                if (!file_exists($path)) {
                    continue;
                }
                $files[] = $path;
            }
        }

        return [
            'hostname' => $hostname,
            'server' => $server,
            'constants' => $constants,
            'mu_plugins' => $mu_plugins,
            // WP Engine documents is_wpe() as its canonical runtime detector. It returns "1"/"0".
            'functions' => ['is_wpe' => $is_wpe],
            'files' => $files,
        ];
    }

    private static function mu_plugin_dir(): string
    {
        if (defined('WPMU_PLUGIN_DIR')) {
            return (string) constant('WPMU_PLUGIN_DIR');
        }
        if (!defined('WP_CONTENT_DIR')) {
            return '';
        }
        return (string) constant('WP_CONTENT_DIR') . '/mu-plugins';
    }

    /**
     * @param array{hostname?: string, server?: array<string, string>, constants?: list<string>, mu_plugins?: list<string>, functions?: array<string, bool>, files?: list<string>} $signals
     * @return array{id: string, name: string, recommendation: 'oauth'|'password', edge: string}|null
     */
    private static function detect_kinsta(array $signals): ?array
    {
        $hostname = strtolower($signals['hostname'] ?? '');
        $server = $signals['server'] ?? [];
        $constants = $signals['constants'] ?? [];
        $mu_plugins = $signals['mu_plugins'] ?? [];

        $detected =
            self::hostname_ends_with($hostname, '.kinsta.cloud')
            || self::hostname_ends_with($hostname, '.kinsta.com')
            || array_key_exists('KINSTA_CACHE_ZONE', $server)
            || in_array('KINSTA_CACHE_ZONE', $constants, strict: true)
            || in_array('KINSTAMU_CUSTOM_MUPLUGIN_URL', $constants, strict: true)
            || self::paths_contain($mu_plugins, 'kinsta-mu-plugins');

        return (
            $detected
                ? ['id' => 'kinsta', 'name' => 'Kinsta', 'recommendation' => 'password', 'edge' => 'Cloudflare']
                : null
        );
    }

    /**
     * @param array{hostname?: string, server?: array<string, string>, constants?: list<string>, mu_plugins?: list<string>, functions?: array<string, bool>, files?: list<string>} $signals
     * @return array{id: string, name: string, recommendation: 'oauth'|'password', edge: string}|null
     */
    private static function detect_wp_engine(array $signals): ?array
    {
        $hostname = strtolower($signals['hostname'] ?? '');
        $functions = $signals['functions'] ?? [];
        $mu_plugins = $signals['mu_plugins'] ?? [];
        $detected =
            ($functions['is_wpe'] ?? false)
            || self::hostname_ends_with($hostname, '.wpenginepowered.com')
            || self::paths_contain($mu_plugins, 'wpengine-common');

        return (
            $detected
                ? [
                    'id' => 'wp-engine',
                    'name' => 'WP Engine',
                    'recommendation' => 'password',
                    'edge' => 'WP Engine proprietary firewall',
                ]
                : null
        );
    }

    /**
     * @param array{hostname?: string, server?: array<string, string>, constants?: list<string>, mu_plugins?: list<string>, functions?: array<string, bool>, files?: list<string>} $signals
     * @return array{id: string, name: string, recommendation: 'oauth'|'password', edge: string}|null
     */
    private static function detect_hostinger(array $signals): ?array
    {
        $hostname = strtolower($signals['hostname'] ?? '');
        $server = $signals['server'] ?? [];
        $detected =
            array_key_exists('H_PLATFORM', $server)
            || self::hostname_ends_with($hostname, '.hostingersite.com')
            || self::hostname_ends_with($hostname, '.hostingersite.dev');

        return (
            $detected
                ? [
                    'id' => 'hostinger',
                    'name' => 'Hostinger',
                    'recommendation' => 'password',
                    'edge' => 'Hostinger automated firewall',
                ]
                : null
        );
    }

    /**
     * @param array{hostname?: string, server?: array<string, string>, constants?: list<string>, mu_plugins?: list<string>, functions?: array<string, bool>, files?: list<string>} $signals
     * @return array{id: string, name: string, recommendation: 'oauth'|'password', edge: string}|null
     */
    private static function detect_siteground(array $signals): ?array
    {
        $files = $signals['files'] ?? [];
        $detected =
            in_array('/etc/yum.repos.d/baseos.repo', $files, strict: true) && in_array('/Z', $files, strict: true);

        return (
            $detected
                ? [
                    'id' => 'siteground',
                    'name' => 'SiteGround',
                    'recommendation' => 'password',
                    'edge' => 'SiteGround IDS/IPS and custom NGINX security layer',
                ]
                : null
        );
    }

    /**
     * @param array{hostname?: string, server?: array<string, string>, constants?: list<string>, mu_plugins?: list<string>, functions?: array<string, bool>, files?: list<string>} $signals
     * @return array{id: string, name: string, recommendation: 'oauth'|'password', edge: string}|null
     */
    private static function detect_pantheon(array $signals): ?array
    {
        $hostname = strtolower($signals['hostname'] ?? '');
        $server = $signals['server'] ?? [];
        $constants = $signals['constants'] ?? [];
        $detected =
            self::hostname_ends_with($hostname, '.pantheonsite.io')
            || array_key_exists('PANTHEON_ENVIRONMENT', $server)
            || in_array('PANTHEON_ENVIRONMENT', $constants, strict: true);

        return (
            $detected
                ? [
                    'id' => 'pantheon',
                    'name' => 'Pantheon',
                    'recommendation' => 'password',
                    'edge' => 'Pantheon Global CDN and edge security',
                ]
                : null
        );
    }

    /**
     * @param array{hostname?: string, server?: array<string, string>, constants?: list<string>, mu_plugins?: list<string>, functions?: array<string, bool>, files?: list<string>} $signals
     * @return array{id: string, name: string, recommendation: 'oauth'|'password', edge: string}|null
     */
    private static function detect_cloudflare(array $signals): ?array
    {
        $server = $signals['server'] ?? [];
        $detected =
            array_key_exists('HTTP_CF_RAY', $server)
            || array_key_exists('HTTP_CF_CONNECTING_IP', $server)
            || array_key_exists('HTTP_CF_IPCOUNTRY', $server);

        return (
            $detected
                ? [
                    'id' => 'cloudflare',
                    'name' => 'Cloudflare-protected hosting',
                    'recommendation' => 'password',
                    'edge' => 'Cloudflare WAF or bot protection',
                ]
                : null
        );
    }

    private static function hostname_ends_with(string $hostname, string $suffix): bool
    {
        return $hostname !== '' && str_ends_with($hostname, $suffix);
    }

    /**
     * @param list<string> $paths
     */
    private static function paths_contain(array $paths, string $needle): bool
    {
        foreach ($paths as $path) {
            if (str_contains(strtolower($path), strtolower($needle))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{id: string, name: string, recommendation: 'oauth'|'password', edge: string}|null
     */
    private static function normalize_detection(mixed $detected): ?array
    {
        if (!is_array($detected)) {
            return null;
        }

        /** @var mixed $id */
        $id = $detected['id'] ?? null;
        /** @var mixed $name */
        $name = $detected['name'] ?? null;
        /** @var mixed $recommendation */
        $recommendation = $detected['recommendation'] ?? null;
        /** @var mixed $edge */
        $edge = $detected['edge'] ?? null;
        if (
            !is_string($id)
            || $id === ''
            || !is_string($name)
            || $name === ''
            || !in_array($recommendation, ['oauth', 'password'], strict: true)
            || !is_string($edge)
            || $edge === ''
        ) {
            return null;
        }

        return ['id' => $id, 'name' => $name, 'recommendation' => $recommendation, 'edge' => $edge];
    }
}
