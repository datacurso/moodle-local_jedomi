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
use local_jedomi\ai\ai_service;
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
    /** @var string Default text-to-speech model of the catalogue. */
    private const INWORLD = 'jedomi/inworld-ai/inworld-tts-1.5-mini';

    /** @var string Second text-to-speech model of the catalogue. */
    private const QWEN_TTS = 'jedomi/Qwen/Qwen3-TTS';

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
            'ai_service' => ai_service::DATACURSO,
            'model' => model_settings::DEFAULT_MODEL,
            'files_enabled' => 1,
            'vision_model' => model_settings::DEFAULT_VISION_MODEL,
            'audio_enabled' => 0,
            'audio_model' => model_settings::DEFAULT_AUDIO_MODEL,
            'voice_enabled' => 0,
            'speech_model' => model_settings::DEFAULT_SPEECH_MODEL,
            model_settings::voice_field(self::INWORLD) => 'Rafael',
            model_settings::voice_field(self::QWEN_TTS) => 'Vivian',
            'max_tokens' => '8000',
        ], $overrides);
    }

    /**
     * Values of the form without the service select and the custom service fields.
     *
     * @param array $data Values of get_data().
     * @return array
     */
    private static function datacurso_values(array $data): array {
        return array_filter(
            $data,
            static fn(string $name): bool => $name !== ai_service::CONFIG && !str_starts_with($name, 'custom_'),
            ARRAY_FILTER_USE_KEY
        );
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
        $this->assertStringNotContainsString('name="service_datacurso"', $html);
        $this->assertStringNotContainsString('name="service_custom"', $html);
        $this->assertStringNotContainsString('docservice_url', $html);
        $order = [];
        $fields = ['enabled', 'ai_service', 'model', 'files_enabled', 'vision_model', 'audio_enabled', 'audio_model',
            'voice_enabled', 'speech_model', model_settings::voice_field(self::INWORLD),
            model_settings::voice_field(self::QWEN_TTS), 'max_tokens'];
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
            'audio_model' => '  jedomi/Qwen/Qwen3-ASR-1.7B  ',
            'max_tokens' => 4096,
        ]));

        $this->assertSame('0', get_config('aiprovider_datacurso', 'plugin_enabled_local_jedomi'));
        $this->assertFalse(plugin_settings::is_enabled('local_jedomi'));
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', get_config('local_jedomi', 'model'));
        $this->assertSame('0', get_config('local_jedomi', 'files_enabled'));
        $this->assertSame('jedomi/Qwen/Qwen3-VL-235B-A22B-Instruct', get_config('local_jedomi', 'vision_model'));
        $this->assertSame('1', get_config('local_jedomi', 'audio_enabled'));
        $this->assertSame('jedomi/Qwen/Qwen3-ASR-1.7B', get_config('local_jedomi', 'audio_model'));
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
        set_config('speech_voice', 'Miguel', 'local_jedomi');
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
            model_settings::voice_field(self::INWORLD) => 'Miguel',
            model_settings::voice_field(self::QWEN_TTS) => 'Vivian',
            'max_tokens' => 9000,
        ], self::datacurso_values((new settings_extension())->get_data()));

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
            model_settings::voice_field(self::INWORLD) => 'Rafael',
            model_settings::voice_field(self::QWEN_TTS) => 'Vivian',
            'max_tokens' => model_settings::DEFAULT_MAX_TOKENS,
        ], self::datacurso_values((new settings_extension())->get_data()));
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
     * MDL-UNIT-104: the speech model selector is shown only with the voice conversation on.
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
            ['speech_model', model_settings::voice_field(self::INWORLD),
                model_settings::voice_field(self::QWEN_TTS)],
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
     * MDL-UNIT-104 / MDL-UNIT-105: only the voice selector of the chosen model is shown; it lists only the voices of its model,
     * without an empty option and with its default voice selected.
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
        foreach ([self::INWORLD, self::QWEN_TTS] as $model) {
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
     * MDL-UNIT-105: each voice selector preselects the saved voice or the default voice of its model.
     * V5: the voice selector of the chosen model shows the saved voice, and the one of the other model its default voice.
     *
     * @return void
     */
    public function test_voice_selectors_show_the_saved_and_the_default_voices(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('speech_model', self::QWEN_TTS, 'local_jedomi');
        set_config('speech_voice', 'Serena', 'local_jedomi');

        $form = new plugin_settings_form(null, ['component' => 'local_jedomi']);
        $form->set_data(plugin_settings_form::current_data('local_jedomi'));
        $html = $form->render();

        foreach ([self::QWEN_TTS => 'Serena', self::INWORLD => 'Rafael'] as $model => $voice) {
            $name = model_settings::voice_field($model);
            $this->assertSame(1, preg_match('/<select[^>]*name="' . $name . '"[^>]*>(.*?)<\/select>/s', $html, $match));
            $this->assertStringNotContainsString('value=""', $match[1], "{$name} must not have an empty option");
            $this->assertMatchesRegularExpression('/value="' . $voice . '"\s+selected/', $match[1]);
        }
    }

    /**
     * MDL-UNIT-104: a coherent voice configuration passes the validation.
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
     * MDL-UNIT-104: turning the voice conversation on with audio processing off shows the message that the voice needs audio, so
     * nothing is saved.
     * V5: the voice conversation cannot be turned on while audio processing is off.
     *
     * @return void
     */
    public function test_validation_rejects_voice_without_audio(): void {
        $errors = (new settings_extension())->validate($this->valid_data(['audio_enabled' => 0, 'voice_enabled' => 1]));

        $this->assertSame(['voice_enabled' => get_string('error_voice_requires_audio', 'local_jedomi')], $errors);
    }

    /**
     * MDL-UNIT-104: with the voice on, an unlisted speech model or a voice outside the chosen model is rejected with its message.
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
        $qwen = model_settings::voice_field(self::QWEN_TTS);
        $inworld = model_settings::voice_field(self::INWORLD);
        return [
            'empty model' => [['speech_model' => ''], 'speech_model', 'error_invalid_model'],
            'chat model' => [['speech_model' => model_settings::DEFAULT_MODEL], 'speech_model', 'error_invalid_model'],
            'audio model' => [['speech_model' => model_settings::DEFAULT_AUDIO_MODEL], 'speech_model', 'error_invalid_model'],
            'voice of another model' => [['speech_model' => self::QWEN_TTS, $qwen => 'Rafael'], $qwen, 'error_invalid_voice'],
            'unknown voice' => [['speech_model' => self::INWORLD, $inworld => 'Valentina'], $inworld, 'error_invalid_voice'],
            'empty voice' => [['speech_model' => self::QWEN_TTS, $qwen => ''], $qwen, 'error_invalid_voice'],
            'voice in another case' => [['speech_model' => self::QWEN_TTS, $qwen => 'vivian'], $qwen, 'error_invalid_voice'],
        ];
    }

    /**
     * MDL-UNIT-104: saving keeps the voice of the chosen model.
     * V5: saving stores the switch, the model and the voice chosen for that model in a single setting.
     *
     * @return void
     */
    public function test_save_stores_the_voice_of_the_chosen_model(): void {
        $this->resetAfterTest();

        (new settings_extension())->save((object) $this->valid_data([
            'audio_enabled' => 1, 'voice_enabled' => 1, 'speech_model' => self::INWORLD,
            model_settings::voice_field(self::QWEN_TTS) => 'Serena',
            model_settings::voice_field(self::INWORLD) => 'Lupita',
        ]));

        $this->assertSame('1', get_config('local_jedomi', 'voice_enabled'));
        $this->assertSame(self::INWORLD, get_config('local_jedomi', 'speech_model'));
        $this->assertSame('Lupita', get_config('local_jedomi', 'speech_voice'));
        $this->assertTrue(model_settings::is_voice_enabled());
    }

    /**
     * MDL-UNIT-104 / MDL-UNIT-105: with the voice off an unlisted speech model is saved as the default model, and a
     * voice that is not one of the default model (Vivian) is saved as its default voice, Rafael.
     * V5: with the switch off, an unlisted model or voice is saved as the default one (unlisted → default).
     *
     * @return void
     */
    public function test_save_with_voice_off_replaces_an_unlisted_model_and_voice_with_the_defaults(): void {
        $this->resetAfterTest();

        (new settings_extension())->save((object) $this->valid_data([
            'voice_enabled' => 0, 'speech_model' => 'jedomi/acme/x',
            model_settings::voice_field(model_settings::DEFAULT_SPEECH_MODEL) => 'Vivian',
        ]));

        $this->assertSame('0', get_config('local_jedomi', 'voice_enabled'));
        $this->assertSame(model_settings::DEFAULT_SPEECH_MODEL, get_config('local_jedomi', 'speech_model'));
        $this->assertSame('Rafael', get_config('local_jedomi', 'speech_voice'));
    }

    /**
     * Valid submitted values with the custom service chosen.
     *
     * @param array $overrides Values replacing the defaults.
     * @return array
     */
    private function custom_data(array $overrides = []): array {
        return $this->valid_data(array_merge([
            'ai_service' => ai_service::CUSTOM,
            'custom_gateway_url' => 'https://api.example.com/v1',
            'custom_api_key' => 'sk-new-key',
            'custom_allow_http' => 0,
            'custom_verified' => 1,
            'custom_model' => 'meta-llama/Llama-3.3-70B-Instruct',
            'custom_vision_model' => '',
            'custom_audio_model' => '',
            'custom_speech_model' => '',
            'custom_speech_voice' => '',
            'custom_max_tokens' => '',
        ], $overrides));
    }

    /**
     * Allow any public address without resolving it, and remember the address and key of custom_data() as verified.
     *
     * @return void
     */
    private function verify_custom_pair(): void {
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        ai_service::remember_verified('https://api.example.com', 'sk-new-key');
    }

    /**
     * One "AI service" select comes right after "Enabled", with exactly the Datacurso service (the default) and the
     * custom service; each option shows only its own fields.
     *
     * @return void
     */
    public function test_service_select_shows_only_its_fields(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mform = new \MoodleQuickForm('jedomitest', 'post', '');
        (new settings_extension())->add_fields($mform);

        $select = $mform->getElement('ai_service');
        $this->assertSame('select', $select->getType());
        $this->assertSame(get_string('ai_service', 'local_jedomi'), $select->getLabel());
        $options = [];
        foreach ($select->_options as $option) {
            $options[$option['attr']['value']] = $option['text'];
        }
        $this->assertSame([
            ai_service::DATACURSO => get_string('service_datacurso', 'local_jedomi'),
            ai_service::CUSTOM => get_string('service_custom', 'local_jedomi'),
        ], $options);
        $this->assertSame(ai_service::DATACURSO, $mform->_defaultValues['ai_service']);
        $this->assertFalse($mform->elementExists('service_datacurso'));
        $this->assertFalse($mform->elementExists('service_custom'));
        [, $dependencies] = $mform->getLockOptionObject();
        $hide = \MoodleQuickForm::DEP_HIDE;
        $datacurso = $dependencies['ai_service']['neq'][ai_service::DATACURSO][$hide];
        foreach (
            ['model', 'files_enabled', 'vision_model', 'audio_enabled', 'audio_model', 'voice_enabled', 'speech_model',
                model_settings::voice_field(self::INWORLD), 'max_tokens'] as $field
        ) {
            $this->assertContains($field, $datacurso);
        }
        $custom = $dependencies['ai_service']['neq'][ai_service::CUSTOM][$hide];
        $models = ['custom_model', 'custom_vision_model', 'custom_audio_model', 'custom_speech_model', 'custom_speech_voice',
            'custom_max_tokens'];
        foreach (array_merge(['custom_gateway_url', 'custom_api_key', 'custom_allow_http', 'custom_verify'], $models) as $field) {
            $this->assertContains($field, $custom);
            $this->assertNotContains($field, $datacurso);
        }
        // The model fields of the custom service appear only once the connection is verified.
        $this->assertSame($models, $dependencies['custom_verified']['neq']['1'][$hide]);
        // The custom model fields are free text, with no catalogue.
        $this->assertSame('text', $mform->getElement('custom_model')->getType());
    }

    /**
     * Only the fields of the chosen service are validated.
     *
     * @return void
     */
    public function test_validation_follows_the_chosen_service(): void {
        $this->resetAfterTest();
        $this->verify_custom_pair();
        $extension = new settings_extension();
        $bad = ['model' => 'jedomi/acme/unknown', 'max_tokens' => 'abc'];

        $this->assertArrayHasKey('model', $extension->validate($this->valid_data($bad)));
        // With the custom service chosen the Datacurso fields are neither checked nor saved.
        $this->assertSame([], $extension->validate($this->custom_data($bad)));
    }

    /**
     * The custom service needs the verified address and key; a changed address must be verified again.
     *
     * @return void
     */
    public function test_custom_service_must_be_verified(): void {
        $this->resetAfterTest();
        $this->verify_custom_pair();
        $extension = new settings_extension();

        $this->assertSame([], $extension->validate($this->custom_data()));
        $this->assertSame(
            ['custom_gateway_url' => get_string('error_custom_not_verified', 'local_jedomi')],
            $extension->validate($this->custom_data(['custom_gateway_url' => 'https://other.example.com']))
        );
        $this->assertSame(
            ['custom_gateway_url' => get_string('error_custom_not_verified', 'local_jedomi')],
            $extension->validate($this->custom_data(['custom_api_key' => 'sk-other']))
        );
        $this->assertSame(
            ['custom_gateway_url' => get_string('custom_verify_invalid_url', 'local_jedomi')],
            $extension->validate($this->custom_data(['custom_gateway_url' => 'not a url']))
        );
        $this->assertSame(
            ['custom_gateway_url' => get_string('custom_verify_http_not_allowed', 'local_jedomi')],
            $extension->validate($this->custom_data(['custom_gateway_url' => 'http://api.example.com']))
        );
    }

    /**
     * An empty key keeps the saved key, but only for the saved address.
     *
     * @return void
     */
    public function test_empty_key_keeps_the_saved_key_only_for_the_saved_address(): void {
        $this->resetAfterTest();
        $this->verify_custom_pair();
        set_config('custom_gateway_url', 'https://api.example.com', 'local_jedomi');
        set_config('custom_api_key', 'sk-new-key', 'local_jedomi');
        $extension = new settings_extension();

        $this->assertSame([], $extension->validate($this->custom_data(['custom_api_key' => ''])));
        $this->assertSame(
            ['custom_api_key' => get_string('custom_verify_key_required', 'local_jedomi')],
            $extension->validate($this->custom_data(['custom_api_key' => '', 'custom_gateway_url' => 'https://b.example.com']))
        );

        $extension->save((object) $this->custom_data(['custom_api_key' => '']));
        $this->assertSame('sk-new-key', get_config('local_jedomi', 'custom_api_key'));
    }

    /**
     * The model ids of the custom service are free text, but must be plausible ids; voice needs audio and a voice.
     *
     * @dataProvider bad_custom_model_provider
     * @param array $overrides Submitted values.
     * @param array $expected Expected errors as [field => string key].
     * @return void
     */
    public function test_custom_model_validation(array $overrides, array $expected): void {
        $this->resetAfterTest();
        $this->verify_custom_pair();

        $errors = (new settings_extension())->validate($this->custom_data($overrides));

        $this->assertSame(array_map(static fn(string $key): string => get_string($key, 'local_jedomi'), $expected), $errors);
    }

    /**
     * Custom model values and their errors.
     *
     * @return array
     */
    public static function bad_custom_model_provider(): array {
        return [
            'no chat model' => [['custom_model' => ''], ['custom_model' => 'error_custom_model_required']],
            'spaces in a model' => [['custom_model' => 'my model'], ['custom_model' => 'error_custom_model_invalid']],
            'markup in a model' => [['custom_vision_model' => '<b>x</b>'], ['custom_vision_model' => 'error_custom_model_invalid']],
            'speech without audio' => [['custom_speech_model' => 'tts', 'custom_speech_voice' => 'af'],
                ['custom_speech_model' => 'error_voice_requires_audio']],
            'speech without voice' => [['custom_audio_model' => 'whisper', 'custom_speech_model' => 'tts'],
                ['custom_speech_voice' => 'error_custom_voice_required']],
            'bad max tokens' => [['custom_max_tokens' => '12.5'], ['custom_max_tokens' => 'error_invalid_max_tokens']],
        ];
    }

    /**
     * Saving the custom service stores its own settings and keeps the Datacurso ones untouched.
     *
     * @return void
     */
    public function test_save_custom_service_keeps_the_datacurso_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        $extension = new settings_extension();

        $extension->save((object) $this->custom_data([
            'model' => model_settings::DEFAULT_MODEL,
            'custom_vision_model' => ' Qwen/Qwen2.5-VL-7B-Instruct ',
            'custom_max_tokens' => '999999',
        ]));

        $this->assertSame(ai_service::CUSTOM, get_config('local_jedomi', 'ai_service'));
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', get_config('local_jedomi', 'model'));
        $this->assertSame('https://api.example.com/v1', get_config('local_jedomi', 'custom_gateway_url'));
        $this->assertSame('sk-new-key', get_config('local_jedomi', 'custom_api_key'));
        $this->assertSame('meta-llama/Llama-3.3-70B-Instruct', get_config('local_jedomi', 'custom_model'));
        $this->assertSame('Qwen/Qwen2.5-VL-7B-Instruct', get_config('local_jedomi', 'custom_vision_model'));
        $this->assertSame((string) model_settings::MAX_MAX_TOKENS, get_config('local_jedomi', 'custom_max_tokens'));
        $this->assertSame('0', get_config('local_jedomi', 'custom_allow_http'));
        // The key is logged masked, never in clear.
        $logs = $DB->get_records('config_log', ['plugin' => 'local_jedomi', 'name' => 'custom_api_key']);
        $this->assertCount(1, $logs);
        $this->assertSame('********', reset($logs)->value);

        // Switching back to Datacurso finds its settings as they were, and the custom ones are kept.
        $extension->save((object) $this->valid_data(['model' => 'jedomi/zai-org/GLM-5.3-Flash']));
        $this->assertSame(ai_service::DATACURSO, get_config('local_jedomi', 'ai_service'));
        $this->assertSame('meta-llama/Llama-3.3-70B-Instruct', get_config('local_jedomi', 'custom_model'));
        $this->assertSame('sk-new-key', get_config('local_jedomi', 'custom_api_key'));
    }

    /**
     * There is no "no service" choice: a submission without the select, or with any other value, keeps the Datacurso
     * service. Jedomi is switched off with "Enabled" of the provider instead.
     *
     * @return void
     */
    public function test_save_without_a_valid_service_keeps_datacurso(): void {
        $this->resetAfterTest();
        $extension = new settings_extension();

        foreach (['none', '', 'other'] as $value) {
            set_config('ai_service', ai_service::CUSTOM, 'local_jedomi');
            $extension->save((object) $this->valid_data(['ai_service' => $value]));
            $this->assertSame(ai_service::DATACURSO, get_config('local_jedomi', 'ai_service'), $value);
        }
        $data = $this->valid_data();
        unset($data['ai_service']);
        set_config('ai_service', ai_service::CUSTOM, 'local_jedomi');
        $extension->save((object) $data);
        $this->assertSame(ai_service::DATACURSO, get_config('local_jedomi', 'ai_service'));
        $this->assertFalse(get_config('local_jedomi', 'custom_model'));
    }

    /**
     * The saved key is never written back into the page; the field shows that a key is saved.
     *
     * @return void
     */
    public function test_saved_key_never_reaches_the_page(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->verify_custom_pair();
        set_config('ai_service', ai_service::CUSTOM, 'local_jedomi');
        set_config('custom_gateway_url', 'https://api.example.com', 'local_jedomi');
        set_config('custom_api_key', 'sk-new-key', 'local_jedomi');
        set_config('custom_model', 'llama', 'local_jedomi');

        $data = plugin_settings_form::current_data('local_jedomi');
        $form = new plugin_settings_form(null, ['component' => 'local_jedomi']);
        $form->set_data($data);
        $html = $form->render();

        $this->assertArrayNotHasKey('custom_api_key', $data);
        $this->assertStringNotContainsString('sk-new-key', $html);
        $this->assertStringContainsString(s(get_string('custom_api_key_saved', 'local_jedomi')), $html);
        $this->assertSame(ai_service::CUSTOM, $data['ai_service']);
        $this->assertSame(1, $data['custom_verified'], 'The saved address and key were verified');
        $this->assertSame('llama', $data['custom_model']);
        $this->assertSame('', $data['custom_vision_model']);
    }

    /**
     * On a site that never chose, or that kept the retired "none" value, the Datacurso service is selected.
     *
     * @return void
     */
    public function test_get_data_defaults_to_datacurso(): void {
        $this->resetAfterTest();
        set_config('ai_service', 'none', 'local_jedomi');
        $this->assertSame(ai_service::DATACURSO, (new settings_extension())->get_data()['ai_service']);
        unset_config('ai_service', 'local_jedomi');

        $data = (new settings_extension())->get_data();

        $this->assertSame(ai_service::DATACURSO, $data['ai_service']);
        $this->assertArrayNotHasKey('service_datacurso', $data);
        $this->assertArrayNotHasKey('service_custom', $data);
        $this->assertSame(0, $data['custom_verified']);
        $this->assertSame('', $data['custom_gateway_url']);
    }
}
