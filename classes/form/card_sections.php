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
 * Sections of the forms of the Jedomi settings shown as cards, or as plain subsection titles inside a card.
 *
 * Each section is still a form header, so its fields stay in their own fieldset with a legend for screen readers,
 * but the header is drawn with the card header of the settings (local_jedomi/admin/card_header) instead of the
 * collapsible header of moodleform. Collapsing is turned off for the whole form, so there is no "Expand all" link.
 *
 * The renderer closes one element when the next section starts or the buttons come, so a card, which opens two
 * (the card and its body), is closed with {@see self::add_card_end()} before the next section.
 *
 * The header templates are registered on the default QuickForm renderer, which is one global object for the whole
 * request, keyed by the name of the header element. A template therefore applies to any element with that name in any
 * form rendered later in the same request, so the header names must be unique in the request, not only in the form.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait card_sections {
    /**
     * Start a card section: icon, title and subtitle in the header, the following fields in its body.
     *
     * @param string $name Name of the header element, unique in the request (see the class description).
     * @param string $title Card title.
     * @param string $subtitle Card subtitle.
     * @param string $icon Core pix icon key, for example i/lock.
     * @param string $color Icon colour modifier of local_jedomi/admin/card.
     * @return void
     */
    protected function add_card_start(string $name, string $title, string $subtitle, string $icon, string $color): void {
        global $OUTPUT;
        $header = $OUTPUT->render_from_template('local_jedomi/admin/card_header', [
            'icon' => $icon,
            'color' => $color,
            'title' => $title,
            'subtitle' => $subtitle,
        ]);
        $this->add_section_header(
            $name,
            $title,
            '<div class="card local_jedomi-admin-card mb-4">' . $header . '<div class="card-body">'
        );
    }

    /**
     * End the card started by the last {@see self::add_card_start()}: closes its body.
     *
     * @return void
     */
    protected function add_card_end(): void {
        $this->_form->addElement('html', '</div>');
    }

    /**
     * Start a subsection with a plain title, for the sections of a form that is already inside a card.
     *
     * @param string $name Name of the header element, unique in the request (see the class description).
     * @param string $title Subsection title.
     * @return void
     */
    protected function add_subsection(string $name, string $title): void {
        $this->add_section_header(
            $name,
            $title,
            '<h6 class="local_jedomi-admin-subsection-title" aria-hidden="true">' . s($title) . '</h6><div class="fcontainer">'
        );
    }

    /**
     * Add a non-collapsible header drawn with the given markup, after a legend for screen readers.
     *
     * @param string $name Name of the header element, unique in the request: the template is registered on the global
     *     default renderer of QuickForm under this name.
     * @param string $title Section title, used as the legend of the fieldset.
     * @param string $markup Markup after the legend; it must leave exactly one element open more than it closes for
     *     a subsection, or two for a card.
     * @return void
     */
    private function add_section_header(string $name, string $title, string $markup): void {
        $mform = $this->_form;
        $mform->setDisableShortforms(true);
        $mform->addElement('header', $name, $title);
        $mform->defaultRenderer()->setElementTemplate(
            '<legend class="sr-only visually-hidden">' . s($title) . '</legend>' . $markup,
            $name
        );
    }
}
