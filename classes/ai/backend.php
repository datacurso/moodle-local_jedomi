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
 * Chat completion backend contract.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

/**
 * A backend runs one chat completion round with OpenAI-style function calling.
 *
 * Result shapes (the "type" key selects the shape):
 * - text:       ['type' => 'text', 'content' => string]
 * - tool_calls: ['type' => 'tool_calls', 'tool_calls' => list<['id','type','function' => ['name','arguments']]>]
 * - error:      ['type' => 'error', 'error' => string, 'http_code' => int, 'curl_errno' => int,
 *                'curl_error' => string, 'preview' => string] (technical, not for end users)
 * - notice:     ['type' => 'notice', 'message' => string] (localised, safe to show to the user)
 */
interface backend {
    /** @var string Plain assistant answer. */
    public const TYPE_TEXT = 'text';

    /** @var string The model requested one or more tool executions. */
    public const TYPE_TOOL_CALLS = 'tool_calls';

    /** @var string Technical failure (transport, HTTP status, malformed stream). */
    public const TYPE_ERROR = 'error';

    /** @var string User-facing condition such as an exhausted budget or an invalid license. */
    public const TYPE_NOTICE = 'notice';

    /**
     * Stable identifier of the backend (see client::BACKEND_*).
     *
     * @return string
     */
    public function get_key(): string;

    /**
     * Run one completion round.
     *
     * @param string $model Model name; backends may ignore or override it.
     * @param array $messages OpenAI chat messages.
     * @param array $tools OpenAI tool definitions; tool_choice "auto" is sent when non-empty.
     * @param array $options Supported keys: max_tokens (int), timeout (int seconds, default 180),
     *                       temperature (float), extra (array merged into the payload by backends that allow it).
     * @return array One of the result shapes documented on the interface.
     */
    public function complete(string $model, array $messages, array $tools = [], array $options = []): array;
}
