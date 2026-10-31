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

namespace local_jedomi\aiprovider;

use aiprovider_datacurso\local\plugin_settings_extension;
use local_jedomi\ai\model_settings;

/**
 * Jedomi AI service fields on its settings page in the Datacurso AI provider.
 *
 * Adds the model, the document-to-image service URL and the max tokens. The values are still
 * stored in the local_jedomi config, so the code that reads them does not change.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_extension implements plugin_settings_extension {
    /** @var string Component whose config stores the values. */
    private const COMPONENT = 'local_jedomi';

    /**
     * Add the model, document service URL and max tokens fields.
     *
     * @param \MoodleQuickForm $mform Form to add the fields to.
     * @return void
     */
    public function add_fields(\MoodleQuickForm $mform): void {
        $mform->addElement('select', 'model', get_string('model', self::COMPONENT), model_settings::get_available_models());
        $mform->setType('model', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('model', 'model', self::COMPONENT);

        $mform->addElement('text', 'docservice_url', get_string('docservice_url', self::COMPONENT), ['size' => 60]);
        $mform->setType('docservice_url', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('docservice_url', 'docservice_url', self::COMPONENT);

        $mform->addElement('text', 'max_tokens', get_string('max_tokens', self::COMPONENT), ['size' => 10]);
        $mform->setType('max_tokens', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('max_tokens', 'max_tokens', self::COMPONENT);
    }

    /**
     * Values Jedomi uses now, to prefill the form.
     *
     * @return array<string, mixed>
     */
    public function get_data(): array {
        return [
            'model' => model_settings::get_model(),
            'docservice_url' => trim((string) get_config(self::COMPONENT, 'docservice_url')),
            'max_tokens' => model_settings::get_max_tokens(),
        ];
    }

    /**
     * Validate the model, the document service URL and the max tokens.
     *
     * @param array $data Submitted form data.
     * @return array<string, string> Error messages keyed by element name; empty when valid.
     */
    public function validate(array $data): array {
        $errors = [];

        if (!model_settings::is_available(trim((string) ($data['model'] ?? '')))) {
            $errors['model'] = get_string('error_invalid_model', self::COMPONENT);
        }

        $url = trim((string) ($data['docservice_url'] ?? ''));
        if ($url !== '' && !self::is_web_url($url)) {
            $errors['docservice_url'] = get_string('error_invalid_docservice_url', self::COMPONENT);
        }

        if (!preg_match('/^-?\d+$/', trim((string) ($data['max_tokens'] ?? '')))) {
            $errors['max_tokens'] = get_string('error_invalid_max_tokens', self::COMPONENT);
        }

        return $errors;
    }

    /**
     * Save the values in the local_jedomi config, clamping the max tokens to the accepted range.
     *
     * A changed value is written to the config log.
     *
     * @param \stdClass $data Validated form data.
     * @return void
     */
    public function save(\stdClass $data): void {
        $maxtokens = min(model_settings::MAX_MAX_TOKENS, max(model_settings::MIN_MAX_TOKENS, (int) ($data->max_tokens ?? 0)));

        self::save_value('model', trim((string) ($data->model ?? '')));
        self::save_value('docservice_url', trim((string) ($data->docservice_url ?? '')));
        self::save_value('max_tokens', (string) $maxtokens);
    }

    /**
     * Whether a value is an absolute http or https URL with a host.
     *
     * @param string $url Value to check.
     * @return bool
     */
    private static function is_web_url(string $url): bool {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * Store a setting of local_jedomi, writing a config log row only when the value changes.
     *
     * @param string $name Setting name.
     * @param string $value New value.
     * @return void
     */
    private static function save_value(string $name, string $value): void {
        $previous = get_config(self::COMPONENT, $name);
        $changed = $previous === false || (string) $previous !== $value;
        set_config($name, $value, self::COMPONENT, $changed);
    }
}
