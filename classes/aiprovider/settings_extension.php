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
 * Adds the chat model, the file and image switch with its vision model, the audio switch with its
 * audio model, and the max tokens. The values are stored in the local_jedomi config. The vision and
 * audio selectors never show an empty option: they show the saved model, or the default one, even
 * while their switch is off, so turning a switch on can be saved without touching its selector.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_extension implements plugin_settings_extension {
    /** @var string Component whose config stores the values. */
    private const COMPONENT = 'local_jedomi';

    /** @var array<string, string> Model field of each feature switch. */
    private const SWITCHES = [
        'files_enabled' => model_settings::CAPABILITY_VISION,
        'audio_enabled' => model_settings::CAPABILITY_AUDIO,
    ];

    /** @var array<string, string> String shown in the label column of each feature switch. */
    private const SWITCH_LABELS = [
        'files_enabled' => 'files_processing',
        'audio_enabled' => 'audio_processing',
    ];

    /**
     * Add the chat model, the feature switches with their models and the max tokens fields.
     *
     * @param \MoodleQuickForm $mform Form to add the fields to.
     * @return void
     */
    public function add_fields(\MoodleQuickForm $mform): void {
        self::add_model_select($mform, model_settings::CAPABILITY_CHAT);

        foreach (self::SWITCHES as $switch => $capability) {
            $mform->addElement(
                'advcheckbox',
                $switch,
                get_string(self::SWITCH_LABELS[$switch], self::COMPONENT),
                get_string($switch, self::COMPONENT)
            );
            $mform->setType($switch, PARAM_BOOL);
            $mform->addHelpButton($switch, $switch, self::COMPONENT);

            $field = self::add_model_select($mform, $capability);
            $mform->hideIf($field, $switch, 'notchecked');
        }

        $mform->addElement('text', 'max_tokens', get_string('max_tokens', self::COMPONENT), ['size' => 10]);
        $mform->setType('max_tokens', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('max_tokens', 'max_tokens', self::COMPONENT);
    }

    /**
     * Values Jedomi uses now, to prefill the form.
     *
     * The vision and audio models are the ones Jedomi would use, also while their switch is off.
     *
     * @return array<string, mixed>
     */
    public function get_data(): array {
        return [
            'model' => model_settings::get_model(),
            'files_enabled' => (int) model_settings::is_files_enabled(),
            'vision_model' => model_settings::get_vision_model(),
            'audio_enabled' => (int) model_settings::is_audio_enabled(),
            'audio_model' => model_settings::get_audio_model(),
            'max_tokens' => model_settings::get_max_tokens(),
        ];
    }

    /**
     * Validate the chat model, the model of each switch that is on and the max tokens.
     *
     * @param array $data Submitted form data.
     * @return array<string, string> Error messages keyed by element name; empty when valid.
     */
    public function validate(array $data): array {
        $errors = [];

        if (!model_settings::is_available(trim((string) ($data['model'] ?? '')))) {
            $errors['model'] = get_string('error_invalid_model', self::COMPONENT);
        }

        foreach (self::SWITCHES as $switch => $capability) {
            $field = model_settings::MODEL_SETTINGS[$capability];
            if (!empty($data[$switch]) && !model_settings::is_available(trim((string) ($data[$field] ?? '')), $capability)) {
                $errors[$field] = get_string('error_invalid_model', self::COMPONENT);
            }
        }

        if (!preg_match('/^-?\d+$/', trim((string) ($data['max_tokens'] ?? '')))) {
            $errors['max_tokens'] = get_string('error_invalid_max_tokens', self::COMPONENT);
        }

        return $errors;
    }

    /**
     * Save the values in the local_jedomi config, clamping the max tokens to the accepted range.
     *
     * A vision or audio model that is not in its list (possible only while its switch is off) is
     * saved as the default one. A changed value is written to the config log.
     *
     * @param \stdClass $data Validated form data.
     * @return void
     */
    public function save(\stdClass $data): void {
        $maxtokens = min(model_settings::MAX_MAX_TOKENS, max(model_settings::MIN_MAX_TOKENS, (int) ($data->max_tokens ?? 0)));

        self::save_value('model', trim((string) ($data->model ?? '')));
        foreach (self::SWITCHES as $switch => $capability) {
            $field = model_settings::MODEL_SETTINGS[$capability];
            $model = trim((string) ($data->{$field} ?? ''));
            if (!model_settings::is_available($model, $capability)) {
                $model = model_settings::get_default_model($capability);
            }
            self::save_value($switch, empty($data->{$switch}) ? '0' : '1');
            self::save_value($field, $model);
        }
        self::save_value('max_tokens', (string) $maxtokens);
    }

    /**
     * Add the model selector of a capability, with only the models of that capability and no empty option.
     *
     * @param \MoodleQuickForm $mform Form to add the field to.
     * @param string $capability One of the model_settings::CAPABILITY_* constants.
     * @return string Name of the added field.
     */
    private static function add_model_select(\MoodleQuickForm $mform, string $capability): string {
        $field = model_settings::MODEL_SETTINGS[$capability];
        $models = model_settings::get_available_models($capability);
        $mform->addElement('select', $field, get_string($field, self::COMPONENT), $models);
        $mform->setType($field, PARAM_RAW_TRIMMED);
        $mform->setDefault($field, model_settings::get_default_model($capability));
        $mform->addHelpButton($field, $field, self::COMPONENT);
        return $field;
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
