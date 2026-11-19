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
 * request_audio() asks the provider for the body of the answer without decoding it (request_raw(), provider
 * 1.8.0), so the request keeps the provider's licence, rate-limit headers, pseudonymised user and error handling.
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

    /**
     * POST a JSON request to a route that answers audio, and return the audio.
     *
     * @param string $path Route of the AI service.
     * @param array $body JSON body; the provider adds the site, the pseudonymised user, the timezone and the language.
     * @return string Audio bytes of the answer.
     * @throws \moodle_exception Provider errors (component aiprovider_datacurso), or ai_error_invalidanswer
     *         when a successful answer has no audio.
     */
    public function request_audio(string $path, array $body): string {
        $audio = $this->request_raw('POST', $path, $body);
        json_decode($audio);
        if ($audio === '' || json_last_error() === JSON_ERROR_NONE) {
            // A JSON answer (or no body at all) carries no audio.
            throw new \moodle_exception('ai_error_invalidanswer', 'local_jedomi');
        }
        return $audio;
    }

    #[\Override]
    protected function create_curl(): \curl {
        $curl = $this->create_transport();
        // While a question waits, the progress of the request sends its signals of life ({@see heartbeat}).
        $curl->setopt(['CURLOPT_TIMEOUT' => $this->timeout] + heartbeat::curl_options());
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
