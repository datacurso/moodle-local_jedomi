<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Jedomi Cache Definitions
 *
 * Uses MODE_SESSION so the cache lives exactly as long as the Moodle session.
 * Nothing is persisted: when the user logs out, the data is gone.
 * An admin can never read another user's cache (it is per session).
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'conversation' => [
        'mode'             => cache_store::MODE_SESSION,
        'simplekeys'       => true,
        'simpledata'       => false, // Datos complejos (array de mensajes).
        'staticacceleration' => true, // Keep in memory during the request.
        'staticaccelerationsize' => 1, // Only one active conversation.
        'ttl'              => 0, // 0 = expires with the session (no fixed TTL).
    ],
    // Application-wide cache for Telegram link codes.
    // MUST be MODE_APPLICATION — the Telegram webhook runs in a different HTTP
    // request with no user session, so MODE_SESSION would never find the codes.
    'telegram_link_codes' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true, // Values are simple strings (code or userid).
        'ttl'        => 600, // 10 minutes — same as CODE_TTL in manager.php
    ],

    // Application-wide cache for database schema introspection (table_descriptor).
    // MODE_APPLICATION — DB schema is site-wide, not per-user.
    // TTL 86400 (24h) — schema changes only during upgrades or plugin installs.
    'schema_cache' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 86400, // 24 hours
    ],

    // Requests of each user to read answers aloud in the current minute (voice conversation rate limit).
    // MODE_APPLICATION so the limit holds across sessions and browser tabs of the same user.
    'speech_rate' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 120,
    ],

    // Verifications of the custom AI service made by each administrator in the current minute, so the check cannot be
    // used to scan the network. MODE_APPLICATION so the limit holds across sessions.
    'verify_rate' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 120,
    ],

    // Times of the web chat messages each user sent in the last minute (chat_rate_limit_service, JEDOMI-SEC-006).
    // MODE_APPLICATION so the limit holds across sessions, browser tabs and web servers that share the cache.
    'chat_rate' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true, // A list of timestamps.
        'ttl'        => 120,
    ],

    // Hashes of the signatures of the companion web services already used, so a signed request runs only once.
    // MODE_APPLICATION so a replay is seen by every request. A timestamp is accepted from 60 seconds before to 60 seconds
    // after it, so a signature is kept a little longer than twice that margin.
    'companion_signatures' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 130,
    ],

    // Telegram update ids already received, per bot, so an update Telegram delivers again is not processed twice
    // (SEC-JD-049). MODE_APPLICATION so every web server sees them; Telegram stops retrying an update within a day.
    'telegram_updates' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 86400,
    ],
];
