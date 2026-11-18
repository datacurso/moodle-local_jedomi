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
use local_jedomi\ai\ai_service;
use local_jedomi\ai\client;
use local_jedomi\ai\custom_endpoint;
use local_jedomi\ai\model_settings;

/**
 * Jedomi AI service fields on its settings page in the Datacurso AI provider.
 *
 * Below "Enabled", the "AI service" select chooses the service: "Datacurso service" (the default) shows the Datacurso
 * fields below, "Custom service" shows the fields of a custom OpenAI-compatible service. There is no "no service"
 * choice: Jedomi is switched off with "Enabled". Only the fields of the chosen service are validated and saved, and
 * each service keeps its own settings.
 *
 * The custom service has an address, an API key (never sent back to the page: an empty field keeps the saved key),
 * "Allow HTTP for local models" and a "Verify connection" button, which calls local_jedomi_verify_custom_service.
 * Its fields appear once the connection is verified (custom_verified), and saving requires the address and key to be
 * the last verified pair. They mirror the Datacurso ones: the chat model, then a switch per feature (images, documents,
 * audio and voice) whose fields are shown, and required, only while it is on; then the max tokens and the context
 * window. The model fields are free text with no catalogue. The custom service has its own switches
 * (custom_files_enabled, custom_documents_enabled, custom_audio_enabled, custom_voice_enabled) instead of sharing the
 * Datacurso ones, because each service keeps its own settings: a switch saved for one service never turns a feature of
 * the other one on or off.
 *
 * The AI request timeout (ai_timeout) is shared by both services and shown for either of them, after their fields.
 *
 * The Datacurso fields: the chat model, the file and image switch with its vision model, the audio switch with its
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

    /** @var string Pattern of a model or voice id of the custom service: no spaces or markup, up to 200 characters. */
    public const CUSTOM_ID_PATTERN = '~^[A-Za-z0-9._:/@+\-]{1,200}\z~';

    /**
     * @var array<string, array{label: string, fields: string[]}> Feature switches of the custom service, in the order of
     *      the page: the string of their label column and the fields shown, and required, only while they are on.
     */
    private const CUSTOM_SWITCHES = [
        'custom_files_enabled' => ['label' => 'custom_files_processing', 'fields' => ['custom_vision_model']],
        'custom_documents_enabled' => ['label' => 'custom_documents_processing', 'fields' => ['custom_docservice_url']],
        'custom_audio_enabled' => ['label' => 'custom_audio_processing', 'fields' => ['custom_audio_model']],
        'custom_voice_enabled' => [
            'label' => 'custom_voice_conversation',
            'fields' => ['custom_speech_model', 'custom_speech_voice'],
        ],
    ];

    /** @var string[] Model and voice id fields of the custom service that hang from a switch. */
    private const CUSTOM_ID_FIELDS = ['custom_vision_model', 'custom_audio_model', 'custom_speech_model', 'custom_speech_voice'];

    /** @var int Smallest context window accepted, in tokens. */
    public const MIN_CONTEXT_WINDOW = 1024;

    /** @var int Largest context window accepted, in tokens. */
    public const MAX_CONTEXT_WINDOW = 10000000;

    /**
     * Add the chat model, the feature switches with their models and the max tokens fields.
     *
     * @param \MoodleQuickForm $mform Form to add the fields to.
     * @return void
     */
    public function add_fields(\MoodleQuickForm $mform): void {
        global $PAGE;

        // Jedomi always has a service: it is switched off with "Enabled" of the provider, not here.
        $mform->addElement('select', ai_service::CONFIG, get_string('ai_service', self::COMPONENT), [
            ai_service::DATACURSO => get_string('service_datacurso', self::COMPONENT),
            ai_service::CUSTOM => get_string('service_custom', self::COMPONENT),
        ]);
        $mform->setType(ai_service::CONFIG, PARAM_ALPHA);
        $mform->setDefault(ai_service::CONFIG, ai_service::DATACURSO);
        $mform->addHelpButton(ai_service::CONFIG, 'ai_service', self::COMPONENT);

        $before = self::element_names($mform);
        self::add_datacurso_fields($mform);
        foreach (array_diff(self::element_names($mform), $before) as $field) {
            $mform->hideIf($field, ai_service::CONFIG, 'neq', ai_service::DATACURSO);
        }

        $before = self::element_names($mform);
        self::add_custom_fields($mform);
        foreach (array_diff(self::element_names($mform), $before) as $field) {
            if ($field !== 'custom_verified') {
                $mform->hideIf($field, ai_service::CONFIG, 'neq', ai_service::CUSTOM);
            }
        }

        // Shared by both services.
        $mform->addElement('text', 'ai_timeout', get_string('ai_timeout', self::COMPONENT), ['size' => 6]);
        $mform->setType('ai_timeout', PARAM_RAW_TRIMMED);
        $mform->setDefault('ai_timeout', client::DEFAULT_TIMEOUT);
        $mform->addHelpButton('ai_timeout', 'ai_timeout', self::COMPONENT);

        $PAGE->requires->js_call_amd('local_jedomi/admin/custom_service', 'init', [[
            'defaultmaxtokens' => model_settings::DEFAULT_MAX_TOKENS,
            'minmaxtokens' => model_settings::MIN_MAX_TOKENS,
        ]]);
    }

    /**
     * Add the fields of the Datacurso service: chat model, feature switches with their models, voice and max tokens.
     *
     * @param \MoodleQuickForm $mform Form to add the fields to.
     * @return void
     */
    private static function add_datacurso_fields(\MoodleQuickForm $mform): void {
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
     * Add the fields of the custom service: address, key, HTTP for local models, the verification button and its
     * result, and, shown once the connection is verified, the chat model, the feature switches with their fields, the
     * max tokens and the context window.
     *
     * The key field is never filled from the saved value: it shows a placeholder when a key is saved.
     *
     * @param \MoodleQuickForm $mform Form to add the fields to.
     * @return void
     */
    private static function add_custom_fields(\MoodleQuickForm $mform): void {
        $mform->addElement('text', 'custom_gateway_url', get_string('custom_gateway_url', self::COMPONENT), [
            'size' => 60,
            'placeholder' => 'https://api.openai.com/v1',
        ]);
        $mform->setType('custom_gateway_url', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('custom_gateway_url', 'custom_gateway_url', self::COMPONENT);

        $placeholder = ai_service::get_custom_key() !== ''
            ? get_string('custom_api_key_saved', self::COMPONENT)
            : get_string('custom_api_key_placeholder', self::COMPONENT);
        $mform->addElement('passwordunmask', 'custom_api_key', get_string('custom_api_key', self::COMPONENT), [
            'placeholder' => $placeholder,
            'autocomplete' => 'new-password',
        ]);
        $mform->setType('custom_api_key', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('custom_api_key', 'custom_api_key', self::COMPONENT);

        $mform->addElement('advcheckbox', 'custom_allow_http', '', get_string('custom_allow_http', self::COMPONENT));
        $mform->setType('custom_allow_http', PARAM_BOOL);
        $mform->addHelpButton('custom_allow_http', 'custom_allow_http', self::COMPONENT);

        $mform->addElement('button', 'custom_verify', get_string('custom_verify', self::COMPONENT));
        $mform->addElement(
            'static',
            'custom_verify_status',
            '',
            \html_writer::div('', '', ['data-region' => 'local_jedomi-custom-verify-status', 'aria-live' => 'polite'])
        );
        // Set by the page when the connection is verified; the server checks the verified pair again on saving.
        $mform->addElement('hidden', 'custom_verified', 0);
        $mform->setType('custom_verified', PARAM_INT);

        $before = self::element_names($mform);
        self::add_custom_text($mform, 'custom_model', 40);
        foreach (self::CUSTOM_SWITCHES as $switch => $info) {
            $mform->addElement(
                'advcheckbox',
                $switch,
                get_string($info['label'], self::COMPONENT),
                get_string($switch, self::COMPONENT)
            );
            $mform->setType($switch, PARAM_BOOL);
            $mform->addHelpButton($switch, $switch, self::COMPONENT);
            foreach ($info['fields'] as $field) {
                self::add_custom_text($mform, $field, $field === 'custom_docservice_url' ? 60 : 40);
                $mform->hideIf($field, $switch, 'notchecked');
            }
        }
        $mform->getElement('custom_docservice_url')->updateAttributes(['placeholder' => 'https://docs.example.com']);
        self::add_custom_text($mform, 'custom_max_tokens', 10);
        $default = ai_service::default_custom_max_tokens(ai_service::get_custom_context_window());
        $mform->setDefault('custom_max_tokens', (string) $default);
        $detected = ai_service::get_detected_context_window(ai_service::get_custom_model(model_settings::CAPABILITY_CHAT));
        self::add_custom_text($mform, 'custom_context_window', 10);
        if ($detected > 0) {
            $mform->getElement('custom_context_window')->updateAttributes([
                'placeholder' => get_string('custom_context_window_detected', self::COMPONENT, $detected),
            ]);
        }

        foreach (array_diff(self::element_names($mform), $before) as $field) {
            $mform->hideIf($field, 'custom_verified', 'neq', '1');
        }
    }

    /**
     * Add a free-text field of the custom service with its help button.
     *
     * @param \MoodleQuickForm $mform Form to add the field to.
     * @param string $field Field name; also the string of its label and help.
     * @param int $size Size of the field.
     * @return void
     */
    private static function add_custom_text(\MoodleQuickForm $mform, string $field, int $size): void {
        $mform->addElement('text', $field, get_string($field, self::COMPONENT), ['size' => $size]);
        $mform->setType($field, PARAM_RAW_TRIMMED);
        $mform->addHelpButton($field, $field, self::COMPONENT);
    }

    /**
     * Names of the elements of a form.
     *
     * @param \MoodleQuickForm $mform Form.
     * @return string[]
     */
    private static function element_names(\MoodleQuickForm $mform): array {
        $names = [];
        foreach ($mform->_elements as $element) {
            $name = $element->getName();
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
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
        $speechmodel = model_settings::get_configured_model(model_settings::CAPABILITY_SPEECH);
        $voices = [];
        foreach (array_keys(model_settings::get_available_models(model_settings::CAPABILITY_SPEECH)) as $model) {
            $voices[model_settings::voice_field($model)] = $model === $speechmodel
                ? model_settings::get_configured_voice()
                : model_settings::get_default_voice($model);
        }

        return [
            ai_service::CONFIG => ai_service::get_active(),
            'model' => model_settings::get_configured_model(model_settings::CAPABILITY_CHAT),
            'files_enabled' => (int) model_settings::is_switch_on('files_enabled', true),
            'vision_model' => model_settings::get_configured_model(model_settings::CAPABILITY_VISION),
            'audio_enabled' => (int) model_settings::is_switch_on('audio_enabled', false),
            'audio_model' => model_settings::get_configured_model(model_settings::CAPABILITY_AUDIO),
            'voice_enabled' => (int) model_settings::is_switch_on('voice_enabled', false),
            'speech_model' => $speechmodel,
        ] + $voices + [
            'max_tokens' => model_settings::get_configured_max_tokens(),
        ] + self::get_custom_data() + [
            'ai_timeout' => client::configured_timeout(),
        ];
    }

    /**
     * Saved values of the custom service, without the key, which never goes back to the page.
     *
     * @return array
     */
    private static function get_custom_data(): array {
        $url = ai_service::get_custom_url();
        $data = [
            'custom_gateway_url' => $url,
            'custom_allow_http' => (int) ai_service::allows_custom_http(),
            'custom_verified' => (int) ($url !== '' && ai_service::is_verified($url, ai_service::get_custom_key())),
        ];
        foreach (ai_service::CUSTOM_MODEL_SETTINGS as $capability => $field) {
            $data[$field] = ai_service::get_custom_model($capability);
        }
        $data['custom_speech_voice'] = ai_service::get_custom_voice();
        // A switch never saved (a site configured before 1.6.3) shows on while its model is saved, as the runtime does.
        $vision = $data['custom_vision_model'] !== '';
        $audio = $data['custom_audio_model'] !== '';
        $data['custom_files_enabled'] = (int) model_settings::is_switch_on('custom_files_enabled', $vision);
        $data['custom_audio_enabled'] = (int) model_settings::is_switch_on('custom_audio_enabled', $audio);
        $data['custom_voice_enabled'] = (int) model_settings::is_switch_on(
            'custom_voice_enabled',
            $data['custom_speech_model'] !== '' && $data['custom_speech_voice'] !== ''
        );
        $data['custom_documents_enabled'] = (int) model_settings::is_switch_on('custom_documents_enabled', false);
        $data['custom_docservice_url'] = ai_service::get_docservice_url();
        $maxtokens = trim((string) get_config(self::COMPONENT, 'custom_max_tokens'));
        $data['custom_max_tokens'] = $maxtokens !== ''
            ? $maxtokens
            : (string) ai_service::default_custom_max_tokens(ai_service::get_custom_context_window());
        $data['custom_context_window'] = trim((string) get_config(self::COMPONENT, 'custom_context_window'));
        return $data;
    }

    /**
     * Validate the chat model, the model of each switch that is on, the voice of the chosen speech model
     * and the max tokens. The voice conversation needs audio processing, which transcribes the user.
     *
     * @param array $data Submitted form data.
     * @return array<string, string> Error messages keyed by element name; empty when valid.
     */
    public function validate(array $data): array {
        $errors = self::submitted_service($data) === ai_service::CUSTOM
            ? self::validate_custom($data)
            : self::validate_datacurso($data);
        // Shared by both services; a submission without it (an older caller) keeps the saved value.
        if (array_key_exists('ai_timeout', $data) && self::parse_timeout($data['ai_timeout']) === null) {
            $errors['ai_timeout'] = get_string('error_invalid_ai_timeout', self::COMPONENT);
        }
        return $errors;
    }

    /**
     * AI request timeout of a submission.
     *
     * @param mixed $value Submitted value.
     * @return int|null Seconds, or null when it is not a whole number within the accepted range.
     */
    private static function parse_timeout($value): ?int {
        $value = trim((string) $value);
        if (!preg_match('/^\d+$/', $value) || (int) $value < client::MIN_TIMEOUT || (int) $value > client::MAX_TIMEOUT) {
            return null;
        }
        return (int) $value;
    }

    /**
     * Service chosen in a submission: the custom service only when it is chosen; anything else, including a
     * submission without the select (an older caller), is the Datacurso service.
     *
     * @param array $data Submitted form data.
     * @return string ai_service::CUSTOM or ai_service::DATACURSO.
     */
    private static function submitted_service(array $data): string {
        return ($data[ai_service::CONFIG] ?? '') === ai_service::CUSTOM ? ai_service::CUSTOM : ai_service::DATACURSO;
    }

    /**
     * Validate the fields of the Datacurso service.
     *
     * @param array $data Submitted form data.
     * @return array Error messages keyed by element name; empty when valid.
     */
    private static function validate_datacurso(array $data): array {
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
     * Validate the fields of the custom service: an allowed address, a key, the verified pair, and plausible model ids.
     *
     * @param array $data Submitted form data.
     * @return array Error messages keyed by element name; empty when valid.
     */
    private static function validate_custom(array $data): array {
        $url = custom_endpoint::normalise((string) ($data['custom_gateway_url'] ?? ''));
        $refused = custom_endpoint::check($url, !empty($data['custom_allow_http']));
        if ($refused !== null) {
            return ['custom_gateway_url' => get_string('custom_verify_' . $refused, self::COMPONENT)];
        }
        $key = ai_service::effective_key($url, (string) ($data['custom_api_key'] ?? ''));
        if ($key === null) {
            return ['custom_api_key' => get_string('custom_verify_key_required', self::COMPONENT)];
        }
        if (!ai_service::is_verified($url, $key)) {
            return ['custom_gateway_url' => get_string('error_custom_not_verified', self::COMPONENT)];
        }

        $errors = [];
        $model = trim((string) ($data['custom_model'] ?? ''));
        if ($model === '') {
            $errors['custom_model'] = get_string('error_custom_model_required', self::COMPONENT);
        } else if (!preg_match(self::CUSTOM_ID_PATTERN, $model)) {
            $errors['custom_model'] = get_string('error_custom_model_invalid', self::COMPONENT);
        }

        // Only the fields of a switch that is on are checked, and they are then required.
        foreach (self::CUSTOM_SWITCHES as $switch => $info) {
            if (empty($data[$switch])) {
                continue;
            }
            foreach ($info['fields'] as $field) {
                if ($field === 'custom_docservice_url') {
                    $errors += self::validate_docservice_url($data);
                    continue;
                }
                $value = trim((string) ($data[$field] ?? ''));
                if ($value === '') {
                    $errors[$field] = get_string(
                        $field === 'custom_speech_voice' ? 'error_custom_voice_required' : 'error_custom_model_required_switch',
                        self::COMPONENT
                    );
                } else if (!preg_match(self::CUSTOM_ID_PATTERN, $value)) {
                    $errors[$field] = get_string('error_custom_model_invalid', self::COMPONENT);
                }
            }
        }
        if (!empty($data['custom_voice_enabled']) && empty($data['custom_audio_enabled'])) {
            // What the user says is transcribed by the audio model.
            $errors = ['custom_voice_enabled' => get_string('error_voice_requires_audio', self::COMPONENT)]
                + array_diff_key($errors, array_flip(['custom_speech_model', 'custom_speech_voice']));
        }
        if (!empty($data['custom_documents_enabled']) && empty($data['custom_files_enabled'])) {
            // The pages of a PDF are read by the vision model.
            $errors = ['custom_documents_enabled' => get_string('error_documents_require_vision', self::COMPONENT)]
                + array_diff_key($errors, ['custom_docservice_url' => true]);
        }

        $maxtokens = trim((string) ($data['custom_max_tokens'] ?? ''));
        if ($maxtokens !== '' && !preg_match('/^-?\d+$/', $maxtokens)) {
            $errors['custom_max_tokens'] = get_string('error_invalid_max_tokens', self::COMPONENT);
        }
        $window = trim((string) ($data['custom_context_window'] ?? ''));
        if (
            $window !== '' && (!preg_match('/^\d+$/', $window) || (int) $window < self::MIN_CONTEXT_WINDOW
                || (int) $window > self::MAX_CONTEXT_WINDOW)
        ) {
            $errors['custom_context_window'] = get_string('error_invalid_context_window', self::COMPONENT);
        }
        if (!isset($errors['custom_max_tokens']) && !isset($errors['custom_context_window']) && !isset($errors['custom_model'])) {
            // The answer must fit in the window: the typed one, or the one detected for the chat model.
            $window = $window !== '' ? (int) $window : ai_service::get_detected_context_window($model);
            if ($window > 0 && self::custom_max_tokens($maxtokens, $window) >= $window) {
                $errors['custom_max_tokens'] = get_string('error_max_tokens_exceed_context', self::COMPONENT, $window);
            }
        }
        return $errors;
    }

    /**
     * Validate the address of the document service: required while documents are on, with the policy of the address of
     * the custom service.
     *
     * @param array $data Submitted form data.
     * @return array Error messages keyed by element name; empty when valid.
     */
    private static function validate_docservice_url(array $data): array {
        $url = ai_service::normalise_docservice_url((string) ($data['custom_docservice_url'] ?? ''));
        if ($url === '') {
            return ['custom_docservice_url' => get_string('error_docservice_url_required', self::COMPONENT)];
        }
        $refused = custom_endpoint::check($url, !empty($data['custom_allow_http']));
        if ($refused !== null) {
            return ['custom_docservice_url' => get_string('custom_verify_' . $refused, self::COMPONENT)];
        }
        return [];
    }

    /**
     * Max tokens of the custom service as they are saved: clamped to the accepted range; when empty, the default that
     * fits the context window ({@see ai_service::default_custom_max_tokens()}).
     *
     * @param string $value Submitted value, already checked to be a whole number or empty.
     * @param int $window Context window of the submission, in tokens; 0 when it is not known.
     * @return int
     */
    private static function custom_max_tokens(string $value, int $window): int {
        if ($value === '') {
            return ai_service::default_custom_max_tokens($window);
        }
        return min(model_settings::MAX_MAX_TOKENS, max(model_settings::MIN_MAX_TOKENS, (int) $value));
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
        $service = self::submitted_service((array) $data);
        self::save_value(ai_service::CONFIG, $service);
        if ($service === ai_service::CUSTOM) {
            self::save_custom($data);
        } else {
            self::save_datacurso($data);
        }
        $timeout = property_exists($data, 'ai_timeout') ? self::parse_timeout($data->ai_timeout) : null;
        if ($timeout !== null) {
            self::save_value('ai_timeout', (string) $timeout);
        }
    }

    /**
     * Save the fields of the Datacurso service.
     *
     * @param \stdClass $data Validated form data.
     * @return void
     */
    private static function save_datacurso(\stdClass $data): void {
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
     * Save the fields of the custom service. An empty key keeps the saved one; the key is logged masked.
     *
     * @param \stdClass $data Validated form data.
     * @return void
     */
    private static function save_custom(\stdClass $data): void {
        $url = custom_endpoint::normalise((string) ($data->custom_gateway_url ?? ''));
        self::save_value('custom_gateway_url', $url);
        self::save_secret('custom_api_key', ai_service::effective_key($url, (string) ($data->custom_api_key ?? '')) ?? '');
        self::save_value('custom_allow_http', empty($data->custom_allow_http) ? '0' : '1');
        self::save_value('custom_model', trim((string) ($data->custom_model ?? '')));
        foreach (array_keys(self::CUSTOM_SWITCHES) as $switch) {
            self::save_value($switch, empty($data->{$switch}) ? '0' : '1');
        }
        // A model behind a switch that is off was not checked: it is kept for later only when it is a valid id.
        foreach (self::CUSTOM_ID_FIELDS as $field) {
            $value = trim((string) ($data->{$field} ?? ''));
            self::save_value($field, preg_match(self::CUSTOM_ID_PATTERN, $value) ? $value : '');
        }
        $docservice = ai_service::normalise_docservice_url((string) ($data->custom_docservice_url ?? ''));
        if ($docservice !== '' && custom_endpoint::check($docservice, !empty($data->custom_allow_http)) !== null) {
            $docservice = '';
        }
        self::save_value('custom_docservice_url', $docservice);
        $window = trim((string) ($data->custom_context_window ?? ''));
        $window = preg_match('/^\d+$/', $window) && (int) $window > 0 ? (string) (int) $window : '';
        self::save_value('custom_context_window', $window);
        $known = $window !== '' ? (int) $window
            : ai_service::get_detected_context_window(trim((string) ($data->custom_model ?? '')));
        $maxtokens = self::custom_max_tokens(trim((string) ($data->custom_max_tokens ?? '')), $known);
        self::save_value('custom_max_tokens', (string) $maxtokens);
    }

    /**
     * Store a secret of local_jedomi; a change is written to the config log masked, as Moodle does for passwords.
     *
     * @param string $name Setting name.
     * @param string $value New value.
     * @return void
     */
    private static function save_secret(string $name, string $value): void {
        $previous = (string) get_config(self::COMPONENT, $name);
        if ($previous === $value) {
            return;
        }
        set_config($name, $value, self::COMPONENT);
        add_to_config_log($name, $previous === '' ? null : '********', $value === '' ? '' : '********', self::COMPONENT);
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
