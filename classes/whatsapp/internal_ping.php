<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_jedomi\whatsapp;

use local_jedomi\local\secret_store;
use local_jedomi\local\user_language;
use local_jedomi\messaging\conversation_engine;
use local_jedomi\messaging\http_responder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Internal self-ping of the WhatsApp channel (whatsapp_webhook.php?internal=1): the next leg of a long question.
 *
 * The body is JSON with the chat key, the session generation and the time it was signed; it is signed with an HMAC
 * (X-Jedomi-Signature: sha256=<hex>) under a secret the plugin generates for this, never with the App Secret of Meta.
 * A ping is accepted only when its signature matches, it is at most MAX_AGE seconds old on the Moodle clock and its
 * chat key is a WhatsApp one; it is acknowledged at once and the tool loop of the chat is resumed by the conversation
 * engine, in the language of the linked user.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class internal_ping {
    /** @var string Header of the signature. */
    public const SIGNATURE_HEADER = 'X-Jedomi-Signature';

    /** @var string Server key of the signature header. */
    public const SERVER_KEY = 'HTTP_X_JEDOMI_SIGNATURE';

    /** @var int Seconds a signed ping is accepted. */
    public const MAX_AGE = 5 * MINSECS;

    /** @var string Setting of the internal secret. */
    private const SECRET = 'whatsapp_internal_secret';

    /** @var string Chat key of a BSUID. */
    private const CHATKEY_PATTERN = '/^wa_[A-Z]{2}\.(ENT\.)?[A-Za-z0-9]{1,128}$/D';

    /**
     * Internal secret of the self-ping, generated (256 bits, saved encrypted) the first time it is needed.
     *
     * @return string
     */
    public static function secret(): string {
        return secret_store::get_or_generate(self::SECRET, 32);
    }

    /**
     * Signature of a body.
     *
     * @param string $body Raw body.
     * @return string sha256=<hex>
     */
    public static function sign(string $body): string {
        return 'sha256=' . hash_hmac('sha256', $body, self::secret());
    }

    /**
     * Whether a signature matches a body.
     *
     * @param string $body Raw body.
     * @param string|null $signature Signature header; null when missing.
     * @return bool
     */
    public static function verify(string $body, ?string $signature): bool {
        return $signature !== null && $signature !== '' && hash_equals(self::sign($body), $signature);
    }

    /**
     * Handle an internal ping.
     *
     * @param string $method HTTP method.
     * @param string $rawbody Raw body.
     * @param string|null $signature Signature header; null when missing.
     */
    public function handle(string $method, string $rawbody, ?string $signature): void {
        $responder = \core\di::get(http_responder::class);
        if ($method !== 'POST') {
            $responder->send(405, ['Allow: POST', 'Content-Type: text/plain; charset=utf-8'], '');
            return;
        }
        $ping = self::read($rawbody, $signature);
        if ($ping === null) {
            \local_jedomi_log('WhatsApp webhook: internal ping refused');
            $responder->send(403, ['Content-Type: application/json'], '');
            return;
        }
        $responder->send_and_close(200, http_responder::closing_headers('application/json', ''), '', true);

        [$chatkey, $sessiongen] = $ping;
        $channel = new whatsapp_channel();
        user_language::run($channel->linked_userid($chatkey), static function () use ($channel, $chatkey, $sessiongen): void {
            (new conversation_engine($channel))->resume($chatkey, $sessiongen);
        });
    }

    /**
     * Chat key and session generation of a valid ping.
     *
     * @param string $rawbody Raw body.
     * @param string|null $signature Signature header.
     * @return array{0: string, 1: int}|null Null when the ping is not valid.
     */
    private static function read(string $rawbody, ?string $signature): ?array {
        if (!self::verify($rawbody, $signature)) {
            return null;
        }
        $data = json_decode($rawbody, true);
        if (
            !is_array($data) || !is_string($data['chatkey'] ?? null) || !is_int($data['session_gen'] ?? null)
                || !is_int($data['time'] ?? null)
        ) {
            return null;
        }
        if (abs(\core\di::get(\core\clock::class)->time() - $data['time']) > self::MAX_AGE) {
            return null;
        }
        if (preg_match(self::CHATKEY_PATTERN, $data['chatkey']) !== 1) {
            return null;
        }
        return [$data['chatkey'], $data['session_gen']];
    }
}
