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

namespace local_jedomi\hook;

/**
 * Lets the module of an activity give the Jedomi tutor its mode and teaching context.
 *
 * local_jedomi dispatches it when it resolves the tutor mode and when it builds the system message. The listener of
 * the activity's module sets the mode the activity asks for (inherit, socratic or guided) and adds plain-text context
 * lines (for example the object and the parts of a 3D activity). Moodle hooks return nothing: listeners use the
 * setters, local_jedomi reads the result.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Lets an activity module set the Jedomi tutor mode and add teaching context for one of its activities.')]
#[\core\attribute\tags('local_jedomi', 'tutor')]
final class activity_context {
    /** @var string[] Modes a module can ask for. */
    public const MODES = ['inherit', 'socratic', 'guided'];

    /** @var string|null Mode set by the module, or null when the module sets none. */
    private ?string $mode = null;

    /** @var string[] Context lines added by the module. */
    private array $context = [];

    /**
     * Constructor.
     *
     * @param \cm_info $cm Activity the tutor works in.
     * @param int $userid User talking to the tutor.
     * @param array<string, string> $client State the activity page reported (for example the selected part), already
     *     reduced by local_jedomi to short plain strings; untrusted, so the module checks it against its own data.
     */
    public function __construct(
        /** @var \cm_info Activity the tutor works in. */
        public readonly \cm_info $cm,
        /** @var int User talking to the tutor. */
        public readonly int $userid,
        /** @var array<string, string> State the activity page reported, untrusted. */
        public readonly array $client = [],
    ) {
    }

    /**
     * Sets the tutor mode of the activity.
     *
     * @param string $mode One of MODES; inherit means the site default.
     * @throws \coding_exception When the mode is not known.
     */
    public function set_mode(string $mode): void {
        if (!in_array($mode, self::MODES, true)) {
            throw new \coding_exception('Unknown tutor mode: ' . $mode);
        }
        $this->mode = $mode;
    }

    /**
     * Mode set by the module.
     *
     * @return string|null One of MODES, or null.
     */
    public function get_mode(): ?string {
        return $this->mode;
    }

    /**
     * Adds plain-text context for the tutor.
     *
     * @param string $text Context; empty text is ignored.
     */
    public function add_context(string $text): void {
        $text = trim($text);
        if ($text !== '') {
            $this->context[] = $text;
        }
    }

    /**
     * Context lines added by the module, in order.
     *
     * @return string[]
     */
    public function get_context(): array {
        return $this->context;
    }
}
