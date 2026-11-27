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

/**
 * Catalog of the utility templates the WhatsApp channel sends. Outside the 24-hour window Meta accepts only templates
 * approved for the WhatsApp Business Account, and the notifications of Jedomi always use them.
 *
 * Each template has one body variable per datum, in the order of the approved template. The name and the language of
 * the approved template come from the settings (manager::get_template_name(), manager::get_template_language()); the
 * catalog name is the default name.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class templates {
    /** @var string A grade was posted: {{1}} course, {{2}} activity, {{3}} grade. */
    public const GRADE_POSTED = 'grade_posted';

    /** @var string A reply waits because the window closed; any message of the student shows it. No variables. */
    public const REPLY_READY = 'reply_ready';

    /** @var array<string, string[]> Data of each template, in the order of its body variables. */
    public const PARAMETERS = [
        self::GRADE_POSTED => ['course', 'activity', 'grade'],
        self::REPLY_READY => [],
    ];

    /** @var int Characters kept of a parameter. */
    public const PARAMETER_LENGTH = 200;

    /** @var string Text of a parameter without text: Meta refuses empty parameters. */
    private const EMPTY_PARAMETER = '-';

    /**
     * Whether a template is in the catalog.
     *
     * @param string $template Template.
     * @return bool
     */
    public static function is_known(string $template): bool {
        return array_key_exists($template, self::PARAMETERS);
    }

    /**
     * Components of a template message: one body text parameter per datum, in the order of the template; none for a
     * template without variables.
     *
     * Meta refuses parameters with line breaks, tabs or more than four spaces in a row, so whitespace is collapsed;
     * an empty value becomes a dash.
     *
     * @param string $template One of the template constants.
     * @param array<string, string> $values Value of each datum of the template.
     * @return array Components for cloud_api_client::send_template().
     * @throws \coding_exception For an unknown template or a missing datum.
     */
    public static function components(string $template, array $values): array {
        if (!self::is_known($template)) {
            throw new \coding_exception('Unknown WhatsApp template: ' . $template);
        }
        $parameters = [];
        foreach (self::PARAMETERS[$template] as $datum) {
            if (!array_key_exists($datum, $values)) {
                throw new \coding_exception('Missing datum of the WhatsApp template ' . $template . ': ' . $datum);
            }
            $parameters[] = ['type' => 'text', 'text' => self::clean((string) $values[$datum])];
        }
        return $parameters === [] ? [] : [['type' => 'body', 'parameters' => $parameters]];
    }

    /**
     * A parameter as Meta accepts it.
     *
     * @param string $value Value.
     * @return string
     */
    private static function clean(string $value): string {
        $value = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', fix_utf8($value)));
        $value = \core_text::substr($value, 0, self::PARAMETER_LENGTH);
        return $value === '' ? self::EMPTY_PARAMETER : $value;
    }
}
