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

/**
 * Provider client that talks to a real AI service at a given address, for the opt-in evaluation tests.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

defined('MOODLE_INTERNAL') || die();

// The parent class extends a class from aiprovider_datacurso; without that plugin the evaluation tests skip.
if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
    return;
}

/**
 * Real provider client stack pointed at an AI service address chosen by the tester (for example a local container).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_provider_api extends provider_api {
    /**
     * Constructor.
     *
     * @param int $timeout Total request timeout in seconds.
     * @param string $baseurl Address of the AI service, without a trailing slash.
     */
    public function __construct(int $timeout, string $baseurl) {
        parent::__construct($timeout);
        $this->baseurl = rtrim($baseurl, '/');
    }

    /**
     * Jedomi AI client that sends its requests to the AI service at the given address.
     *
     * @param string $baseurl Address of the AI service.
     * @return client
     */
    public static function make_client(string $baseurl): client {
        return new client(static fn(int $timeout): provider_api => new live_provider_api($timeout, $baseurl));
    }

    #[\Override]
    public function is_for_ue(): bool {
        // The address is given explicitly, so the region of the licence is not resolved with the shop.
        return false;
    }

    #[\Override]
    protected function create_transport(): \curl {
        // A local service (container, localhost) is in Moodle's blocked hosts list by default.
        return new \curl(['ignoresecurity' => true]);
    }
}
