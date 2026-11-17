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
 * Tests for the choice of AI service and the settings of the custom service.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\ai_service
 * @covers     \local_jedomi\ai\model_settings
 */
final class ai_service_test extends \advanced_testcase {
    /**
     * Save a custom service with every model.
     *
     * @return void
     */
    private function configure_custom(): void {
        set_config('ai_service', ai_service::CUSTOM, 'local_jedomi');
        set_config('custom_gateway_url', 'https://api.example.com', 'local_jedomi');
        set_config('custom_api_key', 'sk-test-secret', 'local_jedomi');
        set_config('custom_model', 'meta-llama/Llama-3.3-70B-Instruct', 'local_jedomi');
        set_config('custom_vision_model', 'Qwen/Qwen2.5-VL-7B-Instruct', 'local_jedomi');
        set_config('custom_audio_model', 'openai/whisper-large-v3', 'local_jedomi');
        set_config('custom_speech_model', 'hexgrad/Kokoro-82M', 'local_jedomi');
        set_config('custom_speech_voice', 'af_bella', 'local_jedomi');
        set_config('custom_max_tokens', '2048', 'local_jedomi');
    }

    /**
     * A site that never chose keeps the Datacurso service, as before this setting existed.
     *
     * @return void
     */
    public function test_datacurso_is_the_default_service(): void {
        $this->resetAfterTest();
        unset_config('ai_service', 'local_jedomi');

        $this->assertSame(ai_service::DATACURSO, ai_service::get_active());
        $this->assertFalse(ai_service::is_custom());

        set_config('ai_service', 'unknown', 'local_jedomi');
        $this->assertSame(ai_service::DATACURSO, ai_service::get_active());

        // The retired "none" value is no longer a service: Jedomi is switched off with "Enabled" of the provider.
        set_config('ai_service', 'none', 'local_jedomi');
        $this->assertSame(ai_service::DATACURSO, ai_service::get_active());
        $this->assertFalse(defined(ai_service::class . '::NONE'));
        $this->assertFalse(method_exists(ai_service::class, 'is_none'));
    }

    /**
     * With the custom service, Jedomi uses its free-text models, voice and max tokens, never the Datacurso catalogue.
     *
     * @return void
     */
    public function test_model_settings_follow_the_custom_service(): void {
        $this->resetAfterTest();
        set_config('model', model_settings::DEFAULT_MODEL, 'local_jedomi');
        $this->configure_custom();

        $this->assertSame('meta-llama/Llama-3.3-70B-Instruct', model_settings::get_model());
        $this->assertSame('Qwen/Qwen2.5-VL-7B-Instruct', model_settings::get_vision_model());
        $this->assertSame('openai/whisper-large-v3', model_settings::get_audio_model());
        $this->assertSame('hexgrad/Kokoro-82M', model_settings::get_speech_model());
        $this->assertSame('af_bella', model_settings::get_speech_voice());
        $this->assertSame(2048, model_settings::get_max_tokens());
        $this->assertTrue(model_settings::is_files_enabled());
        $this->assertTrue(model_settings::is_audio_enabled());
        $this->assertTrue(model_settings::is_voice_enabled());
        // Documents need the file reading of the Datacurso service.
        $this->assertFalse(model_settings::is_documents_enabled());

        // The Datacurso values stay saved for when the service is switched back.
        set_config('ai_service', ai_service::DATACURSO, 'local_jedomi');
        $this->assertSame(model_settings::DEFAULT_MODEL, model_settings::get_model());
        $this->assertTrue(model_settings::is_documents_enabled());
    }

    /**
     * With the custom service, a feature is on only when its model is set; voice also needs audio.
     *
     * @return void
     */
    public function test_custom_features_follow_their_models(): void {
        $this->resetAfterTest();
        $this->configure_custom();
        set_config('custom_vision_model', '', 'local_jedomi');
        set_config('custom_audio_model', '', 'local_jedomi');
        set_config('custom_max_tokens', '', 'local_jedomi');

        $this->assertFalse(model_settings::is_files_enabled());
        $this->assertFalse(model_settings::is_audio_enabled());
        $this->assertFalse(model_settings::is_voice_enabled(), 'The voice conversation needs the audio model');
        $this->assertSame(model_settings::DEFAULT_MAX_TOKENS, model_settings::get_max_tokens());
    }

    /**
     * A site left with the retired "none" value uses the Datacurso switches, as the Datacurso service does.
     *
     * @return void
     */
    public function test_retired_none_value_follows_the_datacurso_switches(): void {
        $this->resetAfterTest();
        set_config('files_enabled', 1, 'local_jedomi');
        set_config('audio_enabled', 1, 'local_jedomi');
        set_config('voice_enabled', 1, 'local_jedomi');
        set_config('ai_service', 'none', 'local_jedomi');

        $this->assertTrue(model_settings::is_files_enabled());
        $this->assertTrue(model_settings::is_documents_enabled());
        $this->assertTrue(model_settings::is_audio_enabled());
        $this->assertTrue(model_settings::is_voice_enabled());
    }

    /**
     * Custom settings are read trimmed, and the address normalised.
     *
     * @return void
     */
    public function test_custom_getters(): void {
        $this->resetAfterTest();
        $this->configure_custom();
        set_config('custom_gateway_url', ' https://api.example.com/v1/ ', 'local_jedomi');
        set_config('custom_allow_http', 1, 'local_jedomi');

        $this->assertSame('https://api.example.com/v1', ai_service::get_custom_url());
        $this->assertSame('sk-test-secret', ai_service::get_custom_key());
        $this->assertTrue(ai_service::allows_custom_http());
        $this->assertSame('meta-llama/Llama-3.3-70B-Instruct', ai_service::get_custom_model(model_settings::CAPABILITY_CHAT));
    }

    /**
     * A verified address and key are remembered by a fingerprint that holds neither of them.
     *
     * @return void
     */
    public function test_verification_fingerprint(): void {
        $this->resetAfterTest();

        $this->assertFalse(ai_service::is_verified('https://api.example.com', 'sk-one'));

        ai_service::remember_verified('https://api.example.com/v1/', 'sk-one');

        $this->assertTrue(ai_service::is_verified('https://api.example.com', 'sk-one'));
        $this->assertFalse(ai_service::is_verified('https://api.example.com', 'sk-two'));
        $this->assertFalse(ai_service::is_verified('https://other.example.com', 'sk-one'));
        $stored = (string) get_config('local_jedomi', 'custom_verified');
        $this->assertStringNotContainsString('sk-one', $stored);
        $this->assertStringNotContainsString('api.example.com', $stored);
        $this->assertGreaterThan(0, json_decode($stored, true)['time']);
    }

    /**
     * The fingerprint follows the address the requests go to: a host and the same host with /v1 are one service,
     * while a path with and without /v1 are two different services.
     *
     * @return void
     */
    public function test_verification_fingerprint_follows_the_api_root(): void {
        $this->resetAfterTest();

        ai_service::remember_verified('http://localhost:11434', 'sk-one');
        $this->assertTrue(ai_service::is_verified('http://localhost:11434/v1/', 'sk-one'));

        ai_service::remember_verified('https://api.deepinfra.com/v1/openai', 'sk-one');
        $this->assertTrue(ai_service::is_verified(' https://api.deepinfra.com/v1/openai/ ', 'sk-one'));
        $this->assertFalse(ai_service::is_verified('https://api.deepinfra.com/v1/openai/v1', 'sk-one'));
        $this->assertFalse(ai_service::is_verified('https://api.deepinfra.com', 'sk-one'));
    }

    /**
     * The legacy gateway settings are still purged, the custom service settings are kept.
     *
     * @return void
     */
    public function test_legacy_migration_keeps_the_custom_settings(): void {
        $this->resetAfterTest();
        $this->configure_custom();
        set_config('gateway_url', 'http://localhost:8765', 'local_jedomi');
        set_config('api_key', 'legacy-key-value', 'local_jedomi');

        model_settings::migrate_legacy_settings();

        $this->assertFalse(get_config('local_jedomi', 'gateway_url'));
        $this->assertFalse(get_config('local_jedomi', 'api_key'));
        $this->assertSame('https://api.example.com', get_config('local_jedomi', 'custom_gateway_url'));
        $this->assertSame('sk-test-secret', get_config('local_jedomi', 'custom_api_key'));
        $this->assertSame(ai_service::CUSTOM, get_config('local_jedomi', 'ai_service'));
    }

    /**
     * The upgrade saves the Datacurso service on sites that never chose, so nothing changes for them, and keeps a
     * choice already saved.
     *
     * @return void
     */
    public function test_upgrade_keeps_the_datacurso_service(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/jedomi/db/upgrade.php');
        $this->resetAfterTest();
        unset_config('ai_service', 'local_jedomi');
        set_config('version', 2026111300, 'local_jedomi');

        $this->assertTrue(xmldb_local_jedomi_upgrade(2026111300));
        $this->assertSame(ai_service::DATACURSO, get_config('local_jedomi', 'ai_service'));

        set_config('ai_service', ai_service::CUSTOM, 'local_jedomi');
        set_config('version', 2026111300, 'local_jedomi');
        $this->assertTrue(xmldb_local_jedomi_upgrade(2026111300));
        $this->assertSame(ai_service::CUSTOM, get_config('local_jedomi', 'ai_service'));
    }
}
