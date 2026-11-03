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
 * General tab of the Jedomi settings: link to the AI service settings in the provider and the general form.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class general_tab extends form_tab {
    /** @var string URL of the Jedomi settings page in the Datacurso AI provider. */
    protected string $providerurl;

    /**
     * Store the provider link and the rendered form.
     *
     * @param string $providerurl URL of the Jedomi settings page in the Datacurso AI provider.
     * @param string $formhtml HTML of the general settings form.
     */
    public function __construct(string $providerurl, string $formhtml) {
        parent::__construct($formhtml);
        $this->providerurl = $providerurl;
    }

    /**
     * Export the provider link and the form for the template.
     *
     * @param \renderer_base $output Renderer used to render the tab.
     * @return array{provider: array{url: string}, form: string}
     */
    public function export_for_template(\renderer_base $output): array {
        return ['provider' => ['url' => $this->providerurl]] + parent::export_for_template($output);
    }

    /**
     * Template of the tab.
     *
     * @param \renderer_base $renderer Renderer used to render the tab.
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'local_jedomi/admin/general_tab';
    }
}
