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
            'docservice_url' => 'https://docs.example.com/convert',
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
     * S5: the provider form shows "Enabled" plus the model, document service URL and max tokens fields.
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
        $this->assertStringContainsString('name="docservice_url"', $html);
        $this->assertStringContainsString('name="max_tokens"', $html);
        foreach (array_keys(model_settings::get_available_models()) as $model) {
            $this->assertStringContainsString('value="' . s($model) . '"', $html);
        }
    }

    /**
     * S5: valid values pass the validation of the provider form.
     *
     * @return void
     */
    public function test_validation_accepts_valid_values(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new plugin_settings_form(null, ['component' => 'local_jedomi']);

        $this->assertSame([], $form->validation($this->valid_data(), []));
        $this->assertSame([], $form->validation($this->valid_data(['docservice_url' => '']), []));
        $this->assertSame([], $form->validation($this->valid_data(['docservice_url' => 'http://localhost:8000']), []));
    }

    /**
     * S5: a model outside the list is rejected.
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
     * S5: a document service URL that is not a web URL is rejected.
     *
     * @dataProvider bad_url_provider
     * @param string $url Submitted URL.
     * @return void
     */
    public function test_validation_rejects_bad_docservice_url(string $url): void {
        $extension = new settings_extension();

        $errors = $extension->validate($this->valid_data(['docservice_url' => $url]));

        $this->assertSame(['docservice_url' => get_string('error_invalid_docservice_url', 'local_jedomi')], $errors);
    }

    /**
     * URLs the document service field must reject.
     *
     * @return array<string, array{0: string}>
     */
    public static function bad_url_provider(): array {
        return [
            'not a url' => ['not a url'],
            'ftp scheme' => ['ftp://docs.example.com'],
            'javascript' => ['javascript:alert(1)'],
            'no host' => ['http://'],
        ];
    }

    /**
     * S5: max tokens must be a whole number.
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
     * S5: max tokens are clamped to the accepted range when saved.
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
            'in range' => [8000, 8000],
            'at maximum' => [128000, 128000],
            'above maximum' => [500000, model_settings::MAX_MAX_TOKENS],
        ];
    }

    /**
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
            'docservice_url' => '  https://docs.example.com/convert  ',
            'max_tokens' => 4096,
        ]));

        $this->assertSame('0', get_config('aiprovider_datacurso', 'plugin_enabled_local_jedomi'));
        $this->assertFalse(plugin_settings::is_enabled('local_jedomi'));
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', get_config('local_jedomi', 'model'));
        $this->assertSame('https://docs.example.com/convert', get_config('local_jedomi', 'docservice_url'));
        $this->assertSame('4096', get_config('local_jedomi', 'max_tokens'));
        $this->assertFalse(get_config('local_jedomi', 'enabled'));
    }

    /**
     * S5: an empty document service URL is saved empty so the readers keep their fallback.
     *
     * @return void
     */
    public function test_save_keeps_empty_docservice_url(): void {
        $this->resetAfterTest();
        set_config('docservice_url', 'http://old.example.com', 'local_jedomi');

        (new settings_extension())->save((object) $this->valid_data(['docservice_url' => '']));

        $this->assertSame('', get_config('local_jedomi', 'docservice_url'));
    }

    /**
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
     * S5: the form is prefilled with the current Jedomi settings and the provider state.
     *
     * @return void
     */
    public function test_get_data_reflects_current_config(): void {
        $this->resetAfterTest();
        set_config('model', 'jedomi/XiaomiMiMo/MiMo-V2.6-Flash', 'local_jedomi');
        set_config('docservice_url', 'http://docs.local:8000', 'local_jedomi');
        set_config('max_tokens', 9000, 'local_jedomi');
        set_config('plugin_enabled_local_jedomi', '0', 'aiprovider_datacurso');

        $this->assertSame([
            'model' => 'jedomi/XiaomiMiMo/MiMo-V2.6-Flash',
            'docservice_url' => 'http://docs.local:8000',
            'max_tokens' => 9000,
        ], (new settings_extension())->get_data());

        $current = plugin_settings_form::current_data('local_jedomi');
        $this->assertSame(0, $current['enabled']);
        $this->assertSame('jedomi/XiaomiMiMo/MiMo-V2.6-Flash', $current['model']);
        $this->assertSame(9000, $current['max_tokens']);
    }

    /**
     * S5: unset or unlisted values are shown as the values Jedomi actually uses.
     *
     * @return void
     */
    public function test_get_data_uses_effective_defaults(): void {
        $this->resetAfterTest();
        set_config('model', 'jedomi/acme/unknown-model', 'local_jedomi');
        unset_config('docservice_url', 'local_jedomi');
        unset_config('max_tokens', 'local_jedomi');

        $this->assertSame([
            'model' => model_settings::DEFAULT_MODEL,
            'docservice_url' => '',
            'max_tokens' => model_settings::DEFAULT_MAX_TOKENS,
        ], (new settings_extension())->get_data());
    }
}
