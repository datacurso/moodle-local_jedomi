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

namespace local_jedomi\output\admin;

/**
 * Base of the admin tabs whose content is a settings form.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class form_tab implements \renderable, \templatable, \core\output\named_templatable {
    /** @var string HTML of the settings form, rendered by moodleform. */
    protected string $formhtml;

    /**
     * Store the rendered form.
     *
     * @param string $formhtml HTML of the settings form, rendered by moodleform::render().
     */
    public function __construct(string $formhtml) {
        $this->formhtml = $formhtml;
    }

    /**
     * Export the form for the template.
     *
     * @param \renderer_base $output Renderer used to render the tab.
     * @return array{form: string}
     */
    public function export_for_template(\renderer_base $output): array {
        return ['form' => $this->formhtml];
    }
}
