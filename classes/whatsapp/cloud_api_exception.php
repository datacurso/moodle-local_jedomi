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

namespace local_jedomi\whatsapp;

/**
 * Failure of a call to the WhatsApp Cloud API, with the HTTP status and the error code of Meta.
 *
 * The callers decide on the codes (for example 404 on an expired media address, 190 on a revoked token). The text is
 * already redacted by the client: it never holds the access token nor the recipient.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cloud_api_exception extends \moodle_exception {
    /** @var int HTTP status of the answer; 0 when no answer arrived. */
    private int $httpcode;

    /** @var int Error code of Meta (error.code); 0 when the answer had none. */
    private int $metacode;

    /**
     * Failure of a call.
     *
     * @param int $httpcode HTTP status; 0 when no answer arrived.
     * @param int $metacode Error code of Meta; 0 when there is none.
     * @param string $detail Redacted description of the failure.
     */
    public function __construct(int $httpcode, int $metacode, string $detail) {
        $this->httpcode = $httpcode;
        $this->metacode = $metacode;
        $text = 'WhatsApp Cloud API error: http=' . $httpcode . ' code=' . $metacode . ' ' . $detail;
        parent::__construct('generalexceptionmessage', 'error', '', $text);
    }

    /**
     * HTTP status of the answer; 0 when no answer arrived.
     *
     * @return int
     */
    public function get_http_code(): int {
        return $this->httpcode;
    }

    /**
     * Error code of Meta (error.code); 0 when the answer had none.
     *
     * @return int
     */
    public function get_meta_code(): int {
        return $this->metacode;
    }
}
