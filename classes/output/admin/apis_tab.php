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

use local_jedomi\local\service\admin_settings_service;

/**
 * APIs tab of the Jedomi settings: the web services each role may call through the chat.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apis_tab implements \core\output\named_templatable, \renderable, \templatable {
    /** @var string Role key whose allowlist is shown. */
    protected string $role;
    /** @var array Page of the table from admin_settings_service::get_ws_table(). */
    protected array $table;
    /** @var string[] Components for the component filter. */
    protected array $components;
    /** @var string Current name search. */
    protected string $search;
    /** @var string Current component filter. */
    protected string $component;
    /** @var string Current type filter. */
    protected string $type;
    /** @var int Current page size. */
    protected int $perpage;

    /**
     * Store the table and the current filters.
     *
     * @param string $role Role key whose allowlist is shown.
     * @param array $table Page of the table from admin_settings_service::get_ws_table().
     * @param string[] $components Components for the component filter.
     * @param string $search Current name search.
     * @param string $component Current component filter; empty for all.
     * @param string $type Current type filter, one of admin_settings_service::WS_TYPES.
     * @param int $perpage Current page size.
     */
    public function __construct(
        string $role,
        array $table,
        array $components,
        string $search,
        string $component,
        string $type,
        int $perpage
    ) {
        $this->role = $role;
        $this->table = $table;
        $this->components = $components;
        $this->search = $search;
        $this->component = $component;
        $this->type = $type;
        $this->perpage = $perpage;
    }

    /**
     * Pagination data shared by the page and the table refreshed through AJAX.
     *
     * @param int $page Current page.
     * @param int $pages Number of pages.
     * @param int $total Number of functions that match the filters.
     * @return array{page: int, pages: int, total: int, pagelinks: array<int, array{page: int, active: bool}>}
     */
    public static function export_pagination(int $page, int $pages, int $total): array {
        $links = [];
        for ($p = 1; $p <= $pages; $p++) {
            $links[] = ['page' => $p, 'active' => $p === $page];
        }
        return ['page' => $page, 'pages' => $pages, 'total' => $total, 'pagelinks' => $links];
    }

    /**
     * Export the pills, the filters, the rows and the pagination for the template.
     *
     * @param \renderer_base $output Renderer used to render the tab.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $roles = [];
        foreach (admin_settings_service::WS_ROLES as $key) {
            $url = new \moodle_url('/local/jedomi/admin_settings.php', [
                'tab' => 'apis', 'role' => $key, 'ap_page' => 1, 'ap_search' => $this->search,
                'ap_component' => $this->component, 'ap_type' => $this->type,
            ]);
            $roles[] = ['key' => $key, 'label' => get_string('api_role_' . $key, 'local_jedomi'), 'url' => $url->out(false),
                'active' => $key === $this->role];
        }

        $counts = ['all' => $this->table['count_all'], 'Lectura' => $this->table['count_read'],
            'Escritura' => $this->table['count_write']];
        $labels = ['all' => 'ws_type_all', 'Lectura' => 'ws_type_read', 'Escritura' => 'ws_type_write'];
        $types = [];
        foreach (admin_settings_service::WS_TYPES as $type) {
            $types[] = ['type' => $type, 'label' => get_string($labels[$type], 'local_jedomi'), 'count' => (int) $counts[$type],
                'active' => $type === $this->type];
        }

        $components = [];
        foreach ($this->components as $component) {
            $components[] = ['value' => $component, 'selected' => $component === $this->component];
        }
        $perpageoptions = [];
        foreach (admin_settings_service::WS_PERPAGE_OPTIONS as $option) {
            $perpageoptions[] = ['value' => $option, 'selected' => $option === $this->perpage];
        }

        return [
            'role' => $this->role,
            'actionurl' => (new \moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'apis', 'role' => $this->role]))
                ->out(false),
            'sesskey' => sesskey(),
            'roles' => $roles,
            'types' => $types,
            'search' => $this->search,
            'components' => $components,
            'perpageoptions' => $perpageoptions,
            'rows' => array_values($this->table['rows']),
            'hasrows' => !empty($this->table['rows']),
            'pagination' => self::export_pagination($this->table['page'], $this->table['pages'], $this->table['total']),
        ];
    }

    /**
     * Template of the tab.
     *
     * @param \renderer_base $renderer Renderer used to render the tab.
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'local_jedomi/admin/apis_tab';
    }
}
