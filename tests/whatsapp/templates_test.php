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

namespace local_jedomi\whatsapp;

/**
 * Tests for the catalog of WhatsApp templates and for the settings of the templates, the monthly limit and the error
 * codes that stop the messages.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\templates
 * @covers     \local_jedomi\whatsapp\manager
 */
final class templates_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * grade_posted has one body parameter per datum, in the order of the approved template: course, activity, grade.
     */
    public function test_grade_posted_has_one_parameter_per_datum_in_order(): void {
        $components = templates::components(templates::GRADE_POSTED, [
            'grade' => '85.00',
            'course' => 'Biology 101',
            'activity' => 'Cell essay',
        ]);

        $this->assertSame([[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'Biology 101'],
                ['type' => 'text', 'text' => 'Cell essay'],
                ['type' => 'text', 'text' => '85.00'],
            ],
        ]], $components);
    }

    /**
     * reply_ready has no variable, so it is sent without components.
     */
    public function test_reply_ready_has_no_components(): void {
        $this->assertSame([], templates::components(templates::REPLY_READY, []));
    }

    /**
     * Meta refuses parameters with line breaks, tabs, long runs of spaces or no text: they are collapsed and an empty
     * value becomes a dash.
     */
    public function test_parameters_are_cleaned_for_meta(): void {
        $components = templates::components(templates::GRADE_POSTED, [
            'course' => "Biology\n\t101    (A)",
            'activity' => '   ',
            'grade' => '9',
        ]);

        $this->assertSame(
            ['Biology 101 (A)', '-', '9'],
            array_column($components[0]['parameters'], 'text')
        );
    }

    /**
     * A missing datum or an unknown template is a programming error.
     */
    public function test_missing_datum_and_unknown_template_are_refused(): void {
        try {
            templates::components(templates::GRADE_POSTED, ['course' => 'A', 'activity' => 'B']);
            $this->fail('A missing datum must be refused');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('grade', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        templates::components('marketing_offer', []);
    }

    /**
     * The template names default to the names of the catalog, can be changed and an empty name restores the default.
     */
    public function test_template_names_default_to_the_catalog_and_round_trip(): void {
        $this->assertSame('grade_posted', manager::get_template_name(templates::GRADE_POSTED));
        $this->assertSame('reply_ready', manager::get_template_name(templates::REPLY_READY));

        manager::set_template_name(templates::GRADE_POSTED, ' jedomi_grade_v2 ');
        $this->assertSame('jedomi_grade_v2', manager::get_template_name(templates::GRADE_POSTED));
        $this->assertSame('jedomi_grade_v2', get_config('local_jedomi', 'whatsapp_template_grade_posted'));

        manager::set_template_name(templates::GRADE_POSTED, '');
        $this->assertSame('grade_posted', manager::get_template_name(templates::GRADE_POSTED));
    }

    /**
     * Meta names templates with lowercase letters, digits and underscores only.
     */
    public function test_an_invalid_template_name_is_refused(): void {
        $this->expectException(\moodle_exception::class);
        manager::set_template_name(templates::REPLY_READY, 'Reply Ready');
    }

    /**
     * The language of the templates follows the site language in the form of Meta, with es when the site language
     * cannot be used, and it can be set.
     */
    public function test_template_language_defaults_to_the_site_language(): void {
        global $CFG;
        $CFG->lang = 'es_mx';
        $this->assertSame('es_MX', manager::get_template_language());
        $CFG->lang = 'en';
        $this->assertSame('en', manager::get_template_language());
        $CFG->lang = '';
        $this->assertSame('es', manager::get_template_language());

        manager::set_template_language('pt_BR');
        $this->assertSame('pt_BR', manager::get_template_language());
        manager::set_template_language('');
        $this->assertSame('es', manager::get_template_language());
    }

    /**
     * A language code that Meta does not use is refused.
     */
    public function test_an_invalid_template_language_is_refused(): void {
        $this->expectException(\moodle_exception::class);
        manager::set_template_language('Spanish');
    }

    /**
     * The monthly limit is off (0) until it is set; a negative limit is refused.
     */
    public function test_monthly_cap_defaults_to_unlimited(): void {
        $this->assertSame(0, manager::get_monthly_cap());
        manager::set_monthly_cap(300);
        $this->assertSame(300, manager::get_monthly_cap());
        manager::set_monthly_cap(0);
        $this->assertSame(0, manager::get_monthly_cap());

        $this->expectException(\moodle_exception::class);
        manager::set_monthly_cap(-1);
    }

    /**
     * The error codes that stop the messages have a default list, can be replaced and an empty value restores it.
     */
    public function test_opt_out_error_codes_default_and_round_trip(): void {
        $this->assertSame(manager::DEFAULT_OPT_OUT_ERROR_CODES, manager::get_opt_out_error_codes());

        manager::set_opt_out_error_codes('131050, 131026');
        $this->assertSame([131050, 131026], manager::get_opt_out_error_codes());

        manager::set_opt_out_error_codes('');
        $this->assertSame(manager::DEFAULT_OPT_OUT_ERROR_CODES, manager::get_opt_out_error_codes());

        $this->expectException(\moodle_exception::class);
        manager::set_opt_out_error_codes('131050, blocked');
    }
}
