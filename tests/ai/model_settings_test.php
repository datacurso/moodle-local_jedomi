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

namespace local_jedomi\ai;

/**
 * Tests for the AI model settings and the migration of the legacy gateway settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\model_settings
 */
final class model_settings_test extends \advanced_testcase {
    /**
     * Seed the settings an old site has.
     *
     * @param string $model Model value to seed.
     */
    private function seed_legacy_settings(string $model): void {
        set_config('gateway_url', 'http://localhost:8765', 'local_jedomi');
        set_config('api_key', 'legacy-key-value', 'local_jedomi');
        set_config('model', $model, 'local_jedomi');
        set_config('max_tokens', 4096, 'local_jedomi');
        set_config('spt_secret', 'spt-secret-value', 'local_jedomi');
        set_config('enabled', 1, 'local_jedomi');
    }

    /**
     * Load the plugin upgrade function and the upgrade library it uses.
     */
    private function load_upgrade_script(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/jedomi/db/upgrade.php');
    }

    /**
     * Assert the settings the migration must not touch are unchanged.
     */
    private function assert_other_settings_unchanged(): void {
        $this->assertSame('4096', get_config('local_jedomi', 'max_tokens'));
        $this->assertSame('spt-secret-value', get_config('local_jedomi', 'spt_secret'));
        $this->assertSame('1', get_config('local_jedomi', 'enabled'));
    }

    /**
     * MDL-INT-001: the old connection settings of the plugin are removed.
     * MDL-INT-086: the update removes the obsolete gateway settings.
     * M8: the gateway URL and key are removed and a legacy model becomes the default model.
     */
    public function test_migration_removes_gateway_settings_and_replaces_legacy_model(): void {
        $this->resetAfterTest();
        $this->seed_legacy_settings('jedomi-e4b');

        model_settings::migrate_legacy_settings();

        $this->assertFalse(get_config('local_jedomi', 'gateway_url'));
        $this->assertFalse(get_config('local_jedomi', 'api_key'));
        $this->assertSame(model_settings::DEFAULT_MODEL, get_config('local_jedomi', 'model'));
        $this->assert_other_settings_unchanged();
    }

    /**
     * M8: a model that already uses the jedomi/ route is kept as is.
     */
    public function test_migration_keeps_model_with_jedomi_prefix(): void {
        $this->resetAfterTest();
        $this->seed_legacy_settings('jedomi/deepseek-ai/DeepSeek-V4.1-Flash-1017');

        model_settings::migrate_legacy_settings();

        $this->assertSame('jedomi/deepseek-ai/DeepSeek-V4.1-Flash-1017', get_config('local_jedomi', 'model'));
        $this->assertFalse(get_config('local_jedomi', 'gateway_url'));
        $this->assertFalse(get_config('local_jedomi', 'api_key'));
        $this->assert_other_settings_unchanged();
    }

    /**
     * M8: a site that never set a model gets the default one.
     */
    public function test_migration_sets_default_model_when_missing(): void {
        $this->resetAfterTest();
        unset_config('model', 'local_jedomi');

        model_settings::migrate_legacy_settings();

        $this->assertSame(model_settings::DEFAULT_MODEL, get_config('local_jedomi', 'model'));
    }

    /**
     * V7: the selector offers exactly the phase 7 list, with the default model first.
     */
    public function test_available_models_are_the_final_list_with_default_first(): void {
        $models = model_settings::get_available_models();

        $this->assertSame('jedomi/google/gemma-4-26B-A4B-it', model_settings::DEFAULT_MODEL);
        $this->assertSame([
            'jedomi/google/gemma-4-26B-A4B-it',
            'jedomi/deepseek-ai/DeepSeek-V4.1-Flash-1017',
            'jedomi/zai-org/GLM-5.3-Flash',
            'jedomi/XiaomiMiMo/MiMo-V2.6-Flash',
            'jedomi/moonshotai/Kimi-K3.1-Pro',
        ], array_keys($models));
        $this->assertSame(model_settings::DEFAULT_MODEL, array_key_first($models));
        foreach ($models as $model => $name) {
            $this->assertTrue(model_settings::is_routable($model));
            $this->assertNotEmpty($name);
        }
    }

    /**
     * MDL-UNIT-003: every listed model is used as saved.
     */
    public function test_get_model_returns_saved_listed_model(): void {
        $this->resetAfterTest();

        foreach (array_keys(model_settings::get_available_models()) as $model) {
            set_config('model', $model, 'local_jedomi');
            $this->assertSame($model, model_settings::get_model());
        }
    }

    /**
     * MDL-UNIT-003: a saved model outside the list, including a removed one or another case, falls back to the default.
     *
     * @dataProvider unlisted_model_provider
     * @param string $model Saved model.
     */
    public function test_get_model_falls_back_to_default_for_unlisted_model(string $model): void {
        $this->resetAfterTest();
        set_config('model', $model, 'local_jedomi');

        $this->assertSame(model_settings::DEFAULT_MODEL, model_settings::get_model());
    }

    /**
     * Saved models that are not in the list.
     *
     * @return array<string, array{0: string}>
     */
    public static function unlisted_model_provider(): array {
        return [
            'removed mistral' => ['jedomi/mistralai/Mistral-Small-3.2-24B-Instruct-2506'],
            'other case' => ['jedomi/google/gemma-4-26b-a4b-it'],
            'unknown routable' => ['jedomi/acme/unknown-model'],
            'legacy' => ['jedomi-e4b'],
            'empty' => [''],
        ];
    }

    /**
     * A saved model outside the list is replaced with the default; a listed one is kept.
     */
    public function test_reset_unlisted_model(): void {
        $this->resetAfterTest();

        set_config('model', 'jedomi/mistralai/Mistral-Small-3.2-24B-Instruct-2506', 'local_jedomi');
        model_settings::reset_unlisted_model();
        $this->assertSame(model_settings::DEFAULT_MODEL, get_config('local_jedomi', 'model'));

        set_config('model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        model_settings::reset_unlisted_model();
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', get_config('local_jedomi', 'model'));
    }

    /**
     * The 2026102801 upgrade step replaces a saved model outside the list with the default one.
     * MDL-INT-086: an invalid model is replaced by the default model.
     */
    public function test_upgrade_replaces_unlisted_model_with_default(): void {
        $this->resetAfterTest();
        $this->load_upgrade_script();
        set_config('version', 2026102800, 'local_jedomi');
        set_config('model', 'jedomi/mistralai/Mistral-Small-3.2-24B-Instruct-2506', 'local_jedomi');
        set_config('max_tokens', 4096, 'local_jedomi');

        $this->assertTrue(xmldb_local_jedomi_upgrade(2026102800));

        $this->assertSame(model_settings::DEFAULT_MODEL, get_config('local_jedomi', 'model'));
        $this->assertSame('4096', get_config('local_jedomi', 'max_tokens'));
        // Later upgrade steps run too, so the stored version is at least the one of this step.
        $this->assertGreaterThanOrEqual(2026102801, (int) get_config('local_jedomi', 'version'));
    }

    /**
     * The 2026102801 upgrade step keeps a saved model that is in the list.
     * MDL-INT-086: a valid model is kept.
     */
    public function test_upgrade_keeps_listed_model(): void {
        $this->resetAfterTest();
        $this->load_upgrade_script();
        set_config('version', 2026102800, 'local_jedomi');
        set_config('model', 'jedomi/XiaomiMiMo/MiMo-V2.6-Flash', 'local_jedomi');

        $this->assertTrue(xmldb_local_jedomi_upgrade(2026102800));

        $this->assertSame('jedomi/XiaomiMiMo/MiMo-V2.6-Flash', get_config('local_jedomi', 'model'));
    }

    /**
     * The max tokens setting is used, with the default when unset or too small.
     */
    public function test_max_tokens_uses_setting_with_safe_default(): void {
        $this->resetAfterTest();

        unset_config('max_tokens', 'local_jedomi');
        $this->assertSame(model_settings::DEFAULT_MAX_TOKENS, model_settings::get_max_tokens());

        set_config('max_tokens', 100, 'local_jedomi');
        $this->assertSame(model_settings::DEFAULT_MAX_TOKENS, model_settings::get_max_tokens());

        set_config('max_tokens', 8000, 'local_jedomi');
        $this->assertSame(8000, model_settings::get_max_tokens());
    }

    /**
     * MDL-UNIT-003: each selector lists only the models with its capability, with its default model first.
     */
    public function test_each_capability_lists_only_its_models_with_default_first(): void {
        $this->assertSame('jedomi/Qwen/Qwen3-VL-30B-A3B-Instruct', model_settings::DEFAULT_VISION_MODEL);
        $this->assertSame('jedomi/openai/whisper-large-v3-turbo', model_settings::DEFAULT_AUDIO_MODEL);

        $this->assertSame([
            'jedomi/google/gemma-4-26B-A4B-it',
            'jedomi/deepseek-ai/DeepSeek-V4.1-Flash-1017',
            'jedomi/zai-org/GLM-5.3-Flash',
            'jedomi/XiaomiMiMo/MiMo-V2.6-Flash',
            'jedomi/moonshotai/Kimi-K3.1-Pro',
        ], array_keys(model_settings::get_available_models(model_settings::CAPABILITY_CHAT)));
        $this->assertSame([
            'jedomi/Qwen/Qwen3-VL-30B-A3B-Instruct',
            'jedomi/Qwen/Qwen3-VL-235B-A22B-Instruct',
            'jedomi/google/gemma-4-26B-A4B-it',
            'jedomi/deepseek-ai/DeepSeek-V4.1-Flash-1017',
            'jedomi/zai-org/GLM-5.3-Flash',
            'jedomi/XiaomiMiMo/MiMo-V2.6-Flash',
            'jedomi/moonshotai/Kimi-K3.1-Pro',
        ], array_keys(model_settings::get_available_models(model_settings::CAPABILITY_VISION)));
        $this->assertSame([
            'jedomi/openai/whisper-large-v3-turbo',
            'jedomi/Qwen/Qwen3-ASR-1.7B',
            'jedomi/mistralai/Voxtral-Mini-3B-2507',
        ], array_keys(model_settings::get_available_models(model_settings::CAPABILITY_AUDIO)));

        $this->assertSame(
            'Qwen3-VL 30B A3B',
            model_settings::get_available_models(model_settings::CAPABILITY_VISION)[model_settings::DEFAULT_VISION_MODEL]
        );
        $this->assertSame(
            'Whisper large-v3 turbo',
            model_settings::get_available_models(model_settings::CAPABILITY_AUDIO)[model_settings::DEFAULT_AUDIO_MODEL]
        );
        foreach (array_keys(model_settings::get_catalogue()) as $model) {
            $this->assertTrue(model_settings::is_routable($model));
        }
    }

    /**
     * MDL-UNIT-003: a multimodal model is available for each of its capabilities and not for the others.
     */
    public function test_availability_depends_on_the_capability(): void {
        $gemma = 'jedomi/google/gemma-4-26B-A4B-it';
        $this->assertTrue(model_settings::is_available($gemma));
        $this->assertTrue(model_settings::is_available($gemma, model_settings::CAPABILITY_VISION));
        $this->assertFalse(model_settings::is_available($gemma, model_settings::CAPABILITY_AUDIO));
        $this->assertFalse(model_settings::is_available(model_settings::DEFAULT_VISION_MODEL));
        $this->assertFalse(model_settings::is_available(
            model_settings::DEFAULT_AUDIO_MODEL,
            model_settings::CAPABILITY_VISION
        ));
    }

    /**
     * MDL-UNIT-003: the vision and audio models fall back to their own defaults when empty or not in their list.
     */
    public function test_vision_and_audio_models_fall_back_to_their_defaults(): void {
        $this->resetAfterTest();

        unset_config('vision_model', 'local_jedomi');
        unset_config('audio_model', 'local_jedomi');
        $this->assertSame(model_settings::DEFAULT_VISION_MODEL, model_settings::get_vision_model());
        $this->assertSame(model_settings::DEFAULT_AUDIO_MODEL, model_settings::get_audio_model());

        set_config('vision_model', 'jedomi/Qwen/Qwen3-ASR-1.7B', 'local_jedomi');
        set_config('audio_model', '', 'local_jedomi');
        $this->assertSame(model_settings::DEFAULT_VISION_MODEL, model_settings::get_vision_model());
        $this->assertSame(model_settings::DEFAULT_AUDIO_MODEL, model_settings::get_audio_model());

        set_config('vision_model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        set_config('audio_model', 'jedomi/Qwen/Qwen3-ASR-1.7B', 'local_jedomi');
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', model_settings::get_vision_model());
        $this->assertSame('jedomi/Qwen/Qwen3-ASR-1.7B', model_settings::get_audio_model());
    }

    /**
     * MDL-UNIT-004: file processing is on and audio processing is off until they are saved.
     */
    public function test_feature_switches_have_their_defaults(): void {
        $this->resetAfterTest();

        unset_config('files_enabled', 'local_jedomi');
        unset_config('audio_enabled', 'local_jedomi');
        $this->assertTrue(model_settings::is_files_enabled());
        $this->assertFalse(model_settings::is_audio_enabled());

        set_config('files_enabled', 0, 'local_jedomi');
        set_config('audio_enabled', 1, 'local_jedomi');
        $this->assertFalse(model_settings::is_files_enabled());
        $this->assertTrue(model_settings::is_audio_enabled());
    }

    /**
     * M6: the 2026110301 upgrade step saves the defaults of the new settings and removes docservice_url.
     * MDL-INT-086: the update stores the missing file and audio defaults and removes the document conversion setting.
     */
    public function test_upgrade_saves_media_defaults_and_removes_docservice_url(): void {
        $this->resetAfterTest();
        $this->load_upgrade_script();
        set_config('version', 2026110300, 'local_jedomi');
        set_config('docservice_url', 'http://localhost:8000', 'local_jedomi');
        unset_config('files_enabled', 'local_jedomi');
        unset_config('audio_enabled', 'local_jedomi');
        unset_config('vision_model', 'local_jedomi');
        unset_config('audio_model', 'local_jedomi');

        $this->assertTrue(xmldb_local_jedomi_upgrade(2026110300));

        $this->assertSame('1', get_config('local_jedomi', 'files_enabled'));
        $this->assertSame('0', get_config('local_jedomi', 'audio_enabled'));
        $this->assertSame(model_settings::DEFAULT_VISION_MODEL, get_config('local_jedomi', 'vision_model'));
        $this->assertSame(model_settings::DEFAULT_AUDIO_MODEL, get_config('local_jedomi', 'audio_model'));
        $this->assertFalse(get_config('local_jedomi', 'docservice_url'));
    }

    /**
     * M6: the upgrade step keeps values an administrator already saved.
     * MDL-INT-086: the update keeps the saved file and audio settings.
     */
    public function test_upgrade_keeps_saved_media_settings(): void {
        $this->resetAfterTest();
        $this->load_upgrade_script();
        set_config('version', 2026110300, 'local_jedomi');
        set_config('files_enabled', 0, 'local_jedomi');
        set_config('audio_enabled', 1, 'local_jedomi');
        set_config('vision_model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        set_config('audio_model', 'jedomi/Qwen/Qwen3-ASR-1.7B', 'local_jedomi');

        $this->assertTrue(xmldb_local_jedomi_upgrade(2026110300));

        $this->assertSame('0', get_config('local_jedomi', 'files_enabled'));
        $this->assertSame('1', get_config('local_jedomi', 'audio_enabled'));
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', get_config('local_jedomi', 'vision_model'));
        $this->assertSame('jedomi/Qwen/Qwen3-ASR-1.7B', get_config('local_jedomi', 'audio_model'));
    }

    /**
     * MDL-UNIT-105: the speech selector lists only speech models with the default first, each with its own voices.
     * V6: the speech selector lists the text-to-speech models with the default one first, each with its voices.
     */
    public function test_speech_catalogue_lists_its_models_and_voices(): void {
        $this->assertSame('jedomi/inworld-ai/inworld-tts-1.5-mini', model_settings::DEFAULT_SPEECH_MODEL);
        $inworld = model_settings::DEFAULT_SPEECH_MODEL;
        $qwen = 'jedomi/Qwen/Qwen3-TTS';

        $models = model_settings::get_available_models(model_settings::CAPABILITY_SPEECH);

        $this->assertSame([$inworld => 'Inworld TTS 1.5 mini', $qwen => 'Qwen3-TTS'], $models);
        $this->assertContains(model_settings::CAPABILITY_SPEECH, model_settings::CAPABILITIES);
        $this->assertSame('speech_model', model_settings::MODEL_SETTINGS[model_settings::CAPABILITY_SPEECH]);
        $this->assertSame(
            ['Vivian', 'Serena', 'Uncle_Fu', 'Dylan', 'Eric', 'Ryan', 'Aiden', 'Ono_Anna', 'Sohee'],
            model_settings::get_voices($qwen)
        );
        $this->assertSame(['Rafael', 'Diego', 'Lupita', 'Miguel'], model_settings::get_voices($inworld));
        $this->assertSame('Vivian', model_settings::get_default_voice($qwen));
        $this->assertSame('Rafael', model_settings::get_default_voice($inworld));
        $this->assertSame([], model_settings::get_voices(model_settings::DEFAULT_MODEL));
        $this->assertSame('', model_settings::get_default_voice(model_settings::DEFAULT_MODEL));
        $this->assertSame('speech_voice_qwen_qwen3_tts', model_settings::voice_field($qwen));
        $this->assertSame('speech_voice_inworld_ai_inworld_tts_1_5_mini', model_settings::voice_field($inworld));
        foreach (array_keys($models) as $model) {
            $this->assertTrue(model_settings::is_routable($model));
        }
    }

    /**
     * MDL-UNIT-105: speech models never appear in the chat, file and image, or audio selectors.
     * V6: a speech model never appears in the chat, vision or audio selectors, and no other model in the speech one.
     */
    public function test_speech_models_are_only_in_the_speech_selector(): void {
        $speech = array_keys(model_settings::get_available_models(model_settings::CAPABILITY_SPEECH));
        foreach ([model_settings::CAPABILITY_CHAT, model_settings::CAPABILITY_VISION, model_settings::CAPABILITY_AUDIO] as $other) {
            $models = array_keys(model_settings::get_available_models($other));
            $this->assertSame([], array_intersect($speech, $models), "No speech model in the {$other} selector");
            foreach ($speech as $model) {
                $this->assertFalse(model_settings::is_available($model, $other));
            }
        }
        $this->assertFalse(model_settings::is_available(model_settings::DEFAULT_MODEL, model_settings::CAPABILITY_SPEECH));
        $this->assertFalse(model_settings::is_available(model_settings::DEFAULT_AUDIO_MODEL, model_settings::CAPABILITY_SPEECH));
    }

    /**
     * MDL-UNIT-105: a saved model or voice no longer listed is replaced by the default, so an unlisted value never reaches the
     * service.
     * V5: the speech model and voice fall back to the defaults when empty or not in their list, and a voice of
     * another model falls back to the default voice of the configured model.
     */
    public function test_speech_model_and_voice_fall_back_to_their_defaults(): void {
        $this->resetAfterTest();
        $qwen = 'jedomi/Qwen/Qwen3-TTS';

        unset_config('speech_model', 'local_jedomi');
        unset_config('speech_voice', 'local_jedomi');
        $this->assertSame(model_settings::DEFAULT_SPEECH_MODEL, model_settings::get_speech_model());
        $this->assertSame('Rafael', model_settings::get_speech_voice());

        set_config('speech_model', model_settings::DEFAULT_AUDIO_MODEL, 'local_jedomi');
        set_config('speech_voice', 'Lupita', 'local_jedomi');
        $this->assertSame(model_settings::DEFAULT_SPEECH_MODEL, model_settings::get_speech_model());
        $this->assertSame('Lupita', model_settings::get_speech_voice());

        set_config('speech_model', $qwen, 'local_jedomi');
        $this->assertSame($qwen, model_settings::get_speech_model());
        $this->assertSame('Vivian', model_settings::get_speech_voice(), 'A voice of another model uses the default voice');

        set_config('speech_voice', 'Serena', 'local_jedomi');
        $this->assertSame('Serena', model_settings::get_speech_voice());

        set_config('speech_voice', 'serena', 'local_jedomi');
        $this->assertSame('Vivian', model_settings::get_speech_voice(), 'Voices are case-sensitive');
    }

    /**
     * MDL-UNIT-105: on a site without configuration the voice conversation is off.
     * V5: the voice conversation is off until it is saved, and also off while audio processing is off.
     */
    public function test_voice_conversation_is_off_by_default_and_needs_audio(): void {
        $this->resetAfterTest();

        unset_config('voice_enabled', 'local_jedomi');
        set_config('audio_enabled', 1, 'local_jedomi');
        $this->assertFalse(model_settings::is_voice_enabled());

        set_config('voice_enabled', 1, 'local_jedomi');
        $this->assertTrue(model_settings::is_voice_enabled());

        set_config('audio_enabled', 0, 'local_jedomi');
        $this->assertFalse(model_settings::is_voice_enabled(), 'Without audio processing nothing can be transcribed');
    }

    /**
     * MDL-INT-086: the upgrade leaves the voice conversation off with the default model and voice.
     * V5: the 2026111100 upgrade step leaves the voice conversation off, with the default model and its default voice.
     */
    public function test_upgrade_saves_voice_defaults(): void {
        $this->resetAfterTest();
        $this->load_upgrade_script();
        set_config('version', 2026111000, 'local_jedomi');
        unset_config('voice_enabled', 'local_jedomi');
        unset_config('speech_model', 'local_jedomi');
        unset_config('speech_voice', 'local_jedomi');

        $this->assertTrue(xmldb_local_jedomi_upgrade(2026111000));

        $this->assertSame('0', get_config('local_jedomi', 'voice_enabled'));
        $this->assertSame(model_settings::DEFAULT_SPEECH_MODEL, get_config('local_jedomi', 'speech_model'));
        $this->assertSame('Rafael', get_config('local_jedomi', 'speech_voice'));
        $this->assertGreaterThanOrEqual(2026111100, (int) get_config('local_jedomi', 'version'));
    }

    /**
     * MDL-INT-086: the upgrade does not touch voice values already saved.
     * V5: the upgrade step keeps the voice settings an administrator already saved.
     */
    public function test_upgrade_keeps_saved_voice_settings(): void {
        $this->resetAfterTest();
        $this->load_upgrade_script();
        set_config('version', 2026111000, 'local_jedomi');
        set_config('voice_enabled', 1, 'local_jedomi');
        set_config('speech_model', 'jedomi/Qwen/Qwen3-TTS', 'local_jedomi');
        set_config('speech_voice', 'Serena', 'local_jedomi');

        $this->assertTrue(xmldb_local_jedomi_upgrade(2026111000));

        $this->assertSame('1', get_config('local_jedomi', 'voice_enabled'));
        $this->assertSame('jedomi/Qwen/Qwen3-TTS', get_config('local_jedomi', 'speech_model'));
        $this->assertSame('Serena', get_config('local_jedomi', 'speech_voice'));
    }
}
