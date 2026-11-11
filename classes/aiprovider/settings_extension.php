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
 * audio model, the voice conversation switch with its speech model and voice, and the max tokens. The
 * values are stored in the local_jedomi config. The model and voice selectors never show an empty
 * option: they show the saved value, or the default one, even while their switch is off, so turning a
 * switch on can be saved without touching its selectors.
 *
 * The voices depend on the speech model, so there is one voice selector per speech model, shown only
 * while that model is chosen (hideIf on the model selector). Moodle forms then show and hide them
 * without any JavaScript of the plugin, every selector only lists voices its model accepts, and the
 * one of the chosen model is saved in the single speech_voice setting.
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

        self::add_voice_fields($mform);

        $mform->addElement('text', 'max_tokens', get_string('max_tokens', self::COMPONENT), ['size' => 10]);
        $mform->setType('max_tokens', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('max_tokens', 'max_tokens', self::COMPONENT);
    }

    /**
     * Values Jedomi uses now, to prefill the form.
     *
     * The vision, audio and speech models are the ones Jedomi would use, also while their switch is off.
     * The voice selector of the speech model in use shows its voice; the others show their default voice.
     *
     * @return array<string, mixed>
     */
    public function get_data(): array {
        $speechmodel = model_settings::get_speech_model();
        $voices = [];
        foreach (array_keys(model_settings::get_available_models(model_settings::CAPABILITY_SPEECH)) as $model) {
            $voices[model_settings::voice_field($model)] = $model === $speechmodel
                ? model_settings::get_speech_voice()
                : model_settings::get_default_voice($model);
        }

        return [
            'model' => model_settings::get_model(),
            'files_enabled' => (int) model_settings::is_files_enabled(),
            'vision_model' => model_settings::get_vision_model(),
            'audio_enabled' => (int) model_settings::is_audio_enabled(),
            'audio_model' => model_settings::get_audio_model(),
            'voice_enabled' => (int) !empty(get_config(self::COMPONENT, 'voice_enabled')),
            'speech_model' => $speechmodel,
        ] + $voices + [
            'max_tokens' => model_settings::get_max_tokens(),
        ];
    }

    /**
     * Validate the chat model, the model of each switch that is on, the voice of the chosen speech model
     * and the max tokens. The voice conversation needs audio processing, which transcribes the user.
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

        $errors += self::validate_voice($data);

        if (!preg_match('/^-?\d+$/', trim((string) ($data['max_tokens'] ?? '')))) {
            $errors['max_tokens'] = get_string('error_invalid_max_tokens', self::COMPONENT);
        }

        return $errors;
    }

    /**
     * Save the values in the local_jedomi config, clamping the max tokens to the accepted range.
     *
     * A vision, audio or speech model, or a voice, that is not in its list (possible only while its
     * switch is off) is saved as the default one. A changed value is written to the config log.
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

        $speechmodel = trim((string) ($data->speech_model ?? ''));
        if (!model_settings::is_available($speechmodel, model_settings::CAPABILITY_SPEECH)) {
            $speechmodel = model_settings::DEFAULT_SPEECH_MODEL;
        }
        $voice = trim((string) ($data->{model_settings::voice_field($speechmodel)} ?? ''));
        if (!in_array($voice, model_settings::get_voices($speechmodel), true)) {
            $voice = model_settings::get_default_voice($speechmodel);
        }
        self::save_value('voice_enabled', empty($data->voice_enabled) ? '0' : '1');
        self::save_value('speech_model', $speechmodel);
        self::save_value('speech_voice', $voice);

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
     * Add the voice conversation switch, its speech model and one voice selector per speech model.
     *
     * Each voice selector lists only the voices of its model, starts on the default voice and is hidden
     * while the switch is off or another speech model is chosen.
     *
     * @param \MoodleQuickForm $mform Form to add the fields to.
     * @return void
     */
    private static function add_voice_fields(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'advcheckbox',
            'voice_enabled',
            get_string('voice_conversation', self::COMPONENT),
            get_string('voice_enabled', self::COMPONENT)
        );
        $mform->setType('voice_enabled', PARAM_BOOL);
        $mform->addHelpButton('voice_enabled', 'voice_enabled', self::COMPONENT);

        $modelfield = self::add_model_select($mform, model_settings::CAPABILITY_SPEECH);
        $mform->hideIf($modelfield, 'voice_enabled', 'notchecked');

        foreach (array_keys(model_settings::get_available_models(model_settings::CAPABILITY_SPEECH)) as $model) {
            $field = model_settings::voice_field($model);
            $voices = [];
            foreach (model_settings::get_voices($model) as $voice) {
                $voices[$voice] = str_replace('_', ' ', $voice);
            }
            $mform->addElement('select', $field, get_string('speech_voice', self::COMPONENT), $voices);
            $mform->setType($field, PARAM_RAW_TRIMMED);
            $mform->setDefault($field, model_settings::get_default_voice($model));
            $mform->addHelpButton($field, 'speech_voice', self::COMPONENT);
            $mform->hideIf($field, 'voice_enabled', 'notchecked');
            $mform->hideIf($field, $modelfield, 'neq', $model);
        }
    }

    /**
     * Validate the voice conversation: it needs audio processing, a listed speech model and a voice of that model.
     *
     * @param array $data Submitted form data.
     * @return array<string, string> Error messages keyed by element name; empty when valid or switched off.
     */
    private static function validate_voice(array $data): array {
        if (empty($data['voice_enabled'])) {
            return [];
        }
        if (empty($data['audio_enabled'])) {
            return ['voice_enabled' => get_string('error_voice_requires_audio', self::COMPONENT)];
        }
        $model = trim((string) ($data['speech_model'] ?? ''));
        if (!model_settings::is_available($model, model_settings::CAPABILITY_SPEECH)) {
            return ['speech_model' => get_string('error_invalid_model', self::COMPONENT)];
        }
        $field = model_settings::voice_field($model);
        if (!in_array(trim((string) ($data[$field] ?? '')), model_settings::get_voices($model), true)) {
            return [$field => get_string('error_invalid_voice', self::COMPONENT)];
        }
        return [];
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
