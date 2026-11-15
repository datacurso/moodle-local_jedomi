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
 * Agents tab of the Jedomi settings: charts, the agents report, the agent form and the purge form.
 *
 * The charts follow the filters of the agents report; they and the row actions are handled by the
 * local_jedomi/admin/agents module.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agents_tab implements \core\output\named_templatable, \renderable, \templatable {
    /** @var array<string, string> Schedules offered in the agent form: cron expression => lang string key. */
    private const SCHEDULES = [
        '*/5 * * * *' => 'agent_every_5min',
        '*/15 * * * *' => 'agent_every_15min',
        '*/30 * * * *' => 'agent_every_30min',
        '0 * * * *' => 'agent_every_1h',
        '0 */6 * * *' => 'agent_every_6h',
        '0 0 * * *' => 'agent_daily',
        '0 0 * * 0' => 'agent_weekly',
        'custom' => 'agent_custom_cron',
    ];

    /** @var string[] Context types an agent can run in. */
    private const CONTEXT_TYPES = ['site', 'course', 'category', 'user'];

    /** @var array Roles of the site, from admin_settings_service::get_roles(). */
    protected array $roles;
    /** @var int Agent to open in the form when the page loads; 0 for none. */
    protected int $editagentid;
    /** @var string HTML of the agents report. */
    protected string $report;

    /**
     * Store the roles, the agent to edit and the agents report.
     *
     * @param array $roles Roles of the site, from admin_settings_service::get_roles().
     * @param int $editagentid Agent to open in the form when the page loads; 0 for none.
     * @param string $report HTML of the agents report.
     */
    public function __construct(array $roles, int $editagentid, string $report) {
        $this->roles = $roles;
        $this->editagentid = $editagentid;
        $this->report = $report;
    }

    /**
     * Export the options of the forms and the agents report for the template.
     *
     * The approved writes offered are the write functions the current user may approve for a new agent; the form loads
     * those of the owner when it edits an agent.
     *
     * @param \renderer_base $output Renderer used to render the tab.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $schedules = [];
        foreach (self::SCHEDULES as $value => $labelkey) {
            $schedules[] = ['value' => $value, 'label' => get_string($labelkey, 'local_jedomi')];
        }
        $contexttypes = [];
        foreach (self::CONTEXT_TYPES as $type) {
            $contexttypes[] = ['value' => $type, 'label' => get_string('agent_context_type_' . $type, 'local_jedomi')];
        }
        $roles = [];
        foreach ($this->roles as $role) {
            $roles[] = ['id' => (int) $role['id'], 'name' => (string) $role['name']];
        }
        global $USER;
        $approvablewrites = [];
        foreach (\local_jedomi\agent\agent_manager::approvable_writes((int) $USER->id) as $function) {
            $approvablewrites[] = ['name' => $function, 'checked' => false];
        }
        return [
            'roles' => $roles,
            'approvablewrites' => $approvablewrites,
            'approvedwriteshelp' => get_string('agent_approved_writes_help', 'local_jedomi', [
                'writes' => \local_jedomi\agent\write_guard::MAX_WRITES_PER_RUN,
                'recipients' => \local_jedomi\agent\write_guard::MAX_RECIPIENTS_PER_RUN,
            ]),
            'schedules' => $schedules,
            'contexttypes' => $contexttypes,
            'editagentid' => $this->editagentid,
            'report' => $this->report,
        ];
    }

    /**
     * Template of the tab.
     *
     * @param \renderer_base $renderer Renderer used to render the tab.
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'local_jedomi/admin/agents_tab';
    }
}
