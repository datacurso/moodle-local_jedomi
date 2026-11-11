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

use aiprovider_datacurso\form\plugin_settings_form;
use aiprovider_datacurso\local\plugin_settings;
use aiprovider_datacurso\local\plugin_settings_extension;
use local_jedomi\ai\model_settings;

/**
 * Tests for the Jedomi fields on its settings page in the Datacurso AI provider (S5).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\aiprovider\settings_extension
 */
final class settings_extension_test extends \advanced_testcase {
    /** @var string Second text-to-speech model of the catalogue. */
    private const INWORLD = 'jedomi/inworld-ai/inworld-tts-1.5-mini';

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; there is no settings page to extend.');
        }
    }

    /**
     * Valid submitted values of the Jedomi fields.
     *
     * @param array $overrides Values replacing the defaults.
     * @return array
     */
    private function valid_data(array $overrides = []): array {
        return array_merge([
            'component' => 'local_jedomi',
            'enabled' => 1,
            'model' => model_settings::DEFAULT_MODEL,
            'files_enabled' => 1,
            'vision_model' => model_settings::DEFAULT_VISION_MODEL,
            'audio_enabled' => 0,
            'audio_model' => model_settings::DEFAULT_AUDIO_MODEL,
            'voice_enabled' => 0,
            'speech_model' => model_settings::DEFAULT_SPEECH_MODEL,
            model_settings::voice_field(model_settings::DEFAULT_SPEECH_MODEL) => 'Vivian',
            model_settings::voice_field(self::INWORLD) => 'Diego',
            'max_tokens' => '8000',
        ], $overrides);
    }

    /**
     * S5: the provider finds the Jedomi extension by its class name.
     *
     * @return void
     */
    public function test_provider_finds_the_extension(): void {
        $extension = plugin_settings::get_extension('local_jedomi');

        $this->assertInstanceOf(settings_extension::class, $extension);
        $this->assertInstanceOf(plugin_settings_extension::class, $extension);
    }

    /**
     * MDL-INT-005: the provider form shows the Jedomi fields.
     * M6: the provider form shows "Enabled", the chat model, both switches with their models and max tokens.
     * V5: the voice conversation switch, its model and the voice of each model come after the audio model.
     *
     * @return void
     */
    public function test_provider_form_renders_the_jedomi_fields(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $form = new plugin_settings_form(null, ['component' => 'local_jedomi']);
        $html = $form->render();

        $this->assertStringContainsString('name="enabled"', $html);
        $this->assertStringContainsString('name="model"', $html);
        $this->assertStringNotContainsString('docservice_url', $html);
        $order = [];
        $fields = ['enabled', 'model', 'files_enabled', 'vision_model', 'audio_enabled', 'audio_model', 'voice_enabled',
            'speech_model', model_settings::voice_field(model_settings::DEFAULT_SPEECH_MODEL),
            model_settings::voice_field(self::INWORLD), 'max_tokens'];
        foreach ($fields as $name) {
            $position = strpos($html, 'name="' . $name . '"');
            $this->assertNotFalse($position, "Field {$name} must be rendered");
            $order[] = $position;
        }
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'The fields must be in the documented order');
        foreach (model_settings::CAPABILITIES as $capability) {
            foreach (array_keys(model_settings::get_available_models($capability)) as $model) {
                $this->assertStringContainsString('value="' . s($model) . '"', $html);
            }
        }
    }

    /**
     * MDL-UNIT-001: valid values pass the validation of the provider form.
     *
     * @return void
     */
    public function test_validation_accepts_valid_values(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new plugin_settings_form(null, ['component' => 'local_jedomi']);

        $this->assertSame([], $form->validation($this->valid_data(), []));
        $this->assertSame([], $form->validation($this->valid_data(['audio_enabled' => 1]), []));
        // A switch that is off does not need a valid model.
        $this->assertSame([], $form->validation($this->valid_data([
            'files_enabled' => 0, 'vision_model' => '', 'audio_enabled' => 0, 'audio_model' => 'jedomi/acme/x',
        ]), []));
    }

    /**
     * MDL-UNIT-001: a chat model outside the list is always rejected with the "select a model" message.
     *
     * @return void
     */
    public function test_validation_rejects_unlisted_model(): void {
        $extension = new settings_extension();

        $errors = $extension->validate($this->valid_data(['model' => 'jedomi/acme/unknown-model']));

        $this->assertArrayHasKey('model', $errors);
        $this->assertSame(get_string('error_invalid_model', 'local_jedomi'), $errors['model']);
    }

    /**
     * MDL-UNIT-002: max tokens must be a whole number.
     *
     * @dataProvider bad_max_tokens_provider
     * @param string $value Submitted value.
     * @return void
     */
    public function test_validation_rejects_non_integer_max_tokens(string $value): void {
        $extension = new settings_extension();

        $errors = $extension->validate($this->valid_data(['max_tokens' => $value]));

        $this->assertSame(['max_tokens' => get_string('error_invalid_max_tokens', 'local_jedomi')], $errors);
    }

    /**
     * Max tokens values that are not whole numbers.
     *
     * @return array<string, array{0: string}>
     */
    public static function bad_max_tokens_provider(): array {
        return [
            'text' => ['abc'],
            'decimal' => ['12.5'],
            'empty' => [''],
        ];
    }

    /**
     * MDL-UNIT-002: max tokens are clamped to the nearest limit of the accepted range when saved.
     *
     * @dataProvider clamp_provider
     * @param int $submitted Submitted value.
     * @param int $expected Saved value.
     * @return void
     */
    public function test_save_clamps_max_tokens(int $submitted, int $expected): void {
        $this->resetAfterTest();
        $extension = new settings_extension();

        $extension->save((object) $this->valid_data(['max_tokens' => $submitted]));

        $this->assertSame((string) $expected, get_config('local_jedomi', 'max_tokens'));
    }

    /**
     * Submitted max tokens and the value that must be saved.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function clamp_provider(): array {
        return [
            'below minimum' => [10, model_settings::MIN_MAX_TOKENS],
            'negative' => [-5, model_settings::MIN_MAX_TOKENS],
            'just below minimum' => [model_settings::MIN_MAX_TOKENS - 1, model_settings::MIN_MAX_TOKENS],
            'at minimum' => [model_settings::MIN_MAX_TOKENS, model_settings::MIN_MAX_TOKENS],
            'in range' => [8000, 8000],
            'at maximum' => [model_settings::MAX_MAX_TOKENS, model_settings::MAX_MAX_TOKENS],
            'just above maximum' => [model_settings::MAX_MAX_TOKENS + 1, model_settings::MAX_MAX_TOKENS],
            'above maximum' => [500000, model_settings::MAX_MAX_TOKENS],
        ];
    }

    /**
     * MDL-INT-005: saving writes the provider and the Jedomi values.
     * S5: saving through the provider form stores "Enabled" in the provider and the rest in local_jedomi.
     *
     * @return void
     */
    public function test_save_through_provider_writes_provider_and_jedomi_config(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        plugin_settings_form::save('local_jedomi', (object) $this->valid_data([
            'enabled' => 0,
            'model' => 'jedomi/zai-org/GLM-5.3-Flash',
            'files_enabled' => 0,
            'vision_model' => 'jedomi/Qwen/Qwen3-VL-235B-A22B-Instruct',
            'audio_enabled' => 1,
            'audio_model' => '  jedomi/openai/whisper-large-v3-turbo  ',
            'max_tokens' => 4096,
        ]));

        $this->assertSame('0', get_config('aiprovider_datacurso', 'plugin_enabled_local_jedomi'));
        $this->assertFalse(plugin_settings::is_enabled('local_jedomi'));
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', get_config('local_jedomi', 'model'));
        $this->assertSame('0', get_config('local_jedomi', 'files_enabled'));
        $this->assertSame('jedomi/Qwen/Qwen3-VL-235B-A22B-Instruct', get_config('local_jedomi', 'vision_model'));
        $this->assertSame('1', get_config('local_jedomi', 'audio_enabled'));
        $this->assertSame('jedomi/openai/whisper-large-v3-turbo', get_config('local_jedomi', 'audio_model'));
        $this->assertSame('4096', get_config('local_jedomi', 'max_tokens'));
        $this->assertFalse(get_config('local_jedomi', 'enabled'));
    }

    /**
     * MDL-INT-005: only real changes are logged.
     * S5: a change is written to the config log; saving the same value again is not logged.
     *
     * @return void
     */
    public function test_save_logs_only_changes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('model', model_settings::DEFAULT_MODEL, 'local_jedomi');
        $extension = new settings_extension();
        $params = ['plugin' => 'local_jedomi', 'name' => 'model'];

        $extension->save((object) $this->valid_data(['model' => model_settings::DEFAULT_MODEL]));
        $this->assertSame(0, $DB->count_records('config_log', $params));

        $extension->save((object) $this->valid_data(['model' => 'jedomi/zai-org/GLM-5.3-Flash']));
        $this->assertSame(1, $DB->count_records('config_log', $params));
    }

    /**
     * MDL-INT-005: the form shows the current values.
     * S5: the form is prefilled with the current Jedomi settings and the provider state.
     *
     * @return void
     */
    public function test_get_data_reflects_current_config(): void {
        $this->resetAfterTest();
        set_config('model', 'jedomi/XiaomiMiMo/MiMo-V2.6-Flash', 'local_jedomi');
        set_config('files_enabled', 0, 'local_jedomi');
        set_config('vision_model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        set_config('audio_enabled', 1, 'local_jedomi');
        set_config('audio_model', 'jedomi/mistralai/Voxtral-Mini-3B-2507', 'local_jedomi');
        set_config('voice_enabled', 1, 'local_jedomi');
        set_config('speech_model', self::INWORLD, 'local_jedomi');
        set_config('speech_voice', 'Rafael', 'local_jedomi');
        set_config('max_tokens', 9000, 'local_jedomi');
        set_config('plugin_enabled_local_jedomi', '0', 'aiprovider_datacurso');

        $this->assertSame([
            'model' => 'jedomi/XiaomiMiMo/MiMo-V2.6-Flash',
            'files_enabled' => 0,
            'vision_model' => 'jedomi/zai-org/GLM-5.3-Flash',
            'audio_enabled' => 1,
            'audio_model' => 'jedomi/mistralai/Voxtral-Mini-3B-2507',
            'voice_enabled' => 1,
            'speech_model' => self::INWORLD,
            model_settings::voice_field(model_settings::DEFAULT_SPEECH_MODEL) => 'Vivian',
            model_settings::voice_field(self::INWORLD) => 'Rafael',
            'max_tokens' => 9000,
        ], (new settings_extension())->get_data());

        $current = plugin_settings_form::current_data('local_jedomi');
        $this->assertSame(0, $current['enabled']);
        $this->assertSame('jedomi/XiaomiMiMo/MiMo-V2.6-Flash', $current['model']);
        $this->assertSame(9000, $current['max_tokens']);
    }

    /**
     * MDL-INT-005: the form shows the effective defaults when nothing is saved.
     * MDL-UNIT-003: unset or unlisted values are shown as the values Jedomi actually uses.
     *
     * @return void
     */
    public function test_get_data_uses_effective_defaults(): void {
        $this->resetAfterTest();
        set_config('model', 'jedomi/acme/unknown-model', 'local_jedomi');
        unset_config('max_tokens', 'local_jedomi');
        unset_config('files_enabled', 'local_jedomi');
        unset_config('audio_enabled', 'local_jedomi');
        unset_config('vision_model', 'local_jedomi');
        set_config('audio_model', 'jedomi/acme/unknown-model', 'local_jedomi');
        unset_config('voice_enabled', 'local_jedomi');
        set_config('speech_model', 'jedomi/acme/unknown-model', 'local_jedomi');
        set_config('speech_voice', 'Nobody', 'local_jedomi');

        $this->assertSame([
            'model' => model_settings::DEFAULT_MODEL,
            'files_enabled' => 1,
            'vision_model' => model_settings::DEFAULT_VISION_MODEL,
            'audio_enabled' => 0,
            'audio_model' => model_settings::DEFAULT_AUDIO_MODEL,
            'voice_enabled' => 0,
            'speech_model' => model_settings::DEFAULT_SPEECH_MODEL,
            model_settings::voice_field(model_settings::DEFAULT_SPEECH_MODEL) => 'Vivian',
            model_settings::voice_field(self::INWORLD) => 'Diego',
            'max_tokens' => model_settings::DEFAULT_MAX_TOKENS,
        ], (new settings_extension())->get_data());
    }

    /**
     * MDL-UNIT-003: the vision and audio selectors have no empty option and show the default model while their switch is off.
     *
     * @return void
     */
    public function test_media_selectors_are_never_empty(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('vision_model', 'local_jedomi');
        unset_config('audio_model', 'local_jedomi');
        set_config('audio_enabled', 0, 'local_jedomi');

        $form = new plugin_settings_form(null, ['component' => 'local_jedomi']);
        $form->set_data(plugin_settings_form::current_data('local_jedomi'));
        $html = $form->render();

        foreach (
            ['vision_model' => model_settings::DEFAULT_VISION_MODEL,
                'audio_model' => model_settings::DEFAULT_AUDIO_MODEL] as $name => $default
        ) {
            $this->assertSame(1, preg_match('/<select[^>]*name="' . $name . '"[^>]*>(.*?)<\/select>/s', $html, $match));
            $this->assertStringNotContainsString('value=""', $match[1], "{$name} must not have an empty option");
            $this->assertMatchesRegularExpression('/value="' . preg_quote(s($default), '/') . '"\s+selected/', $match[1]);
        }
    }

    /**
     * The feature switches show a label in the label column and their action next to the checkbox.
     *
     * @return void
     */
    public function test_switches_have_a_label_and_a_description(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mform = new \MoodleQuickForm('jedomitest', 'post', '');
        (new settings_extension())->add_fields($mform);

        $files = $mform->getElement('files_enabled');
        $this->assertSame(get_string('files_processing', 'local_jedomi'), $files->getLabel());
        $this->assertSame(get_string('files_enabled', 'local_jedomi'), $files->getText());

        $audio = $mform->getElement('audio_enabled');
        $this->assertSame(get_string('audio_processing', 'local_jedomi'), $audio->getLabel());
        $this->assertSame(get_string('audio_enabled', 'local_jedomi'), $audio->getText());
    }

    /**
     * MDL-UNIT-001: the vision and audio models are hidden while their switch is off.
     *
     * The rules are read from the dependencies the form hands to the browser, the same data the
     * form JavaScript uses to show and hide the fields.
     *
     * @return void
     */
    public function test_media_models_are_hidden_when_their_switch_is_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mform = new \MoodleQuickForm('jedomitest', 'post', '');
        (new settings_extension())->add_fields($mform);

        [, $dependencies] = $mform->getLockOptionObject();
        $hide = \MoodleQuickForm::DEP_HIDE;
        $this->assertSame(['vision_model'], $dependencies['files_enabled']['notchecked']['1'][$hide]);
        $this->assertSame(['audio_model'], $dependencies['audio_enabled']['notchecked']['1'][$hide]);
        $this->assertSame(
            ['speech_model', model_settings::voice_field(model_settings::DEFAULT_SPEECH_MODEL),
                model_settings::voice_field(self::INWORLD)],
            $dependencies['voice_enabled']['notchecked']['1'][$hide]
        );
        $this->assertArrayNotHasKey('model', $dependencies, 'The chat model is never hidden');
        $this->assertArrayNotHasKey('max_tokens', $dependencies, 'Max tokens is never hidden');
    }

    /**
     * MDL-UNIT-001: with its switch on, an empty or unlisted vision or audio model is rejected.
     *
     * @dataProvider bad_media_model_provider
     * @param string $switch Switch field.
     * @param string $field Model field.
     * @param string $model Submitted model.
     * @return void
     */
    public function test_validation_rejects_bad_media_model(string $switch, string $field, string $model): void {
        $errors = (new settings_extension())->validate($this->valid_data([$switch => 1, $field => $model]));

        $this->assertSame([$field => get_string('error_invalid_model', 'local_jedomi')], $errors);
    }

    /**
     * Vision and audio models that must be rejected when their switch is on.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function bad_media_model_provider(): array {
        return [
            'empty vision' => ['files_enabled', 'vision_model', ''],
            'audio model as vision' => ['files_enabled', 'vision_model', 'jedomi/Qwen/Qwen3-ASR-1.7B'],
            'unknown vision' => ['files_enabled', 'vision_model', 'jedomi/acme/unknown-model'],
            'empty audio' => ['audio_enabled', 'audio_model', ''],
            'chat model as audio' => ['audio_enabled', 'audio_model', model_settings::DEFAULT_MODEL],
        ];
    }

    /**
     * MDL-UNIT-001: a switch that is off keeps the default model saved, so turning it on later shows a real model.
     *
     * @return void
     */
    public function test_save_with_switch_off_keeps_a_valid_model(): void {
        $this->resetAfterTest();

        (new settings_extension())->save((object) $this->valid_data([
            'files_enabled' => 0, 'vision_model' => '', 'audio_enabled' => 0, 'audio_model' => 'jedomi/acme/x',
        ]));

        $this->assertSame('0', get_config('local_jedomi', 'files_enabled'));
        $this->assertSame(model_settings::DEFAULT_VISION_MODEL, get_config('local_jedomi', 'vision_model'));
        $this->assertSame('0', get_config('local_jedomi', 'audio_enabled'));
        $this->assertSame(model_settings::DEFAULT_AUDIO_MODEL, get_config('local_jedomi', 'audio_model'));
    }

    /**
     * MDL-UNIT-004: on a site with nothing saved, the provider form shows Jedomi enabled, files on and audio off.
     *
     * @return void
     */
    public function test_provider_form_defaults_on_a_new_site(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('plugin_enabled_local_jedomi', 'aiprovider_datacurso');
        unset_config('files_enabled', 'local_jedomi');
        unset_config('audio_enabled', 'local_jedomi');
        unset_config('voice_enabled', 'local_jedomi');

        $current = plugin_settings_form::current_data('local_jedomi');

        $this->assertTrue(plugin_settings::is_enabled('local_jedomi'));
        $this->assertSame(1, $current['enabled']);
        $this->assertSame(1, $current['files_enabled']);
        $this->assertSame(0, $current['audio_enabled']);
        $this->assertSame(0, $current['voice_enabled']);
    }

    /**
     * V5: the voice conversation switch shows its label and its action, and each voice selector lists only the voices of
     * its model, without an empty option, and is shown only while that model is chosen.
     *
     * @return void
     */
    public function test_voice_fields_list_the_voices_of_each_model(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mform = new \MoodleQuickForm('jedomitest', 'post', '');
        (new settings_extension())->add_fields($mform);

        $switch = $mform->getElement('voice_enabled');
        $this->assertSame(get_string('voice_conversation', 'local_jedomi'), $switch->getLabel());
        $this->assertSame(get_string('voice_enabled', 'local_jedomi'), $switch->getText());

        $speech = $mform->getElement('speech_model');
        $this->assertSame(
            array_keys(model_settings::get_available_models(model_settings::CAPABILITY_SPEECH)),
            array_map(static fn(array $option): string => $option['attr']['value'], $speech->_options)
        );

        [, $dependencies] = $mform->getLockOptionObject();
        $hide = \MoodleQuickForm::DEP_HIDE;
        foreach ([model_settings::DEFAULT_SPEECH_MODEL, self::INWORLD] as $model) {
            $field = model_settings::voice_field($model);
            $this->assertMatchesRegularExpression('/^speech_voice_[a-z0-9_]+$/', $field);
            $select = $mform->getElement($field);
            $values = array_map(static fn(array $option): string => $option['attr']['value'], $select->_options);
            $this->assertSame(model_settings::get_voices($model), $values, "{$field} lists the voices of its model");
            $this->assertSame(model_settings::get_default_voice($model), $mform->_defaultValues[$field] ?? null);
            // Hidden while another model is chosen.
            $others = array_diff(array_keys(model_settings::get_available_models(model_settings::CAPABILITY_SPEECH)), [$model]);
            foreach ($others as $other) {
                $this->assertNotContains($field, $dependencies['speech_model']['neq'][$other][$hide] ?? []);
            }
            $this->assertContains($field, $dependencies['speech_model']['neq'][$model][$hide]);
        }
    }

    /**
     * V5: the voice selector of the chosen model shows the saved voice, and the one of the other model its default voice.
     *
     * @return void
     */
    public function test_voice_selectors_show_the_saved_and_the_default_voices(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('speech_model', self::INWORLD, 'local_jedomi');
        set_config('speech_voice', 'Miguel', 'local_jedomi');

        $form = new plugin_settings_form(null, ['component' => 'local_jedomi']);
        $form->set_data(plugin_settings_form::current_data('local_jedomi'));
        $html = $form->render();

        foreach ([self::INWORLD => 'Miguel', model_settings::DEFAULT_SPEECH_MODEL => 'Vivian'] as $model => $voice) {
            $name = model_settings::voice_field($model);
            $this->assertSame(1, preg_match('/<select[^>]*name="' . $name . '"[^>]*>(.*?)<\/select>/s', $html, $match));
            $this->assertStringNotContainsString('value=""', $match[1], "{$name} must not have an empty option");
            $this->assertMatchesRegularExpression('/value="' . $voice . '"\s+selected/', $match[1]);
        }
    }

    /**
     * V5: valid voice values pass, also with the second model and its voices.
     *
     * @return void
     */
    public function test_validation_accepts_a_voice_of_the_chosen_model(): void {
        $extension = new settings_extension();

        $this->assertSame([], $extension->validate($this->valid_data(['audio_enabled' => 1, 'voice_enabled' => 1])));
        $this->assertSame([], $extension->validate($this->valid_data([
            'audio_enabled' => 1, 'voice_enabled' => 1, 'speech_model' => self::INWORLD,
            model_settings::voice_field(self::INWORLD) => 'Rafael',
        ])));
        // A switch that is off does not need a valid model or voice.
        $this->assertSame([], $extension->validate($this->valid_data([
            'voice_enabled' => 0, 'speech_model' => '', model_settings::voice_field(self::INWORLD) => 'Nobody',
        ])));
    }

    /**
     * V5: the voice conversation cannot be turned on while audio processing is off.
     *
     * @return void
     */
    public function test_validation_rejects_voice_without_audio(): void {
        $errors = (new settings_extension())->validate($this->valid_data(['audio_enabled' => 0, 'voice_enabled' => 1]));

        $this->assertSame(['voice_enabled' => get_string('error_voice_requires_audio', 'local_jedomi')], $errors);
    }

    /**
     * V5: with the voice conversation on, a model outside the speech list or a voice outside the list of the chosen
     * model is rejected.
     *
     * @dataProvider bad_voice_provider
     * @param array $overrides Submitted values.
     * @param string $field Field with the error.
     * @param string $error String of the error.
     * @return void
     */
    public function test_validation_rejects_bad_speech_model_or_voice(array $overrides, string $field, string $error): void {
        $data = $this->valid_data(array_merge(['audio_enabled' => 1, 'voice_enabled' => 1], $overrides));

        $errors = (new settings_extension())->validate($data);

        $this->assertSame([$field => get_string($error, 'local_jedomi')], $errors);
    }

    /**
     * Speech models and voices that must be rejected when the voice conversation is on.
     *
     * @return array
     */
    public static function bad_voice_provider(): array {
        $qwen = model_settings::voice_field(model_settings::DEFAULT_SPEECH_MODEL);
        $inworld = model_settings::voice_field(self::INWORLD);
        return [
            'empty model' => [['speech_model' => ''], 'speech_model', 'error_invalid_model'],
            'chat model' => [['speech_model' => model_settings::DEFAULT_MODEL], 'speech_model', 'error_invalid_model'],
            'audio model' => [['speech_model' => model_settings::DEFAULT_AUDIO_MODEL], 'speech_model', 'error_invalid_model'],
            'voice of another model' => [[$qwen => 'Diego'], $qwen, 'error_invalid_voice'],
            'unknown voice' => [['speech_model' => self::INWORLD, $inworld => 'Valentina'], $inworld, 'error_invalid_voice'],
            'empty voice' => [[$qwen => ''], $qwen, 'error_invalid_voice'],
            'voice in another case' => [[$qwen => 'vivian'], $qwen, 'error_invalid_voice'],
        ];
    }

    /**
     * V5: saving stores the switch, the model and the voice chosen for that model in a single setting.
     *
     * @return void
     */
    public function test_save_stores_the_voice_of_the_chosen_model(): void {
        $this->resetAfterTest();

        (new settings_extension())->save((object) $this->valid_data([
            'audio_enabled' => 1, 'voice_enabled' => 1, 'speech_model' => self::INWORLD,
            model_settings::voice_field(model_settings::DEFAULT_SPEECH_MODEL) => 'Serena',
            model_settings::voice_field(self::INWORLD) => 'Lupita',
        ]));

        $this->assertSame('1', get_config('local_jedomi', 'voice_enabled'));
        $this->assertSame(self::INWORLD, get_config('local_jedomi', 'speech_model'));
        $this->assertSame('Lupita', get_config('local_jedomi', 'speech_voice'));
        $this->assertTrue(model_settings::is_voice_enabled());
    }

    /**
     * V5: with the switch off, an unlisted model or voice is saved as the default one (unlisted → default).
     *
     * @return void
     */
    public function test_save_with_voice_off_keeps_a_valid_model_and_voice(): void {
        $this->resetAfterTest();

        (new settings_extension())->save((object) $this->valid_data([
            'voice_enabled' => 0, 'speech_model' => 'jedomi/acme/x',
            model_settings::voice_field(model_settings::DEFAULT_SPEECH_MODEL) => 'Diego',
        ]));

        $this->assertSame('0', get_config('local_jedomi', 'voice_enabled'));
        $this->assertSame(model_settings::DEFAULT_SPEECH_MODEL, get_config('local_jedomi', 'speech_model'));
        $this->assertSame('Vivian', get_config('local_jedomi', 'speech_voice'));
    }
}
