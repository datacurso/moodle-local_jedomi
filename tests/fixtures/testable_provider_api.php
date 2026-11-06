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
 * Provider client whose transport is a fake cURL wrapper.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

defined('MOODLE_INTERNAL') || die();

// The transport double only needs core, so it stays available to tests that skip without the provider.
require_once(__DIR__ . '/fake_curl.php');

// The parent class provider_api extends a class from aiprovider_datacurso. Without that plugin the class is left
// undeclared, so the tests that need it skip themselves instead of the whole suite failing to load.
if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
    return;
}

/**
 * Real provider client stack with the network replaced by {@see fake_curl}.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_provider_api extends provider_api {
    /** @var fake_curl Transport double. */
    private fake_curl $fakecurl;

    /**
     * Constructor.
     *
     * @param int $timeout Total request timeout in seconds.
     * @param fake_curl $fakecurl Transport double.
     */
    public function __construct(int $timeout, fake_curl $fakecurl) {
        parent::__construct($timeout);
        $this->fakecurl = $fakecurl;
    }

    /**
     * Jedomi AI client whose provider transport is the given fake.
     *
     * @param fake_curl $curl Transport double.
     * @return client
     */
    public static function make_client(fake_curl $curl): client {
        return new client(static fn(int $timeout): provider_api => new testable_provider_api($timeout, $curl));
    }

    #[\Override]
    public function is_for_ue(): bool {
        // The region is a property of the licence; resolving it would ask the shop.
        return false;
    }

    #[\Override]
    protected function create_transport(): \curl {
        return $this->fakecurl;
    }
}
