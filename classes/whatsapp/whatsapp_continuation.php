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

use local_jedomi\messaging\continuation;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Continuation of the WhatsApp channel: a self-ping to whatsapp_webhook.php?internal=1, signed with the internal
 * secret of the plugin (see internal_ping), that runs the next leg of a long question in a new request.
 *
 * The chat key goes in the signed body, never in the address, so web server logs do not keep the BSUID.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_continuation implements continuation {
    #[\Override]
    public function schedule(string $chatkey, int $sessiongen): void {
        $request = $this->request($chatkey, $sessiongen);
        $curl = $this->new_curl();
        $curl->setHeader($request['headers']);
        $curl->post($request['url'], $request['body'], $request['options']);
        if (!empty($curl->errno) || (string) $curl->error !== '') {
            \local_jedomi_log('[WHATSAPP SELF-PING] cURL error', ['errno' => (int) $curl->errno,
                'chat' => \local_jedomi_log_pseudonym('wa', $chatkey)]);
        }
    }

    /**
     * Request of the self-ping of a chat.
     *
     * @param string $chatkey Chat key (wa_ plus the BSUID).
     * @param int $sessiongen Session generation of the tool loop.
     * @return array{url: string, body: string, headers: string[], options: array}
     */
    public function request(string $chatkey, int $sessiongen): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $body = json_encode([
            'chatkey' => $chatkey,
            'session_gen' => $sessiongen,
            'time' => \core\di::get(\core\clock::class)->time(),
        ]);
        return [
            // The address is the site itself, built from wwwroot only: the cURL security of the site, which blocks
            // private and local addresses by default, would stop the ping of a site on such an address.
            'url' => $CFG->wwwroot . '/local/jedomi/whatsapp_webhook.php?internal=1',
            'body' => $body,
            'headers' => [
                'Content-Type: application/json',
                internal_ping::SIGNATURE_HEADER . ': ' . internal_ping::sign($body),
            ],
            'options' => [
                'CURLOPT_TIMEOUT' => 5,
                'CURLOPT_SSL_VERIFYPEER' => true,
                'CURLOPT_SSL_VERIFYHOST' => 2,
                'CURLOPT_FOLLOWLOCATION' => false,
                'CURLOPT_MAXREDIRS' => 0,
            ],
        ];
    }

    /**
     * New cURL client of the self-ping.
     *
     * @return \curl
     */
    protected function new_curl(): \curl {
        return new \curl(['ignoresecurity' => true]);
    }
}
