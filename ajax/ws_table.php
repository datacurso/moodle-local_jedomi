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
 * AJAX endpoint — returns WS allowlist table rows + pagination as JSON.
 *
 * Called from admin_settings.php JS when filters / pagination / type pills change.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

header('Content-Type: application/json; charset=utf-8');

try {
    require_login();
    require_sesskey();
    $context = context_system::instance();
    require_capability('moodle/site:config', $context);

    // ── Read params ──────────────────────────────────────────────────────────
    $role      = optional_param('role', 'admin', PARAM_ALPHA);
    $valid_roles = ['admin', 'manager', 'teacher', 'student'];
    if (!in_array($role, $valid_roles, true)) {
        $role = 'admin';
    }

    $page      = max(1, optional_param('page', 1, PARAM_INT));
    $search    = optional_param('search', '', PARAM_TEXT);
    $component = optional_param('component', '', PARAM_ALPHAEXT);
    $type      = optional_param('type', 'all', PARAM_ALPHA);
    $type      = in_array($type, ['all', 'Lectura', 'Escritura'], true) ? $type : 'all';
    $perpage   = max(10, min(200, optional_param('perpage', 25, PARAM_INT)));

    // ── Build SQL WHERE (search + component only; type is PHP-post-filtered) ─
    $where  = [];
    $params = [];
    if (!empty($search)) {
        $where[] = $DB->sql_like('ef.name', ':ap_search', false, false);
        $params['ap_search'] = '%' . $DB->sql_like_escape($search) . '%';
    }
    if (!empty($component)) {
        $where[] = 'ef.component = :ap_component';
        $params['ap_component'] = $component;
    }
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Load all matching WS ─────────────────────────────────────────────────
    $all_rows = $DB->get_records_sql(
        "SELECT ef.name, '' AS description, ef.component
           FROM {external_functions} ef
           $where_sql
       ORDER BY ef.component, ef.name",
        $params
    );

    // ── Classify + filter by type ────────────────────────────────────────────
    $filtered = [];
    foreach ($all_rows as $ws) {
        $t = local_jedomi_classify_ws_access($ws->name);
        if ($type !== 'all' && $t !== $type) {
            continue;
        }
        $filtered[] = (object) [
            'name'        => $ws->name,
            'description' => $ws->description ?? '',
            'component'   => $ws->component,
            'type'        => $t,
        ];
    }

    $total  = count($filtered);
    $pages  = max(1, (int) ceil($total / $perpage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perpage;
    $page_ws = array_slice($filtered, $offset, $perpage);

    // ── Load allowlist for checked state ─────────────────────────────────────
    $allowlist   = local_jedomi_get_role_allowlist($role);
    $raw_config  = get_config('local_jedomi', 'ws_allowlist_' . $role);
    $first_visit = ($role === 'admin' && $raw_config === false);

    // ── Render table rows HTML ───────────────────────────────────────────────
    $rows_html = '';
    if (empty($page_ws)) {
        $rows_html .= '<tr><td colspan="5" class="text-center text-muted py-4">'
            . get_string('ws_none_found', 'local_jedomi') . '</td></tr>';
    } else {
        foreach ($page_ws as $ws) {
            $is_write   = ($ws->type === 'Escritura');
            $is_checked = in_array($ws->name, $allowlist) || ($first_visit && !$is_write);
            $row_style  = $is_write ? 'background:#fef2f2;border-left:3px solid #dc2626' : '';

            $rows_html .= '<tr style="' . $row_style . '">';
            $rows_html .= '<td><input type="checkbox" class="ap-ws-check"'
                . ' name="ws_' . $role . '[]" value="' . s($ws->name) . '"'
                . ($is_checked ? ' checked' : '') . '></td>';
            $rows_html .= '<td><code style="font-size:.8125rem">' . s($ws->name) . '</code></td>';
            $rows_html .= '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
                . s(shorten_text($ws->description, 80)) . '</td>';
            $rows_html .= '<td><span class="badge badge-secondary">' . s($ws->component) . '</span></td>';
            $rows_html .= '<td>';
            if ($is_write) {
                $rows_html .= '<span class="badge badge-danger">' . get_string('ws_type_write', 'local_jedomi') . '</span>';
            } else {
                $rows_html .= '<span class="badge badge-success">' . get_string('ws_type_read', 'local_jedomi') . '</span>';
            }
            $rows_html .= '</td></tr>';
        }
    }

    // ── Render pagination HTML ───────────────────────────────────────────────
    $pagination_html = '';
    if ($pages > 1) {
        $pagination_html .= '<nav><ul class="pagination pagination-sm mb-0">';
        for ($p = 1; $p <= $pages; $p++) {
            $active = ($p === $page) ? ' active' : '';
            $pagination_html .= '<li class="page-item' . $active . '">'
                . '<a class="page-link ap-page-link" href="#" data-page="' . $p . '">' . $p . '</a></li>';
        }
        $pagination_html .= '</ul></nav>';
    }

    // ── Pre-calculate type counts ────────────────────────────────────────────
    $count_read  = 0;
    $count_write = 0;
    foreach ($all_rows as $ws) {
        $t = local_jedomi_classify_ws_access($ws->name);
        if ($t === 'Lectura') {
            $count_read++;
        }
        if ($t === 'Escritura') {
            $count_write++;
        }
    }

    // ── Response ─────────────────────────────────────────────────────────────
    echo json_encode([
        'rows'        => $rows_html,
        'pagination'  => $pagination_html,
        'total'       => $total,
        'pages'       => $pages,
        'page'        => $page,
        'count_all'   => count($all_rows),
        'count_read'  => $count_read,
        'count_write' => $count_write,
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}
