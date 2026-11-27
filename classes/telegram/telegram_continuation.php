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
 * Telegram continuation: the self-ping to the Telegram webhook.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\messaging\continuation;

/**
 * The next leg of a long tool loop is a POST of the site to its own Telegram webhook (internal_ping), which answers
 * 200 OK at once and continues the saved loop in a new PHP worker (see webhook_handler::handle_internal_ping()).
 */
class telegram_continuation implements continuation {
    #[\Override]
    public function schedule(string $chatkey, int $sessiongen): void {
        $request = $this->request($chatkey, $sessiongen);
        // The address is the site itself, built from wwwroot only: the cURL security of the site, which blocks private
        // and local addresses by default, would stop the ping of a site on such an address. The proxy still applies.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader($request['headers']);
        $curl->post($request['url'], '{}', $request['options']);

        if (!empty($curl->errno) || (string) $curl->error !== '') {
            \local_jedomi_log('[TELEGRAM SELF-PING] cURL error: ' . $curl->error . ' for chat='
                . \local_jedomi_log_pseudonym('chat', $chatkey));
        }
    }

    /**
     * Address, headers and \curl options of the self-ping to our own webhook.
     *
     * The secret goes in the same header Telegram uses, never in the address, and TLS is verified (JEDOMI-SEC-004).
     * Redirects are not followed, so the secret never reaches another address (JEDOMI-SEC-018).
     *
     * @param string $chatkey Telegram chat id whose tool loop continues.
     * @param int $sessiongen Session generation of the saved loop.
     * @return array ['url' => string, 'headers' => string[], 'options' => array of \curl options keyed by name].
     */
    public function request(string $chatkey, int $sessiongen): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $secret = manager::get_webhook_secret();
        $url = $CFG->wwwroot . '/local/jedomi/telegram_webhook.php?internal_ping=1&chat_id=' . urlencode($chatkey)
            . '&session_gen=' . $sessiongen;
        $options = [
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXREDIRS'      => 0,
        ];
        $headers = ['Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $secret];
        return ['url' => $url, 'headers' => $headers, 'options' => $options];
    }
}
