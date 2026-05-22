<?php
namespace aiprovider_jedomi\form;
defined('MOODLE_INTERNAL') || die();

use core_ai\form\action_settings_form;

class action_form extends action_settings_form {

    #[\Override]
    protected function definition() {
        $mform      = $this->_form;
        $actionname = $this->_customdata['actionname'];

        $mform->addElement('text', 'model',
            get_string("action:{$actionname}:model", 'aiprovider_jedomi'));
        $mform->setType('model', PARAM_TEXT);
        $mform->setDefault('model', get_config('local_jedomi', 'model') ?: 'jedomi-e4b');
        $mform->addHelpButton('model', "action:{$actionname}:model", 'aiprovider_jedomi');

        $mform->addElement('textarea', 'systeminstruction',
            get_string("action:{$actionname}:systeminstruction", 'aiprovider_jedomi'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('systeminstruction', PARAM_TEXT);
        $mform->addHelpButton('systeminstruction',
            "action:{$actionname}:systeminstruction", 'aiprovider_jedomi');

        parent::definition();
    }

    public function get_defaults(): array {
        return [
            'model'             => get_config('local_jedomi', 'model') ?: 'jedomi-e4b',
            'systeminstruction' => '',
        ];
    }
}
