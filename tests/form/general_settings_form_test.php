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

namespace local_jedomi\form;

/**
 * Tests for the form of the General tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\form\general_settings_form
 */
final class general_settings_form_test extends \advanced_testcase {
    /**
     * MDL-UNIT-012: a submitted form returns the permission token secret as sent.
     *
     * @return void
     */
    public function test_submitted_values_are_returned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        general_settings_form::mock_submit(['spt_secret' => 's3cret']);

        $data = (new general_settings_form())->get_data();

        $this->assertSame('s3cret', $data->spt_secret);
    }

    /**
     * MDL-UNIT-013: the help of the permission token secret explains its purpose and the effect of changing it, in English
     * and in Spanish, without mentioning retired components.
     *
     * @return void
     */
    public function test_secret_help_does_not_mention_retired_components(): void {
        global $CFG;
        $this->resetAfterTest();
        // The test dataroot has no language packs: a minimal langconfig lets the string manager read lang/es.
        check_dir_exists($CFG->dataroot . '/lang/es');
        file_put_contents(
            $CFG->dataroot . '/lang/es/langconfig.php',
            "<?php\n\$string['thislanguage'] = 'Español';\n\$string['parentlanguage'] = '';\n"
        );
        get_string_manager()->reset_caches();

        $expected = [
            'en' => ['generated automatically', 'stored in moodle', 'does not need to be set', 'invalidates', 'reload'],
            'es' => ['genera automáticamente', 'guarda en moodle', 'no hace falta configurarlo', 'invalida', 'recargar'],
        ];
        foreach ($expected as $lang => $phrases) {
            $help = \core_text::strtolower(get_string_manager()->get_string('spt_secret_help', 'local_jedomi', null, $lang));
            foreach ($phrases as $phrase) {
                $this->assertStringContainsString($phrase, $help, "$lang help must say: $phrase");
            }
            foreach (['gateway', 'pasarela', 'n8n', 'external service', 'servicio externo'] as $retired) {
                $this->assertStringNotContainsString($retired, $help, "$lang help mentions a retired component");
            }
        }
    }

    /**
     * MDL-INT-008: the training data option had no effect, so it is no longer shown nor saved, and the upgrade removes
     * its stored value.
     *
     * @return void
     */
    public function test_training_data_option_is_not_shown(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        general_settings_form::mock_submit(['spt_secret' => 's3cret', 'collect_training_data' => 1]);

        $form = new general_settings_form();
        ob_start();
        $form->display();
        $html = ob_get_clean();
        $data = $form->get_data();

        $this->assertStringNotContainsString('collect_training_data', $html);
        $this->assertObjectNotHasProperty('collect_training_data', $data);
        $this->assertFalse(get_string_manager()->string_exists('collect_training_data', 'local_jedomi'));
        $upgrade = file_get_contents(__DIR__ . '/../../db/upgrade.php');
        $this->assertStringContainsString("unset_config('collect_training_data', 'local_jedomi')", $upgrade);
    }
}
