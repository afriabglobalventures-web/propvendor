<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Abilities\Gutenberg;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Below this many visible characters a page is too short for a ratio to mean anything.
 */
const CONTENT_LOSS_MIN_TEXT = 200;

/**
 * A candidate keeping less than this share of the live text or media counts as a loss.
 */
const CONTENT_LOSS_MIN_RATIO = 0.5;

/**
 * Removing fewer media items than this is an ordinary edit, not a loss.
 */
const CONTENT_LOSS_MIN_MEDIA = 3;

const CONTENT_MEDIA_TAG_PATTERN = '/<(?:img|video|audio|iframe|source)\b[^>]*>/i';

const CONTENT_MEDIA_SOURCE_PATTERN = '/\ssrc\s*=\s*(?:"[^"\s]|\'[^\'\s]|[^"\'\s>])/i';

/**
 * Length of the text a visitor would read: block delimiters, script and style bodies and tags removed,
 * entities decoded, whitespace collapsed.
 */
function content_visible_text_length(string $content): int
{
    $text = (string) preg_replace('/<!--.*?-->/s', replacement: '', subject: mb_scrub($content, encoding: 'UTF-8'));
    $text = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', replacement: '', subject: $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
    $text = trim((string) preg_replace('/\s+/u', replacement: ' ', subject: $text));

    return mb_strlen($text);
}

/**
 * Media elements that point at a file. Block editors still print the tag when its source attribute is
 * missing, so a tag without a source is not counted.
 */
function content_media_count(string $content): int
{
    return count(array_filter(
        content_media_tags($content),
        static fn(string $tag): bool => preg_match(CONTENT_MEDIA_SOURCE_PATTERN, $tag) === 1,
    ));
}

function content_sourceless_media_count(string $content): int
{
    return count(content_media_tags($content)) - content_media_count($content);
}

/**
 * @return list<string>
 */
function content_media_tags(string $content): array
{
    $matches = [];
    preg_match_all(CONTENT_MEDIA_TAG_PATTERN, $content, $matches);

    return array_values($matches[0]);
}

/**
 * @return array{text_before: int, text_after: int, media_before: int, media_after: int}|null
 */
function content_loss_report(string $live, string $candidate): ?array
{
    $report = [
        'text_before' => content_visible_text_length($live),
        'text_after' => content_visible_text_length($candidate),
        'media_before' => content_media_count($live),
        'media_after' => content_media_count($candidate),
    ];

    $text_lost =
        $report['text_before'] >= CONTENT_LOSS_MIN_TEXT
        && $report['text_after'] < ($report['text_before'] * CONTENT_LOSS_MIN_RATIO);
    $media_removed =
        $report['media_before'] >= CONTENT_LOSS_MIN_MEDIA
        && $report['media_after'] < ($report['media_before'] * CONTENT_LOSS_MIN_RATIO);
    $media_emptied = content_sourceless_media_count($candidate) > content_sourceless_media_count($live);

    return $text_lost || $media_removed || $media_emptied ? $report : null;
}

/**
 * @param array{text_before: int, text_after: int, media_before: int, media_after: int} $report
 */
function content_loss_message(array $report): string
{
    return sprintf(
        'Kept %d of %d text characters and %d of %d media sources; live content unchanged. gutenberg-get-content omits content stored in block markup, so resend it in full. If intended, delete this batch with gutenberg-delete-pending-batch and queue again with allow_content_loss.',
        $report['text_after'],
        $report['text_before'],
        $report['media_after'],
        $report['media_before'],
    );
}
