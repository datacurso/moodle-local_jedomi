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
        set_config('docservice_url', 'http://localhost:8000', 'local_jedomi');
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
        $this->assertSame('http://localhost:8000', get_config('local_jedomi', 'docservice_url'));
        $this->assertSame('spt-secret-value', get_config('local_jedomi', 'spt_secret'));
        $this->assertSame('1', get_config('local_jedomi', 'enabled'));
    }

    /**
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
        ], array_keys($models));
        $this->assertSame(model_settings::DEFAULT_MODEL, array_key_first($models));
        foreach ($models as $model => $name) {
            $this->assertTrue(model_settings::is_routable($model));
            $this->assertNotEmpty($name);
        }
    }

    /**
     * V8: every listed model is used as saved.
     */
    public function test_get_model_returns_saved_listed_model(): void {
        $this->resetAfterTest();

        foreach (array_keys(model_settings::get_available_models()) as $model) {
            set_config('model', $model, 'local_jedomi');
            $this->assertSame($model, model_settings::get_model());
        }
    }

    /**
     * V8: a saved model outside the list, including a removed one or another case, falls back to the default.
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
}
