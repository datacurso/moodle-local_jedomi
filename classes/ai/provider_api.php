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

namespace local_jedomi\ai;

/**
 * Datacurso AI services client with a total request timeout.
 *
 * The provider's \curl wrapper has a connect timeout but no total timeout, and request() takes no
 * options; overriding create_curl() is the only way to bound a call without changing the provider.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_api extends \aiprovider_datacurso\httpclient\ai_services_api {
    /** @var int Total request timeout, in seconds. */
    private int $timeout;

    /**
     * Constructor.
     *
     * @param int $timeout Total request timeout, in seconds.
     */
    public function __construct(int $timeout) {
        parent::__construct();
        $this->timeout = max(1, $timeout);
    }

    #[\Override]
    protected function create_curl(): \curl {
        $curl = $this->create_transport();
        $curl->setopt(['CURLOPT_TIMEOUT' => $this->timeout]);
        return $curl;
    }

    /**
     * Create the bare cURL wrapper. Seam for tests.
     *
     * @return \curl
     */
    protected function create_transport(): \curl {
        return new \curl();
    }
}
