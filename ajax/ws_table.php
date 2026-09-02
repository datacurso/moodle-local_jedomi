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
    $validroles = ['admin', 'manager', 'teacher', 'student'];
    if (!in_array($role, $validroles, true)) {
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
    $wheresql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Load all matching WS ─────────────────────────────────────────────────
    $allrows = $DB->get_records_sql(
        "SELECT ef.name, '' AS description, ef.component
           FROM {external_functions} ef
           $wheresql
       ORDER BY ef.component, ef.name",
        $params
    );

    // ── Classify + filter by type ────────────────────────────────────────────
    $filtered = [];
    foreach ($allrows as $ws) {
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
    $pagews = array_slice($filtered, $offset, $perpage);

    // ── Load allowlist for checked state ─────────────────────────────────────
    $allowlist   = local_jedomi_get_role_allowlist($role);
    $rawconfig  = get_config('local_jedomi', 'ws_allowlist_' . $role);
    $firstvisit = ($role === 'admin' && $rawconfig === false);

    // ── Render table rows HTML ───────────────────────────────────────────────
    $rowshtml = '';
    if (empty($pagews)) {
        $rowshtml .= '<tr><td colspan="5" class="text-center text-muted py-4">'
            . get_string('ws_none_found', 'local_jedomi') . '</td></tr>';
    } else {
        foreach ($pagews as $ws) {
            $iswrite   = ($ws->type === 'Escritura');
            $ischecked = in_array($ws->name, $allowlist) || ($firstvisit && !$iswrite);
            $rowstyle  = $iswrite ? 'background:#fef2f2;border-left:3px solid #dc2626' : '';

            $rowshtml .= '<tr style="' . $rowstyle . '">';
            $rowshtml .= '<td><input type="checkbox" class="ap-ws-check"'
                . ' name="ws_' . $role . '[]" value="' . s($ws->name) . '"'
                . ($ischecked ? ' checked' : '') . '></td>';
            $rowshtml .= '<td><code style="font-size:.8125rem">' . s($ws->name) . '</code></td>';
            $rowshtml .= '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
                . s(shorten_text($ws->description, 80)) . '</td>';
            $rowshtml .= '<td><span class="badge badge-secondary">' . s($ws->component) . '</span></td>';
            $rowshtml .= '<td>';
            if ($iswrite) {
                $rowshtml .= '<span class="badge badge-danger">' . get_string('ws_type_write', 'local_jedomi') . '</span>';
            } else {
                $rowshtml .= '<span class="badge badge-success">' . get_string('ws_type_read', 'local_jedomi') . '</span>';
            }
            $rowshtml .= '</td></tr>';
        }
    }

    // ── Render pagination HTML ───────────────────────────────────────────────
    $paginationhtml = '';
    if ($pages > 1) {
        $paginationhtml .= '<nav><ul class="pagination pagination-sm mb-0">';
        for ($p = 1; $p <= $pages; $p++) {
            $active = ($p === $page) ? ' active' : '';
            $paginationhtml .= '<li class="page-item' . $active . '">'
                . '<a class="page-link ap-page-link" href="#" data-page="' . $p . '">' . $p . '</a></li>';
        }
        $paginationhtml .= '</ul></nav>';
    }

    // ── Pre-calculate type counts ────────────────────────────────────────────
    $countread  = 0;
    $countwrite = 0;
    foreach ($allrows as $ws) {
        $t = local_jedomi_classify_ws_access($ws->name);
        if ($t === 'Lectura') {
            $countread++;
        }
        if ($t === 'Escritura') {
            $countwrite++;
        }
    }

    // ── Response ─────────────────────────────────────────────────────────────
    echo json_encode([
        'rows'        => $rowshtml,
        'pagination'  => $paginationhtml,
        'total'       => $total,
        'pages'       => $pages,
        'page'        => $page,
        'count_all'   => count($allrows),
        'count_read'  => $countread,
        'count_write' => $countwrite,
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}
