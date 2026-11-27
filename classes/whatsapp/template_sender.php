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

namespace local_jedomi\whatsapp;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Sends a template of the catalog to a link, with the name and language of the approved template.
 *
 * Templates are sent inside or outside the 24-hour window. A link that stopped the messages, or whose user reached the
 * monthly limit, gets nothing. Each template sent counts as a template of the month. An error of Meta is logged with
 * its HTTP status and error code and a pseudonym of the chat only.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_sender {
    /**
     * Send a template to a link.
     *
     * @param \stdClass $link Link of the student.
     * @param string $template One of the templates constants.
     * @param array<string, string> $values Value of each datum of the template.
     * @return bool Whether Meta accepted the message.
     * @throws \coding_exception For an unknown template or a missing datum.
     */
    public static function send(\stdClass $link, string $template, array $values): bool {
        $components = templates::components($template, $values);
        $userid = (int) $link->userid;
        if (!empty($link->optedout) || !monthly_cap::allows($userid)) {
            return false;
        }
        try {
            $to = recipient_ref::from_link($link->waid ?? null, (string) ($link->bsuid ?? ''));
            cloud_api_client::instance()->send_template(
                $to,
                manager::get_template_name($template),
                manager::get_template_language(),
                $components
            );
        } catch (\Throwable $e) {
            $context = ['template' => $template, 'chat' => \local_jedomi_log_pseudonym('wa', (string) ($link->bsuid ?? ''))];
            if ($e instanceof cloud_api_exception) {
                $context += ['http' => $e->get_http_code(), 'code' => $e->get_meta_code()];
            } else {
                $context['error'] = \local_jedomi\local\safe_error::describe($e);
            }
            \local_jedomi_log('WhatsApp template not sent', $context);
            return false;
        }
        usage_meter::add($userid, usage_meter::OUTTEMPLATE);
        return true;
    }
}
