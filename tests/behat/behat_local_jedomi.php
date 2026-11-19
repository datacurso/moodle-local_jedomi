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

/**
 * Behat steps for the Jedomi panel.
 *
 * The AI service is a true boundary: these steps answer the panel's chat requests in the browser with
 * a scripted stream, so no request leaves the test site. They also capture the conversation export
 * instead of downloading a file.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;
use Moodle\BehatExtension\Exception\SkippedException;

/**
 * Behat steps for the Jedomi panel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_jedomi extends behat_base {
    /** @var bool Whether the chat requests reach the real AI service in this scenario (no queued answer is given). */
    protected bool $realai = false;

    /**
     * Script that answers the chat requests of the panel with the queued answers and captures the export.
     *
     * Each queued answer is {text, tool, hold, pending, pendingfirst}: the tool is announced first, and with hold the
     * stream waits until the answer is released. A pending action is sent with the answer, or at once with pendingfirst.
     */
    private const AI_STUB = <<<'JS'
(function() {
    if (window.jedomiBehat) {
        return;
    }
    var state = {answers: [], payloads: [], released: false, downloads: [], exported: null};
    window.jedomiBehat = state;
    var realfetch = window.fetch.bind(window);
    var encoder = new TextEncoder();
    var line = function(data) {
        return encoder.encode('data: ' + (typeof data === 'string' ? data : JSON.stringify(data)) + '\n\n');
    };
    window.fetch = function(url, options) {
        if (String(url).indexOf('/local/jedomi/chatproxy.php') === -1) {
            return realfetch(url, options);
        }
        try {
            state.payloads.push(JSON.parse(options.body));
        } catch (e) {
            state.payloads.push(null);
        }
        if (state.passthrough && !state.answers.length) {
            return realfetch(url, options);
        }
        var answer = state.answers.shift() || {text: 'OK'};
        var stream = new ReadableStream({
            start: function(controller) {
                if (answer.tool) {
                    controller.enqueue(line({_jedomi_tool_executing: {tool: answer.tool, args: {}}}));
                }
                if (answer.pending && answer.pendingfirst) {
                    controller.enqueue(line({_jedomi_pending_action: answer.pending}));
                }
                var finish = function() {
                    if (answer.hold && !state.released) {
                        setTimeout(finish, 100);
                        return;
                    }
                    state.released = false;
                    if (answer.error) {
                        controller.enqueue(line({error: answer.error}));
                        controller.enqueue(line('[DONE]'));
                        controller.close();
                        return;
                    }
                    if (answer.pending && !answer.pendingfirst) {
                        controller.enqueue(line({_jedomi_pending_action: answer.pending}));
                    }
                    controller.enqueue(line({choices: [{delta: {content: answer.text}}]}));
                    controller.enqueue(line('[DONE]'));
                    controller.close();
                };
                finish();
            }
        });
        return Promise.resolve(new Response(stream, {status: 200, headers: {'Content-Type': 'text/event-stream'}}));
    };
    var realcreate = URL.createObjectURL.bind(URL);
    URL.createObjectURL = function(blob) {
        if (blob && typeof blob.text === 'function') {
            blob.text().then(function(text) {
                state.exported = text;
            });
        }
        return realcreate(blob);
    };
    var realclick = HTMLAnchorElement.prototype.click;
    HTMLAnchorElement.prototype.click = function() {
        if (this.download) {
            state.downloads.push(this.download);
            return;
        }
        return realclick.call(this);
    };
})();
JS;

    /**
     * Answer the chat requests of the panel in this page with a scripted stream.
     *
     * @return void
     */
    protected function install_ai_stub(): void {
        $this->execute_script(self::AI_STUB);
        if ($this->realai) {
            $this->execute_script('window.jedomiBehat.passthrough = true;');
        }
    }

    /**
     * Queue an answer of the AI.
     *
     * @param array $answer Answer: text, tool and hold.
     * @return void
     */
    protected function queue_answer(array $answer): void {
        $this->install_ai_stub();
        $this->execute_script('window.jedomiBehat.answers.push(' . json_encode($answer) . ');');
    }

    /**
     * Value returned by a script, retried until the check passes or the timeout ends.
     *
     * @param string $script Script returning the value.
     * @param callable $check Check of the value.
     * @param string $message Failure message.
     * @return mixed
     */
    protected function wait_for_value(string $script, callable $check, string $message) {
        $value = null;
        for ($i = 0; $i < 50; $i++) {
            $value = $this->evaluate_script($script);
            if ($check($value)) {
                return $value;
            }
            usleep(100000);
        }
        throw new ExpectationException($message . ' Last value: ' . json_encode($value), $this->getSession());
    }

    /**
     * The next answer of the Jedomi AI is the given text.
     *
     * @Given /^the Jedomi AI answers "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $text Answer.
     * @return void
     */
    public function the_jedomi_ai_answers(string $text): void {
        $this->queue_answer(['text' => $this->escape($text)]);
    }

    /**
     * The next answer of the Jedomi AI waits until it is released.
     *
     * @Given /^the Jedomi AI works on "(?P<text_string>(?:[^"]|\\")*)" until released$/
     * @param string $text Answer given once released.
     * @return void
     */
    public function the_jedomi_ai_works_until_released(string $text): void {
        $this->queue_answer(['text' => $this->escape($text), 'hold' => true]);
    }

    /**
     * The next answer of the Jedomi AI announces a tool and waits until it is released.
     *
     * @Given /^the Jedomi AI works on "(?P<text_string>(?:[^"]|\\")*)" using the tool "(?P<tool_string>[a-z_]+)" until released$/
     * @param string $text Answer given once released.
     * @param string $tool Tool announced while working.
     * @return void
     */
    public function the_jedomi_ai_works_with_a_tool_until_released(string $text, string $tool): void {
        $this->queue_answer(['text' => $this->escape($text), 'tool' => $tool, 'hold' => true]);
    }

    /**
     * Release the answer the Jedomi AI is holding.
     *
     * @When /^the Jedomi AI finishes its answer$/
     * @return void
     */
    public function the_jedomi_ai_finishes_its_answer(): void {
        $this->execute_script('window.jedomiBehat.released = true;');
    }

    /**
     * Type a message in the panel and send it.
     *
     * @When /^I send "(?P<text_string>(?:[^"]|\\")*)" to Jedomi$/
     * @param string $text Message.
     * @return void
     */
    public function i_send_to_jedomi(string $text): void {
        $this->install_ai_stub();
        $this->execute('behat_forms::i_set_the_field_with_xpath_to', ["//textarea[@id='jedomi-input']", $this->escape($text)]);
        $this->execute('behat_general::i_click_on', ['#jedomi-send', 'css_element']);
    }

    /**
     * The location sent to the AI with the last chat request.
     *
     * @Then /^the last Jedomi request should be sent with the location "(?P<location_string>[a-z]+)"$/
     * @param string $location Expected location.
     * @return void
     */
    public function the_last_jedomi_request_should_be_sent_with_the_location(string $location): void {
        $this->wait_for_value(
            'return (function() { var p = window.jedomiBehat && window.jedomiBehat.payloads;'
                . ' return p && p.length ? p[p.length - 1].context.location : null; })();',
            fn($value) => $value === $location,
            "The last request was not sent with the location {$location}."
        );
    }

    /**
     * The exported conversation contains a text.
     *
     * @Then /^the exported Jedomi conversation should contain "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $text Expected text.
     * @return void
     */
    public function the_exported_jedomi_conversation_should_contain(string $text): void {
        $text = $this->escape($text);
        $this->wait_for_value(
            'return window.jedomiBehat ? window.jedomiBehat.exported : null;',
            fn($value) => is_string($value) && str_contains($value, $text),
            "The exported conversation does not contain \"{$text}\"."
        );
    }

    /**
     * The exported conversation file name includes the date of today.
     *
     * @Then /^the exported Jedomi conversation file name should include the date of today$/
     * @return void
     */
    public function the_exported_jedomi_file_name_should_include_the_date_of_today(): void {
        $this->wait_for_value(
            'return (function() { var d = window.jedomiBehat ? window.jedomiBehat.downloads : [];'
                . ' return d.length ? d[d.length - 1] : null; })();',
            fn($value) => is_string($value) && str_contains($value, gmdate('Y-m-d')),
            'The exported file name does not include the date of today.'
        );
    }

    /**
     * Nothing has been exported.
     *
     * @Then /^no Jedomi conversation should have been exported$/
     * @return void
     */
    public function no_jedomi_conversation_should_have_been_exported(): void {
        $this->install_ai_stub();
        $downloads = $this->evaluate_script('return window.jedomiBehat.downloads.length;');
        if ((int) $downloads !== 0) {
            throw new ExpectationException('A conversation file was downloaded.', $this->getSession());
        }
    }

    /**
     * Get ready to capture the export of the conversation.
     *
     * @Given /^the Jedomi conversation export is captured$/
     * @return void
     */
    public function the_jedomi_conversation_export_is_captured(): void {
        $this->install_ai_stub();
    }

    /**
     * The message field of the panel contains a text.
     *
     * @Then /^the Jedomi message field should contain "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $text Expected text.
     * @return void
     */
    public function the_jedomi_message_field_should_contain(string $text): void {
        $text = $this->escape($text);
        $this->wait_for_value(
            "return document.getElementById('jedomi-input').value;",
            fn($value) => $value === $text,
            "The message field does not contain \"{$text}\"."
        );
    }

    /**
     * The panel takes the whole width of the window.
     *
     * @Then /^the Jedomi panel should fill the window width$/
     * @return void
     */
    public function the_jedomi_panel_should_fill_the_window_width(): void {
        $this->wait_for_value(
            "return Math.abs(document.getElementById('jedomi-sidebar').getBoundingClientRect().width"
                . " - document.documentElement.clientWidth) <= 1;",
            fn($value) => $value === true,
            'The panel does not fill the window width.'
        );
    }

    /**
     * Width of the panel in pixels.
     *
     * @return float
     */
    protected function panel_width(): float {
        return (float) $this->evaluate_script("return document.getElementById('jedomi-sidebar').getBoundingClientRect().width;");
    }

    /**
     * Remember the width of the panel to compare it later.
     *
     * @Given /^I remember the width of the Jedomi panel$/
     * @return void
     */
    public function i_remember_the_width_of_the_jedomi_panel(): void {
        $this->execute_script('window.jedomiBehatWidth = ' . json_encode($this->panel_width()) . ';');
    }

    /**
     * Compare the width of the panel with the remembered one.
     *
     * @Then /^the Jedomi panel should be (?P<comparison>wider than|as wide as) the remembered width$/
     * @param string $comparison Comparison.
     * @return void
     */
    public function the_jedomi_panel_width_compared_with_the_remembered(string $comparison): void {
        $remembered = (float) $this->evaluate_script('return window.jedomiBehatWidth;');
        $this->wait_for_value(
            "return document.getElementById('jedomi-sidebar').getBoundingClientRect().width;",
            fn($value) => $comparison === 'wider than' ? (float) $value > $remembered + 1 : abs((float) $value - $remembered) <= 1,
            "The panel is not {$comparison} {$remembered}px."
        );
    }

    /**
     * Give the site a full name that makes the title of the site home exactly the given number of characters long,
     * as the panel reads it.
     *
     * @Given /^the site home title is "(?P<length_number>\d+)" characters long for Jedomi$/
     * @param int $length Wanted length of the title.
     * @return void
     */
    public function the_site_home_title_is_characters_long(int $length): void {
        global $DB;
        $this->execute('behat_general::i_am_on_site_homepage');
        $raw = (string) $this->evaluate_script(
            "return document.title.replace(/\\s*[|\\-]\\s*Moodle.*$/i, '').trim();"
        );
        $current = (string) $DB->get_field('course', 'fullname', ['id' => SITEID]);
        $other = mb_strlen($raw) - mb_strlen($current);
        $wanted = $length - $other;
        if ($wanted < 1 || !str_contains($raw, $current)) {
            throw new ExpectationException(
                'The site name cannot make the title ' . $length . ' characters long.',
                $this->getSession()
            );
        }
        $DB->set_field('course', 'fullname', mb_substr(str_repeat('Jedomi title ', 20), 0, $wanted), ['id' => SITEID]);
        purge_all_caches();
        $this->execute('behat_general::i_am_on_site_homepage');
    }

    // phpcs:disable moodle.Files.LineLength.TooLong -- Behat step patterns must stay on one line.
    /**
     * The context label of the panel shows the page title, cut when it is longer than the maximum.
     *
     * @Then /^the Jedomi header should show the page title (?P<how>complete|cut with an ellipsis) within "(?P<max_number>\d+)" characters$/
     * @param string $how Whether the title is complete or cut.
     * @param int $max Maximum number of characters of the title.
     * @return void
     */
    public function the_jedomi_header_should_show_the_page_title(string $how, int $max): void {
        $result = $this->wait_for_value(
            "return (function() { var el = document.getElementById('jedomi-context-label');"
                . " if (!el || !el.textContent) { return null; }"
                . " return {label: el.textContent.replace(/^\\S+\\s/, ''),"
                . " title: document.title.replace(/\\s*[|\\-]\\s*Moodle.*$/i, '').trim()}; })();",
            fn($value) => is_array($value),
            'The panel header shows no page title.'
        );
        $label = $result['label'];
        $title = $result['title'];
        $chars = mb_strlen($label);
        if ($chars > $max) {
            throw new ExpectationException(
                "The title \"{$label}\" has {$chars} characters, more than {$max}.",
                $this->getSession()
            );
        }
        if ($how === 'complete' && $label !== $title) {
            throw new ExpectationException("The title \"{$label}\" is not the complete \"{$title}\".", $this->getSession());
        }
        if ($how !== 'complete') {
            $prefix = mb_substr($label, 0, -1);
            if (!str_ends_with($label, "\u{2026}") || !str_starts_with($title, $prefix)) {
                throw new ExpectationException(
                    "The title \"{$label}\" is not \"{$title}\" cut with an ellipsis.",
                    $this->getSession()
                );
            }
        }
    }
    // phpcs:enable moodle.Files.LineLength.TooLong

    /**
     * Create Jedomi agents.
     *
     * Columns: name, prompt, creator (username, admin by default), context (site, course, category or user, site by
     * default), reference (course short name, category idnumber or username of the context) and enabled (1 by default).
     *
     * @Given /^the following Jedomi agents exist:$/
     * @param TableNode $table Agents.
     * @return void
     */
    public function the_following_jedomi_agents_exist(TableNode $table): void {
        global $DB;
        foreach ($table->getHash() as $row) {
            $creator = $DB->get_record('user', ['username' => $row['creator'] ?? 'admin'], '*', MUST_EXIST);
            $type = $row['context'] ?? 'site';
            $reference = $row['reference'] ?? '';
            $contextid = null;
            if ($type === 'course') {
                $courseid = $DB->get_field('course', 'id', ['shortname' => $reference], MUST_EXIST);
                $contextid = context_course::instance($courseid)->id;
            } else if ($type === 'category') {
                $categoryid = $DB->get_field('course_categories', 'id', ['idnumber' => $reference], MUST_EXIST);
                $contextid = context_coursecat::instance($categoryid)->id;
            } else if ($type === 'user') {
                $userid = $DB->get_field('user', 'id', ['username' => $reference], MUST_EXIST);
                $contextid = context_user::instance($userid)->id;
            }
            \local_jedomi\agent\agent_manager::create_agent((object) [
                'userid' => (int) $creator->id,
                'name' => $row['name'],
                'prompt' => $row['prompt'] ?? 'Report for ' . $row['name'],
                'schedule_cron' => '0 8 * * *',
                'context_type' => $type,
                'context_id' => $contextid,
                'enabled' => (int) ($row['enabled'] ?? 1),
            ]);
        }
    }

    /**
     * Check whether a Jedomi agent with the given name exists.
     *
     * @Then /^the Jedomi agent "(?P<name_string>(?:[^"]|\\")*)" should (?P<not>not )?exist$/
     * @param string $name Agent name.
     * @param string $not "not " when the agent must not exist.
     * @return void
     */
    public function the_jedomi_agent_should_exist(string $name, string $not = ''): void {
        global $DB;
        $exists = $DB->record_exists('local_jedomi_agents', ['name' => $name]);
        if ($exists === ($not !== '')) {
            throw new ExpectationException(
                "The Jedomi agent \"{$name}\" " . ($exists ? 'exists' : 'does not exist') . '.',
                $this->getSession()
            );
        }
    }

    /**
     * Make Jedomi unavailable in a course with the "All courses except the selected ones" mode.
     *
     * @Given /^Jedomi is not available in the course "(?P<shortname_string>(?:[^"]|\\")*)"$/
     * @param string $shortname Course short name.
     * @return void
     */
    public function jedomi_is_not_available_in_the_course(string $shortname): void {
        global $DB;
        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        set_config('course_mode', 'exclude', 'local_jedomi');
        set_config('course_list', (string) $courseid, 'local_jedomi');
    }

    /**
     * Hide Jedomi from a role in the Privacy settings.
     *
     * @Given /^the role "(?P<shortname_string>(?:[^"]|\\")*)" is hidden from Jedomi$/
     * @param string $shortname Role short name.
     * @return void
     */
    public function the_role_is_hidden_from_jedomi(string $shortname): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        $hidden = array_filter(explode(',', (string) get_config('local_jedomi', 'hidden_roles')));
        $hidden[] = (string) $roleid;
        set_config('hidden_roles', implode(',', array_unique($hidden)), 'local_jedomi');
    }

    /**
     * Send a request to a Jedomi endpoint from the current page, as the panel would, and keep its status.
     *
     * Every request first asks for the chat permissions of the course of the page, as the panel does, and sends them
     * with the session key: the server takes the course of the request from them.
     *
     * @When /^I request the Jedomi (?P<what>conversation history|image reading|panel configuration|chat query) from the browser$/
     * @param string $what Endpoint: the conversation history, the image reading, the panel configuration or a chat query.
     * @return void
     */
    public function i_request_jedomi_from_the_browser(string $what): void {
        $this->send_jedomi_request($what, '');
    }

    /**
     * Send a request to a Jedomi endpoint from the current page that names another course, and keep its status.
     *
     * The panel configuration is asked for that course; any other request carries the chat permissions of the page
     * and names that course too.
     *
     * @When /^I request the Jedomi (?P<what>[a-z ]+) for the course "(?P<shortname>[^"]+)" from the browser$/
     * @param string $what Endpoint: the conversation history, the image reading, the panel configuration or a chat query.
     * @param string $shortname Short name of the course the request names.
     * @return void
     */
    public function i_request_jedomi_for_the_course_from_the_browser(string $what, string $shortname): void {
        $this->send_jedomi_request($what, $shortname);
    }

    /**
     * Send a request to a Jedomi endpoint from the current page and keep its status in window.jedomiLastRequest.
     *
     * @param string $what Endpoint: the conversation history, the image reading, the panel configuration or a chat query.
     * @param string $shortname Short name of a course the request names, or '' for the course of the page only.
     * @return void
     */
    protected function send_jedomi_request(string $what, string $shortname): void {
        global $DB;
        $named = $shortname === '' ? 0 : (int) $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $common = "var course = (M.cfg.courseId || 0); var named = {$named};"
            . " var done = function(x) { window.jedomiLastRequest = {status: x.status, body: x.responseText}; };"
            . " var x = new XMLHttpRequest();";
        $configcourse = $what === 'panel configuration' && $named ? 'named' : 'course';
        $config = " var c = new XMLHttpRequest(); c.open('GET', M.cfg.wwwroot"
            . " + '/local/jedomi/ajax/sidebar_config.php?sesskey=' + M.cfg.sesskey + '&courseid=' + {$configcourse}, false);"
            . " c.send(); var spt = null; try { spt = JSON.parse(c.responseText).spt || null; } catch (e) { spt = null; }";
        if ($what === 'panel configuration') {
            $script = $common . $config
                . " window.jedomiLastRequest = {status: (c.status === 200 && spt) ? 200 : 403, body: c.responseText};";
        } else if ($what === 'chat query') {
            $script = $common . $config
                . " x.open('POST', M.cfg.wwwroot + '/local/jedomi/chatproxy.php', false);"
                . " x.setRequestHeader('Content-Type', 'application/json');"
                . " x.send(JSON.stringify({sesskey: M.cfg.sesskey, spt: spt, context: named ? {course_id: named} : {},"
                . " messages: [{role: 'user', content: 'Show me the courses'}]})); done(x);";
        } else if ($what === 'conversation history') {
            $script = $common . $config
                . " var body = new URLSearchParams({action: 'get', sesskey: M.cfg.sesskey, spt: JSON.stringify(spt)});"
                . " if (named) { body.append('courseid', named); }"
                . " x.open('POST', M.cfg.wwwroot + '/local/jedomi/conversation.php', false);"
                . " x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded'); x.send(body.toString()); done(x);";
        } else {
            // A 1x1 transparent PNG.
            $script = $common . $config
                . " var raw = atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');"
                . " var bytes = new Uint8Array(raw.length); for (var i = 0; i < raw.length; i++) { bytes[i] = raw.charCodeAt(i); }"
                . " var form = new FormData(); form.append('file', new Blob([bytes], {type: 'image/png'}), 'pixel.png');"
                . " form.append('question', 'What is this?'); form.append('sesskey', M.cfg.sesskey);"
                . " form.append('spt', JSON.stringify(spt)); if (named) { form.append('courseid', named); }"
                . " x.open('POST', M.cfg.wwwroot + '/local/jedomi/read_file.php', false); x.send(form); done(x);";
        }
        $this->execute_script('(function() {' . $script . '})();');
    }

    /**
     * Script that asks for the chat permissions of the course of the current page, as the panel does, into "spt".
     *
     * @return string JavaScript statements.
     */
    protected function panel_permissions_script(): string {
        return "var c = new XMLHttpRequest(); c.open('GET', M.cfg.wwwroot + '/local/jedomi/ajax/sidebar_config.php?sesskey='"
            . " + M.cfg.sesskey + '&courseid=' + (M.cfg.courseId || 0), false); c.send();"
            . " var spt = null; try { spt = JSON.parse(c.responseText).spt || null; } catch (e) { spt = null; }";
    }

    /**
     * Check the access answer of the last request sent with "I request the Jedomi ... from the browser".
     *
     * A refusal is an HTTP 403 with a message for the user.
     *
     * @Then /^the Jedomi server should (?P<result>refuse|accept) the last request$/
     * @param string $result Expected result.
     * @return void
     */
    public function the_jedomi_server_should_answer_the_last_request(string $result): void {
        $last = $this->evaluate_script('return window.jedomiLastRequest || null;');
        if (!is_array($last)) {
            throw new ExpectationException('No Jedomi request was sent from this page.', $this->getSession());
        }
        $status = (int) $last['status'];
        if ($result === 'refuse' && $status !== 403) {
            throw new ExpectationException("The request was not refused (HTTP {$status}): {$last['body']}", $this->getSession());
        }
        if ($result === 'accept' && $status !== 200) {
            throw new ExpectationException("The request was not accepted (HTTP {$status}): {$last['body']}", $this->getSession());
        }
    }

    /**
     * Configure an enabled and verified Telegram bot, without contacting the Bot API.
     *
     * @Given /^the Jedomi Telegram bot is configured$/
     * @return void
     */
    public function the_jedomi_telegram_bot_is_configured(): void {
        set_config('telegram_enabled', 1, 'local_jedomi');
        set_config('telegram_bot_verified', 1, 'local_jedomi');
        set_config('telegram_bot_username', 'jedomi_test_bot', 'local_jedomi');
        set_config('telegram_bot_firstname', 'Jedomi', 'local_jedomi');
    }

    /**
     * Link a user to a Telegram chat, with a conversation stored for the chat.
     *
     * @Given /^the user "(?P<username_string>(?:[^"]|\\")*)" is linked to the Jedomi Telegram chat "(?P<chat_number>\d+)"$/
     * @param string $username User name.
     * @param string $chatid Chat id.
     * @return void
     */
    public function the_user_is_linked_to_the_jedomi_telegram_chat(string $username, string $chatid): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $userid, 'chatid' => $chatid,
            'username' => $username, 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        $DB->insert_record('local_jedomi_telegram_sessions', (object) ['chatid' => $chatid,
            'messages' => json_encode([['role' => 'user', 'content' => 'Hello']]), 'usercontext' => null,
            'lastactivity' => time()]);
    }

    /**
     * Hide Telegram from a role.
     *
     * @Given /^the role "(?P<shortname_string>(?:[^"]|\\")*)" is blocked for Jedomi Telegram$/
     * @param string $shortname Role short name.
     * @return void
     */
    public function the_role_is_blocked_for_jedomi_telegram(string $shortname): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        set_config('telegram_hidden_roles', (string) $roleid, 'local_jedomi');
    }

    /**
     * Open the Telegram link page of another user.
     *
     * @When /^I open the Jedomi Telegram page of the user "(?P<username_string>(?:[^"]|\\")*)"$/
     * @param string $username User name.
     * @return void
     */
    public function i_open_the_jedomi_telegram_page_of_the_user(string $username): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $this->execute('behat_general::i_visit', ['/local/jedomi/telegram_profile.php?userid=' . $userid]);
    }

    /**
     * Check whether the Jedomi data of a Telegram chat still exists.
     *
     * @Then /^the Jedomi Telegram chat "(?P<chat_number>\d+)" should have no link and no conversation$/
     * @param string $chatid Chat id.
     * @return void
     */
    public function the_jedomi_telegram_chat_should_have_no_data(string $chatid): void {
        global $DB;
        $links = $DB->count_records('local_jedomi_telegram_links', ['chatid' => $chatid]);
        $sessions = $DB->count_records('local_jedomi_telegram_sessions', ['chatid' => $chatid]);
        if ($links || $sessions) {
            throw new ExpectationException(
                "The chat still has {$links} link(s) and {$sessions} conversation(s).",
                $this->getSession()
            );
        }
    }

    // phpcs:disable moodle.Files.LineLength.TooLong -- Behat step patterns must stay on one line.
    /**
     * Send a request to the Telegram webhook from the browser and keep its status, body and declared length.
     *
     * The body is an update from a chat that is not linked, so a processed request only answers the link notice. The
     * secret goes in the header Telegram uses.
     *
     * @When /^I send a (?P<method>GET|POST) request to the Jedomi Telegram webhook with (?P<secret>the right|a wrong) secret and (?P<body>a valid update|an invalid JSON body)$/
     * @param string $method HTTP method.
     * @param string $secret Whether the secret is the configured one.
     * @param string $body Body kind.
     * @return void
     */
    public function i_send_a_request_to_the_jedomi_telegram_webhook(string $method, string $secret, string $body): void {
        $value = $secret === 'the right' ? \local_jedomi\telegram\manager::get_webhook_secret() : 'wrongsecret123';
        $payload = $body === 'a valid update'
            ? json_encode(['update_id' => 1, 'message' => ['chat' => ['id' => 783000001], 'text' => '/help']])
            : '{not json';
        $this->send_webhook_request($method, '', ['X-Telegram-Bot-Api-Secret-Token' => $value], $payload);
    }
    // phpcs:enable moodle.Files.LineLength.TooLong

    /**
     * Send a valid update to the Telegram webhook with the right secret in the query string instead of the header, as
     * versions before 1.6.1 registered it.
     *
     * @When I send a valid update to the Jedomi Telegram webhook with the right secret in the address
     * @return void
     */
    public function i_send_an_update_with_the_secret_in_the_address(): void {
        $payload = json_encode(['update_id' => 1, 'message' => ['chat' => ['id' => 783000001], 'text' => '/help']]);
        $query = '?secret=' . rawurlencode(\local_jedomi\telegram\manager::get_webhook_secret());
        $this->send_webhook_request('POST', $query, [], $payload);
    }

    /**
     * Send a request to the Telegram webhook from the browser and keep its status, body and declared length.
     *
     * @param string $method HTTP method.
     * @param string $query Query string of the address, with its "?", or empty.
     * @param array $headers Extra request headers, keyed by name.
     * @param string $payload Body sent with a POST request.
     * @return void
     */
    protected function send_webhook_request(string $method, string $query, array $headers, string $payload): void {
        $setheaders = '';
        foreach ($headers as $name => $value) {
            $setheaders .= ' x.setRequestHeader(' . json_encode($name) . ', ' . json_encode($value) . ');';
        }
        $script = "(function() { var x = new XMLHttpRequest();"
            . " x.open(" . json_encode($method) . ", M.cfg.wwwroot + "
            . json_encode('/local/jedomi/telegram_webhook.php' . $query) . ", false);"
            . " x.setRequestHeader('Content-Type', 'application/json');" . $setheaders
            . " x.send(" . ($method === 'POST' ? json_encode($payload) : 'null') . ");"
            . " window.jedomiLastRequest = {status: x.status, body: x.responseText,"
            . " length: x.getResponseHeader('Content-Length'), bytes: new TextEncoder().encode(x.responseText).length};"
            . " })();";
        $this->execute_script($script);
    }

    /**
     * Check the status of the last Telegram webhook request.
     *
     * @Then /^the Jedomi Telegram webhook should answer with status "(?P<status_number>\d+)"$/
     * @param int $status Expected HTTP status.
     * @return void
     */
    public function the_jedomi_telegram_webhook_should_answer_with_status(int $status): void {
        $last = $this->evaluate_script('return window.jedomiLastRequest || null;');
        if (!is_array($last) || (int) $last['status'] !== $status) {
            throw new ExpectationException('Unexpected webhook answer: ' . json_encode($last), $this->getSession());
        }
    }

    /**
     * Check that the last Telegram webhook answer declares the real length of its body.
     *
     * @Then /^the Jedomi Telegram webhook answer should declare the real body length$/
     * @return void
     */
    public function the_jedomi_telegram_webhook_answer_should_declare_the_real_body_length(): void {
        $last = $this->evaluate_script('return window.jedomiLastRequest || null;');
        if (!is_array($last) || (string) $last['length'] !== (string) $last['bytes']) {
            throw new ExpectationException(
                'The declared length does not match the body: ' . json_encode($last),
                $this->getSession()
            );
        }
    }

    /**
     * The next answer of the Jedomi AI is the given multiline text, such as Markdown with lists or tables.
     *
     * @Given /^the Jedomi AI answers:$/
     * @param PyStringNode $text Answer.
     * @return void
     */
    public function the_jedomi_ai_answers_multiline(PyStringNode $text): void {
        $this->queue_answer(['text' => $text->getRaw()]);
    }

    /**
     * The next answer of the Jedomi AI proposes an action that changes data.
     *
     * The action is held on the server for the user of the session, exactly as the chat proxy holds it, so confirming
     * or cancelling it goes through the real resolution of the plugin. In the JSON, %course:SHORTNAME%,
     * %user:USERNAME% and %agent:NAME% are replaced by the id of that course, user or agent.
     *
     * @Given /^the Jedomi AI answers "(?P<text_string>(?:[^"]|\\")*)" proposing to run "(?P<tool_string>[a-z_]+)" with:$/
     * @param string $text Answer text.
     * @param string $tool Tool that changes data.
     * @param PyStringNode $args Tool arguments as JSON.
     * @return void
     */
    public function the_jedomi_ai_proposes_an_action(string $text, string $tool, PyStringNode $args): void {
        $this->queue_answer(['text' => $this->escape($text), 'pending' => $this->hold_action($tool, $args)]);
    }

    /**
     * The next answer of the Jedomi AI holds an action that changes data at once and keeps working until released.
     *
     * The action is held on the server as in the step above; its confirmation card reaches the panel before the rest
     * of the answer, as when the chat proxy holds an action while the AI is still writing.
     *
     * @Given /^the Jedomi AI works on "(?P<text_string>(?:[^"]|\\")*)" proposing to run "([a-z_]+)" until released with:$/
     * @param string $text Answer text, given once released.
     * @param string $tool Tool that changes data.
     * @param PyStringNode $args Tool arguments as JSON.
     * @return void
     */
    public function the_jedomi_ai_works_proposing_an_action(string $text, string $tool, PyStringNode $args): void {
        $this->queue_answer(['text' => $this->escape($text), 'pending' => $this->hold_action($tool, $args),
            'pendingfirst' => true, 'hold' => true]);
    }

    /**
     * Hold an action that changes data for the user of the session and return its confirmation card.
     *
     * @param string $tool Tool that changes data.
     * @param PyStringNode $args Tool arguments as JSON, with %course:SHORTNAME%, %user:USERNAME% and %agent:NAME%.
     * @return array Confirmation card.
     */
    protected function hold_action(string $tool, PyStringNode $args): array {
        $raw = preg_replace_callback('/%(course|user|agent):([^%]+)%/', function (array $m): string {
            global $DB;
            [$table, $field] = match ($m[1]) {
                'course' => ['course', 'shortname'],
                'user' => ['user', 'username'],
                'agent' => ['local_jedomi_agents', 'name'],
            };
            return (string) $DB->get_field($table, 'id', [$field => $m[2]], MUST_EXIST);
        }, $args->getRaw());
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ExpectationException('The arguments of the action are not valid JSON.', $this->getSession());
        }
        $card = \local_jedomi\local\service\pending_action_service::hold((int) $this->get_session_user()->id, $tool, $decoded);
        if ($card === null) {
            throw new ExpectationException("The tool {$tool} does not need a confirmation.", $this->getSession());
        }
        return $card;
    }

    /**
     * The next chat request of the panel ends with a server error carrying the given message.
     *
     * @Given /^the Jedomi AI fails with the message "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $message Error message.
     * @return void
     */
    public function the_jedomi_ai_fails_with_the_message(string $message): void {
        $this->queue_answer(['error' => ['code' => 'generic', 'message' => $this->escape($message)]]);
    }

    /**
     * Make every action waiting for a confirmation older than its expiry time.
     *
     * @Given /^the held Jedomi actions have expired$/
     * @return void
     */
    public function the_held_jedomi_actions_have_expired(): void {
        global $DB;
        $service = \local_jedomi\local\service\pending_action_service::class;
        $DB->set_field_select($service::TABLE, 'timecreated', time() - $service::EXPIRY - 60, '1 = 1');
    }

    /**
     * Text of the last file downloaded from the panel (conversation export or CSV), without the byte order mark.
     *
     * @return string
     */
    protected function downloaded_text(): string {
        $text = $this->wait_for_value(
            'return window.jedomiBehat ? window.jedomiBehat.exported : null;',
            fn($value) => is_string($value),
            'Nothing was downloaded from the panel.'
        );
        return preg_replace('/^\x{FEFF}/u', '', $text);
    }

    /**
     * The downloaded CSV contains a line exactly as given.
     *
     * @Then /^the downloaded Jedomi CSV should contain the line:$/
     * @param PyStringNode $line Expected CSV line.
     * @return void
     */
    public function the_downloaded_jedomi_csv_should_contain_the_line(PyStringNode $line): void {
        $expected = trim($line->getRaw());
        $this->wait_for_value(
            'return window.jedomiBehat ? window.jedomiBehat.exported : null;',
            fn($value) => is_string($value)
                && in_array($expected, array_map('trim', preg_split('/\r?\n/', preg_replace('/^\x{FEFF}/u', '', $value))), true),
            "The downloaded CSV has no line {$expected}."
        );
    }

    /**
     * The downloaded CSV does not contain a text.
     *
     * @Then /^the downloaded Jedomi CSV should not contain "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $text Text that must not be there.
     * @return void
     */
    public function the_downloaded_jedomi_csv_should_not_contain(string $text): void {
        $text = $this->escape($text);
        if (str_contains($this->downloaded_text(), $text)) {
            throw new ExpectationException("The downloaded CSV contains \"{$text}\".", $this->getSession());
        }
    }

    /**
     * No cell of the downloaded CSV starts with a character that a spreadsheet runs as a formula.
     *
     * @Then /^no cell of the downloaded Jedomi CSV should start with a formula character$/
     * @return void
     */
    public function no_cell_of_the_downloaded_jedomi_csv_should_start_with_a_formula_character(): void {
        foreach (preg_split('/\r?\n/', $this->downloaded_text()) as $row) {
            if (trim($row) === '') {
                continue;
            }
            foreach (str_getcsv($row, ',', '"', '') as $cell) {
                if ($cell !== null && $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    throw new ExpectationException("The CSV cell \"{$cell}\" would run as a formula.", $this->getSession());
                }
            }
        }
    }

    /**
     * No script or event handler of an answer has run in the page.
     *
     * The answers used with this step try to set window.jedomiXss.
     *
     * @Then /^no script of the Jedomi answer should have run$/
     * @return void
     */
    public function no_script_of_the_jedomi_answer_should_have_run(): void {
        usleep(500000);
        if ($this->evaluate_script('return typeof window.jedomiXss !== "undefined";')) {
            throw new ExpectationException('Content of the AI answer ran code in the page.', $this->getSession());
        }
    }

    /**
     * Store a conversation of the given number of messages in the session of the user, as the panel does after each
     * message, and reload the page so the panel loads it.
     *
     * The messages alternate user and assistant and are named "Message 001", "Message 002"...
     *
     * @Given /^I have a Jedomi conversation of "(?P<count_number>\d+)" messages$/
     * @param int $count Number of messages.
     * @return void
     */
    public function i_have_a_jedomi_conversation_of_messages(int $count): void {
        $this->execute_script('(function() { ' . $this->panel_permissions_script()
            . ' for (var i = 1; i <= ' . $count . '; i++) {'
            . " var body = new URLSearchParams({action: 'append', sesskey: M.cfg.sesskey, spt: JSON.stringify(spt),"
            . " role: i % 2 ? 'user' : 'assistant', content: 'Message ' + String(i).padStart(3, '0')});"
            . " var x = new XMLHttpRequest(); x.open('POST', M.cfg.wwwroot + '/local/jedomi/conversation.php', false);"
            . " x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded'); x.send(body.toString()); } })();");
        $this->execute('behat_general::reload');
    }

    /**
     * Messages sent to the AI with the last chat request.
     *
     * @return array
     */
    protected function last_request_messages(): array {
        return $this->wait_for_value(
            'return (function() { var p = window.jedomiBehat && window.jedomiBehat.payloads;'
                . ' return p && p.length ? p[p.length - 1].messages : null; })();',
            fn($value) => is_array($value),
            'No chat request was sent to the AI.'
        );
    }

    /**
     * Number of messages sent to the AI with the last chat request.
     *
     * @Then /^the last Jedomi request should carry "(?P<count_number>\d+)" messages$/
     * @param int $count Expected number of messages.
     * @return void
     */
    public function the_last_jedomi_request_should_carry_messages(int $count): void {
        $messages = $this->last_request_messages();
        if (count($messages) !== $count) {
            throw new ExpectationException(
                'The last request carried ' . count($messages) . " messages, not {$count}.",
                $this->getSession()
            );
        }
    }

    /**
     * Whether the last chat request carried a message containing a text.
     *
     * @Then /^the last Jedomi request should (?P<not>not )?include the message "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $not "not " when the message must be absent.
     * @param string $text Text of the message.
     * @return void
     */
    public function the_last_jedomi_request_should_include_the_message(string $not, string $text): void {
        $this->assert_messages_include($this->last_request_messages(), $not === '', $this->escape($text), 'last request');
    }

    /**
     * Conversation stored in the session of the user, read from the browser as the panel does.
     *
     * @return array
     */
    protected function stored_conversation(): array {
        $json = $this->evaluate_script('return (function() { ' . $this->panel_permissions_script()
            . " var x = new XMLHttpRequest(); x.open('POST', M.cfg.wwwroot + '/local/jedomi/conversation.php', false);"
            . " x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');"
            . " x.send(new URLSearchParams({action: 'get', sesskey: M.cfg.sesskey, spt: JSON.stringify(spt)}).toString());"
            . ' return x.responseText; })();');
        $data = json_decode((string) $json, true);
        if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
            throw new ExpectationException('The stored conversation could not be read: ' . $json, $this->getSession());
        }
        return $data['messages'];
    }

    /**
     * Number of messages of the conversation stored in the session.
     *
     * @Then /^the stored Jedomi conversation should have (?P<how>exactly|at most) "(?P<count_number>\d+)" messages$/
     * @param string $how Comparison.
     * @param int $count Number of messages.
     * @return void
     */
    public function the_stored_jedomi_conversation_should_have_messages(string $how, int $count): void {
        $stored = count($this->stored_conversation());
        if (($how === 'exactly' && $stored !== $count) || ($how === 'at most' && $stored > $count)) {
            throw new ExpectationException(
                "The stored conversation has {$stored} messages, not {$how} {$count}.",
                $this->getSession()
            );
        }
    }

    /**
     * Whether the conversation stored in the session has a message containing a text.
     *
     * @Then /^the stored Jedomi conversation should (?P<not>not )?include the message "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $not "not " when the message must be absent.
     * @param string $text Text of the message.
     * @return void
     */
    public function the_stored_jedomi_conversation_should_include_the_message(string $not, string $text): void {
        $this->assert_messages_include($this->stored_conversation(), $not === '', $this->escape($text), 'stored conversation');
    }

    /**
     * Check whether a list of chat messages has one whose text contains the given text.
     *
     * @param array $messages Messages in chat format.
     * @param bool $expected Whether the message must be there.
     * @param string $text Text to look for.
     * @param string $where Description for the failure message.
     * @return void
     */
    protected function assert_messages_include(array $messages, bool $expected, string $text, string $where): void {
        $found = false;
        foreach ($messages as $message) {
            $content = is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content'] ?? '');
            if (str_contains($content, $text)) {
                $found = true;
                break;
            }
        }
        if ($found !== $expected) {
            throw new ExpectationException(
                "The {$where} " . ($found ? 'includes' : 'does not include') . " \"{$text}\".",
                $this->getSession()
            );
        }
    }

    /**
     * Attach a file of tests/fixtures/behat to a file input of the panel, as choosing it from the attach menu does.
     *
     * @When /^I attach the Jedomi test file "(?P<file_string>[^"]+)" to the panel (?P<kind>image|file|audio) input$/
     * @param string $file File name in tests/fixtures/behat.
     * @param string $kind Input: image, file or audio.
     * @return void
     */
    public function i_attach_the_jedomi_test_file(string $file, string $kind): void {
        global $CFG;
        $path = $CFG->dirroot . '/local/jedomi/tests/fixtures/behat/' . basename($file);
        if (!is_readable($path)) {
            throw new ExpectationException("The test file {$file} does not exist.", $this->getSession());
        }
        $id = 'jedomi-' . $kind . '-input';
        // The input is hidden behind the attach menu; WebDriver only types into a displayed file input.
        $this->execute_script("(function() { var i = document.getElementById('{$id}'); i.classList.remove('jedomi-hidden');"
            . " i.style.display = 'block'; })();");
        $this->find('css', '#' . $id)->attachFile($path);
    }

    /**
     * Use the real AI service in this scenario.
     *
     * The service is a true external boundary: this needs a Datacurso licence with credits given in the
     * JEDOMI_BEHAT_LICENSE environment variable of the web server, and the site must reach the AI service.
     * Without it the scenario is skipped as an environment limitation, not as a pending defect.
     *
     * @Given /^the Jedomi AI service is available for acceptance tests$/
     * @return void
     */
    public function the_jedomi_ai_service_is_available_for_acceptance_tests(): void {
        $license = (string) getenv('JEDOMI_BEHAT_LICENSE');
        if ($license === '' || !class_exists(\aiprovider_datacurso\local\license_region::class)) {
            throw new SkippedException('Environment: set JEDOMI_BEHAT_LICENSE and install aiprovider_datacurso to run the '
                . 'scenarios that reach the real AI service.');
        }
        set_config('licensekey', $license, 'aiprovider_datacurso');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        $region = \aiprovider_datacurso\local\license_region::class;
        set_config($region::REGION, (string) (getenv('JEDOMI_BEHAT_LICENSE_REGION') ?: '0'), 'aiprovider_datacurso');
        set_config($region::FINGERPRINT, $region::fingerprint(), 'aiprovider_datacurso');
        set_config($region::CHECKED, time(), 'aiprovider_datacurso');
        $this->realai = true;
    }

    /**
     * Send a Telegram text update from a chat to the webhook, as Telegram does.
     *
     * @param string $chatid Chat id.
     * @param string $text Message text.
     * @return void
     */
    protected function send_telegram_text(string $chatid, string $text): void {
        $update = json_encode(['update_id' => random_int(1, 999999999), 'message' => [
            'message_id' => random_int(1, 999999), 'date' => time(), 'text' => $text,
            'chat' => ['id' => (int) $chatid, 'type' => 'private'],
            'from' => ['id' => (int) $chatid, 'is_bot' => false, 'first_name' => 'Behat', 'username' => 'behat' . $chatid],
        ]]);
        $secret = \local_jedomi\telegram\manager::get_webhook_secret();
        $this->execute_script("(function() { var x = new XMLHttpRequest(); x.open('POST', M.cfg.wwwroot + "
            . json_encode('/local/jedomi/telegram_webhook.php') . ", false);"
            . " x.setRequestHeader('Content-Type', 'application/json');"
            . " x.setRequestHeader('X-Telegram-Bot-Api-Secret-Token', " . json_encode($secret) . "); x.send("
            . json_encode($update) . "); })();");
    }

    /**
     * A Telegram chat sends a text message to the bot.
     *
     * @When /^the Jedomi Telegram chat "(?P<chat_number>\d+)" sends "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $chatid Chat id.
     * @param string $text Message text.
     * @return void
     */
    public function the_jedomi_telegram_chat_sends(string $chatid, string $text): void {
        $this->send_telegram_text($chatid, $this->escape($text));
    }

    /**
     * A Telegram chat sends to the bot the /start command with the link code shown on the Telegram page.
     *
     * @When /^the Jedomi Telegram chat "(?P<chat_number>\d+)" sends the link code shown on the page$/
     * @param string $chatid Chat id.
     * @return void
     */
    public function the_jedomi_telegram_chat_sends_the_link_code(string $chatid): void {
        $code = trim((string) $this->find('css', '.local_jedomi-tg-code')->getText());
        if ($code === '') {
            throw new ExpectationException('The page shows no link code.', $this->getSession());
        }
        $this->send_telegram_text($chatid, '/start ' . $code);
    }

    // phpcs:disable moodle.Files.LineLength.TooLong -- Behat step patterns must stay on one line.
    /**
     * Whether a Telegram chat is linked to a user.
     *
     * @Then /^the Jedomi Telegram chat "(?P<chat_number>\d+)" should (?P<not>not )?be linked to the user "(?P<username_string>(?:[^"]|\\")*)"$/
     * @param string $chatid Chat id.
     * @param string $not "not " when the link must not exist.
     * @param string $username User name.
     * @return void
     */
    public function the_jedomi_telegram_chat_should_be_linked_to_the_user(string $chatid, string $not, string $username): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $linked = $DB->record_exists('local_jedomi_telegram_links', ['chatid' => $chatid, 'userid' => $userid,
            'confirmed' => 1]);
        if ($linked === ($not !== '')) {
            throw new ExpectationException(
                "The chat {$chatid} is " . ($linked ? '' : 'not ') . "linked to {$username}.",
                $this->getSession()
            );
        }
    }
    // phpcs:enable moodle.Files.LineLength.TooLong

    /**
     * The last answer of the assistant stored for a Telegram chat contains a text. The bot answers after the webhook
     * acknowledges the update, so the answer is awaited.
     *
     * @Then /^the last Jedomi Telegram answer of the chat "(?P<chat_number>\d+)" should contain "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $chatid Chat id.
     * @param string $text Expected text.
     * @return void
     */
    public function the_last_jedomi_telegram_answer_should_contain(string $chatid, string $text): void {
        global $DB;
        $text = $this->escape($text);
        $last = '';
        for ($i = 0; $i < 240; $i++) {
            $messages = json_decode((string) $DB->get_field(
                'local_jedomi_telegram_sessions',
                'messages',
                ['chatid' => $chatid]
            ), true) ?: [];
            $answers = array_values(array_filter($messages, fn($m) => ($m['role'] ?? '') === 'assistant'
                && is_string($m['content'] ?? null) && $m['content'] !== ''));
            $last = $answers ? end($answers)['content'] : '';
            if ($last !== '' && str_contains(core_text::strtolower($last), core_text::strtolower($text))) {
                return;
            }
            usleep(500000);
        }
        throw new ExpectationException(
            "The last answer of the chat {$chatid} does not contain \"{$text}\": {$last}",
            $this->getSession()
        );
    }

    /**
     * Log SQL queries of agents for the Query Profiler.
     *
     * Columns: agent (name of an existing agent), query, duration (ms) and rows.
     *
     * @Given /^the following Jedomi agent queries are logged:$/
     * @param TableNode $table Queries.
     * @return void
     */
    public function the_following_jedomi_agent_queries_are_logged(TableNode $table): void {
        global $DB;
        foreach ($table->getHash() as $row) {
            $agentid = (int) $DB->get_field('local_jedomi_agents', 'id', ['name' => $row['agent']], MUST_EXIST);
            \local_jedomi\agent\query_profiler::log_query(
                $agentid,
                $row['query'],
                (float) $row['duration'],
                (int) ($row['rows'] ?? 0)
            );
        }
    }

    /**
     * Open the current page in a new browser window and switch to it.
     *
     * @When /^I open the current page in a new browser window$/
     * @return void
     */
    public function i_open_the_current_page_in_a_new_browser_window(): void {
        $this->execute_script("window.open(window.location.href, 'jedomisecond');");
        $this->execute('behat_general::switch_to_window', ['jedomisecond']);
        $this->execute('behat_general::wait_until_the_page_is_ready');
    }

    /**
     * Start the panel again in this page as if the request for its configuration had failed.
     *
     * The panel module is loaded anew with a configuration address that does not answer JSON.
     *
     * @Given /^the Jedomi panel configuration fails to load$/
     * @return void
     */
    public function the_jedomi_panel_configuration_fails_to_load(): void {
        $this->execute_script("(function() {"
            . " ['jedomi-trigger', 'jedomi-sidebar', 'jedomi-overlay'].forEach(function(id) {"
            . " var e = document.getElementById(id); if (e) { e.remove(); } });"
            . " require.undef('local_jedomi/sidebar');"
            . " require(['local_jedomi/sidebar'], function(sidebar) {"
            . " sidebar.init({configUrl: M.cfg.wwwroot + '/local/jedomi/ajax/jedomi_behat_missing_config.php'}); });"
            . " })();");
        $this->execute('behat_general::wait_until_the_page_is_ready');
    }

    /**
     * A rating of a Jedomi answer is stored for a user.
     *
     * @Then /^a Jedomi rating "(?P<rating>good|bad)" should be stored for the user "(?P<username_string>(?:[^"]|\\")*)"$/
     * @param string $rating Rating.
     * @param string $username User name.
     * @return void
     */
    public function a_jedomi_rating_should_be_stored_for_the_user(string $rating, string $username): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        for ($i = 0; $i < 50; $i++) {
            if ($DB->record_exists('local_jedomi_feedback', ['userid' => $userid, 'rating' => $rating])) {
                return;
            }
            usleep(100000);
        }
        throw new ExpectationException("No \"{$rating}\" rating is stored for {$username}.", $this->getSession());
    }

    /**
     * Type the token of a real Telegram bot in a field.
     *
     * The Telegram Bot API is a true external boundary: the token comes from the JEDOMI_BEHAT_TELEGRAM_TOKEN
     * environment variable of the web server and the site must be reachable by HTTPS from Telegram. Without it
     * the scenario is skipped as an environment limitation, not as a pending defect.
     *
     * @When /^I set the field "(?P<field_string>(?:[^"]|\\")*)" to the real Jedomi Telegram bot token$/
     * @param string $field Field label.
     * @return void
     */
    public function i_set_the_field_to_the_real_jedomi_telegram_bot_token(string $field): void {
        $token = (string) getenv('JEDOMI_BEHAT_TELEGRAM_TOKEN');
        if ($token === '') {
            throw new SkippedException('Environment: set JEDOMI_BEHAT_TELEGRAM_TOKEN with a real bot token to run the '
                . 'scenarios that reach the Telegram Bot API.');
        }
        $this->execute('behat_forms::i_set_the_field_to', [$field, $token]);
    }

    // phpcs:disable moodle.Files.LineLength.MaxExceeded -- Behat step patterns must stay on one line.
    /**
     * Type the Moodle context id of a course in a field of a container, for the forms that ask for a context id.
     *
     * @When /^I set the field "(?P<field_string>(?:[^"]|\\")*)" in the "(?P<container_string>(?:[^"]|\\")*)" "(?P<selector_string>[^"]*)" to the context id of the course "(?P<shortname_string>(?:[^"]|\\")*)"$/
     * @param string $field Field label.
     * @param string $container Container locator.
     * @param string $selector Container selector type.
     * @param string $shortname Course short name.
     * @return void
     */
    public function i_set_the_field_in_the_container_to_the_context_id_of_the_course(
        string $field,
        string $container,
        string $selector,
        string $shortname
    ): void {
        global $DB;
        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $contextid = \context_course::instance($courseid)->id;
        $this->execute('behat_forms::i_set_the_field_in_container_to', [$field, $container, $selector, (string) $contextid]);
    }
    // phpcs:enable moodle.Files.LineLength.MaxExceeded

    /**
     * Let the chat requests of the panel reach the chat proxy of the site instead of answering them in the browser,
     * for the scenarios that check how the server answers (for example without an AI licence).
     *
     * @Given /^the Jedomi chat requests reach the server$/
     * @return void
     */
    public function the_jedomi_chat_requests_reach_the_server(): void {
        $this->realai = true;
    }

    /**
     * Script that gives the page a fake microphone and answers the transcriptions, the file readings and the speech
     * requests of the panel in the browser, recording what the panel sends.
     *
     * The microphone level is scripted (window.jedomiVoice.speak), the recorder gives a short fake recording, the
     * transcriptions answer the queued texts or errors, and each sentence read aloud gets a short silent WAV, so the
     * real Web Audio playback of the panel runs and ends.
     */
    private const VOICE_STUB = <<<'JS'
(function() {
    if (window.jedomiVoice) {
        return;
    }
    var state = {level: 0, denied: false, transcripts: [], transcriptions: 0, readings: [], spoken: [], speechfails: false,
        tracks: 0, released: 0, playing: 0};
    window.jedomiVoice = state;
    // The voice of the browser, which reads the short notices of the voice conversation: it records them and ends at
    // once, so no sound is played.
    state.notices = [];
    var fakeSynth = {
        speak: function(utterance) {
            state.notices.push(utterance.text);
            setTimeout(function() {
                utterance.dispatchEvent(new Event('end'));
            }, 50);
        },
        cancel: function() {
            return;
        }
    };
    Object.defineProperty(window, 'speechSynthesis', {configurable: true, get: function() {
        return fakeSynth;
    }});
    window.SpeechSynthesisUtterance = function(text) {
        var target = new EventTarget();
        target.text = text;
        return target;
    };
    state.speak = function(ms, level) {
        state.level = level || 0.2;
        setTimeout(function() {
            state.level = 0;
        }, ms);
    };
    var AudioContextClass = window.AudioContext || window.webkitAudioContext;
    var media = navigator.mediaDevices || {};
    media.getUserMedia = function() {
        if (state.denied) {
            return Promise.reject(new DOMException('Permission denied', 'NotAllowedError'));
        }
        var ctx = new AudioContextClass();
        var stream = ctx.createMediaStreamDestination().stream;
        stream.getAudioTracks().forEach(function(track) {
            state.tracks++;
            var stop = track.stop.bind(track);
            track.stop = function() {
                state.released++;
                stop();
            };
        });
        return Promise.resolve(stream);
    };
    if (!navigator.mediaDevices) {
        Object.defineProperty(navigator, 'mediaDevices', {value: media, configurable: true});
    }
    // The level the panel measures is the scripted one.
    AnalyserNode.prototype.getFloatTimeDomainData = function(samples) {
        samples.fill(state.level);
    };
    var FakeRecorder = function(stream, options) {
        this.stream = stream;
        this.state = 'inactive';
        this.mimeType = (options && options.mimeType) || 'audio/webm';
        this.listeners = {};
    };
    FakeRecorder.isTypeSupported = function(type) {
        return type.indexOf('audio/webm') === 0;
    };
    FakeRecorder.prototype.addEventListener = function(name, fn) {
        (this.listeners[name] = this.listeners[name] || []).push(fn);
    };
    FakeRecorder.prototype.start = function() {
        this.state = 'recording';
    };
    FakeRecorder.prototype.stop = function() {
        if (this.state === 'inactive') {
            return;
        }
        this.state = 'inactive';
        var self = this;
        setTimeout(function() {
            (self.listeners.dataavailable || []).forEach(function(fn) {
                fn({data: new Blob(['fake recording'], {type: self.mimeType})});
            });
            (self.listeners.stop || []).forEach(function(fn) {
                fn({});
            });
        }, 0);
    };
    window.MediaRecorder = FakeRecorder;
    // Sources of audio playing, to know when Jedomi speaks.
    var start = AudioBufferSourceNode.prototype.start;
    var stop = AudioBufferSourceNode.prototype.stop;
    AudioBufferSourceNode.prototype.start = function() {
        var node = this;
        state.playing++;
        node.addEventListener('ended', function() {
            if (!node.jedomiStopped) {
                node.jedomiStopped = true;
                state.playing--;
            }
        });
        return start.apply(node, arguments);
    };
    AudioBufferSourceNode.prototype.stop = function() {
        if (!this.jedomiStopped) {
            this.jedomiStopped = true;
            state.playing--;
        }
        return stop.apply(this, arguments);
    };
    // A silent WAV of a quarter of a second, or of the scripted duration.
    var wav = function() {
        var rate = 8000;
        var samples = Math.round(rate * (state.seconds || 0.25));
        var buffer = new ArrayBuffer(44 + samples * 2);
        var view = new DataView(buffer);
        var text = function(offset, value) {
            for (var i = 0; i < value.length; i++) {
                view.setUint8(offset + i, value.charCodeAt(i));
            }
        };
        text(0, 'RIFF');
        view.setUint32(4, 36 + samples * 2, true);
        text(8, 'WAVE');
        text(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, rate, true);
        view.setUint32(28, rate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        text(36, 'data');
        view.setUint32(40, samples * 2, true);
        return buffer;
    };
    var json = function(data, status) {
        return Promise.resolve(new Response(JSON.stringify(data), {status: status,
            headers: {'Content-Type': 'application/json'}}));
    };
    var nextfetch = window.fetch.bind(window);
    window.fetch = function(url, options) {
        var address = String(url);
        if (address.indexOf('/local/jedomi/speech.php') !== -1) {
            var text = '';
            try {
                text = JSON.parse(options.body).text || '';
            } catch (e) {
                text = '';
            }
            state.spoken.push(text);
            if (state.speechfails) {
                return json({error: {code: 'unavailable', message: 'The speech service is not available.'}}, 502);
            }
            return Promise.resolve(new Response(wav(), {status: 200, headers: {'Content-Type': 'audio/mpeg'}}));
        }
        if (address.indexOf('/local/jedomi/extract_file.php') !== -1 && options && options.body instanceof FormData) {
            var file = options.body.get('file');
            var name = file && file.name ? file.name : '';
            if (name.indexOf('recording.') === 0) {
                state.transcriptions++;
                var next = state.transcripts.length ? state.transcripts.shift() : {text: 'Hello Jedomi'};
                if (next.error) {
                    return json({error: {code: 'unavailable', message: next.error}}, 400);
                }
                return json({text: next.text, format: 'audio', seconds: 2}, 200);
            }
            state.readings.push(name);
            return json({text: 'Text of ' + name, format: 'text', chars: 12,
                untrusted_text: '<<<UNTRUSTED_DATA id=Behat1 source="file">>>\n[Attached file: ' + name + ']\nText of ' + name
                    + '\n<<<END_UNTRUSTED_DATA id=Behat1>>>'}, 200);
        }
        return nextfetch(url, options);
    };
})();
JS;

    /**
     * Give the page the fake microphone and the voice answers of the browser.
     *
     * @return void
     */
    protected function install_voice_stub(): void {
        $this->execute_script(self::VOICE_STUB);
    }

    /**
     * The browser has a microphone that grants or denies access to the panel.
     *
     * @Given /^the Jedomi browser has a microphone that (?P<how>grants|denies) access$/
     * @param string $how Whether access is granted or denied.
     * @return void
     */
    public function the_jedomi_browser_has_a_microphone(string $how): void {
        $this->install_voice_stub();
        $this->execute_script('window.jedomiVoice.denied = ' . ($how === 'denies' ? 'true' : 'false') . ';');
    }

    /**
     * Queue the text the next transcription of what the user says gives.
     *
     * @Given /^the Jedomi transcription of what I say is "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $text Transcribed text.
     * @return void
     */
    public function the_jedomi_transcription_is(string $text): void {
        $this->install_voice_stub();
        $this->execute_script('window.jedomiVoice.transcripts.push(' . json_encode(['text' => $this->escape($text)]) . ');');
    }

    /**
     * Queue a failure of the next transcription of what the user says, with the message of the server.
     *
     * @Given /^the Jedomi transcription fails with the message "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $message Message of the server.
     * @return void
     */
    public function the_jedomi_transcription_fails(string $message): void {
        $this->install_voice_stub();
        $this->execute_script('window.jedomiVoice.transcripts.push(' . json_encode(['error' => $this->escape($message)]) . ');');
    }

    /**
     * Every sentence Jedomi tries to read aloud fails.
     *
     * @Given /^reading aloud fails for Jedomi$/
     * @return void
     */
    public function reading_aloud_fails_for_jedomi(): void {
        $this->install_voice_stub();
        $this->execute_script('window.jedomiVoice.speechfails = true;');
    }

    /**
     * The user speaks to the microphone for a time, then stays silent.
     *
     * @When /^I speak to Jedomi for "(?P<ms_number>\d+)" milliseconds$/
     * @param int $ms Duration of the speech, in milliseconds.
     * @return void
     */
    public function i_speak_to_jedomi_for(int $ms): void {
        $this->execute_script("window.jedomiVoice.speak({$ms});");
        usleep($ms * 1000);
    }

    /**
     * Current state of the voice conversation shown by the panel.
     *
     * @Then /^the Jedomi voice conversation should be (?P<state>listening|thinking|speaking|waiting for the confirmation)$/
     * @param string $state Expected state.
     * @return void
     */
    public function the_jedomi_voice_conversation_should_be(string $state): void {
        $class = ['listening' => 'listening', 'thinking' => 'thinking', 'speaking' => 'speaking',
            'waiting for the confirmation' => 'paused'][$state];
        $label = get_string('voice_mode_' . $class, 'local_jedomi');
        $this->wait_for_value(
            "return (function() { var v = document.getElementById('jedomi-voice-mode');"
                . " var l = v && v.querySelector('[data-region=\"local_jedomi/sidebar/voice-mode-state\"]');"
                . " return !!v && !v.classList.contains('jedomi-hidden') && v.classList.contains('jedomi-voice-mode--{$class}')"
                . ' && !!l && l.textContent === ' . json_encode($label) . '; })();',
            static fn($value): bool => $value === true,
            "The voice conversation is not {$state}."
        );
    }

    /**
     * Whether the voice view replaces the input row.
     *
     * @Then /^the Jedomi voice conversation should be (?P<how>open|closed)$/
     * @param string $how Open or closed.
     * @return void
     */
    public function the_jedomi_voice_conversation_should_be_open_or_closed(string $how): void {
        $open = $how === 'open' ? 'true' : 'false';
        $this->wait_for_value(
            "return (function() { var v = document.getElementById('jedomi-voice-mode');"
                . " var r = document.querySelector('#jedomi-sidebar .jedomi-input-row');"
                . " var viewopen = !!v && !v.classList.contains('jedomi-hidden');"
                . " var rowshown = !!r && !r.classList.contains('jedomi-hidden');"
                . " return viewopen === {$open} && rowshown === !{$open}; })();",
            static fn($value): bool => $value === true,
            "The voice view is not {$how}."
        );
    }

    /**
     * The microphone of the voice conversation was released and no audio is playing.
     *
     * @Then /^the Jedomi microphone should be released and no answer should be playing$/
     * @return void
     */
    public function the_jedomi_microphone_should_be_released(): void {
        $this->wait_for_value(
            'return (function() { var s = window.jedomiVoice;'
                . ' return !!s && s.tracks > 0 && s.released >= s.tracks && s.playing <= 0; })();',
            static fn($value): bool => $value === true,
            'The microphone is still open or an answer is still playing.'
        );
    }

    /**
     * Number of transcriptions of what the user said requested by the panel.
     *
     * @Then /^"(?P<count_number>\d+)" Jedomi transcriptions? should have been requested$/
     * @param int $count Expected number.
     * @return void
     */
    public function jedomi_transcriptions_should_have_been_requested(int $count): void {
        // A request the panel is still about to send is waited for.
        usleep(500000);
        $this->wait_for_value(
            'return window.jedomiVoice ? window.jedomiVoice.transcriptions : -1;',
            static fn($value): bool => (int) $value === $count,
            "Expected {$count} transcriptions."
        );
    }

    /**
     * Number of attached files sent to be read by the panel.
     *
     * @Then /^"(?P<count_number>\d+)" Jedomi file readings? should have been requested$/
     * @param int $count Expected number.
     * @return void
     */
    public function jedomi_file_readings_should_have_been_requested(int $count): void {
        usleep(500000);
        $this->wait_for_value(
            'return window.jedomiVoice ? window.jedomiVoice.readings.length : -1;',
            static fn($value): bool => (int) $value === $count,
            "Expected {$count} file readings."
        );
    }

    /**
     * Whether a text was sent to be read aloud.
     *
     * @Then /^Jedomi should (?P<not>not )?have read aloud "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $not Empty, or "not ".
     * @param string $text Text, or part of it.
     * @return void
     */
    public function jedomi_should_have_read_aloud(string $not, string $text): void {
        $text = $this->escape($text);
        if ($not === '') {
            $this->wait_for_value(
                'return (window.jedomiVoice ? window.jedomiVoice.spoken : []).join("\n");',
                static fn($value): bool => str_contains((string) $value, $text),
                "\"{$text}\" was not read aloud."
            );
            return;
        }
        $spoken = (string) $this->evaluate_script('return (window.jedomiVoice ? window.jedomiVoice.spoken : []).join("\n");');
        if (str_contains($spoken, $text)) {
            throw new ExpectationException("\"{$text}\" was read aloud: {$spoken}", $this->getSession());
        }
    }

    /**
     * Number of texts sent to speech.php to be read aloud, each one a request.
     *
     * @Then /^Jedomi should have sent "(?P<count_number>\d+)" texts? to be read aloud$/
     * @param int $count Expected number.
     * @return void
     */
    public function jedomi_should_have_sent_texts_to_be_read_aloud(int $count): void {
        // A request the panel is still about to send is waited for.
        usleep(500000);
        $this->wait_for_value(
            'return window.jedomiVoice ? window.jedomiVoice.spoken.length : -1;',
            static fn($value): bool => (int) $value === $count,
            "Expected {$count} texts to be read aloud."
        );
    }

    /**
     * Length of the first sentence sent to be read aloud.
     *
     * @Then /^the first text Jedomi read aloud should be at most "(?P<max_number>\d+)" characters long$/
     * @param int $max Most characters.
     * @return void
     */
    public function the_first_text_read_aloud_should_be_short(int $max): void {
        $first = $this->wait_for_value(
            'return window.jedomiVoice && window.jedomiVoice.spoken.length ? window.jedomiVoice.spoken[0] : null;',
            static fn($value): bool => is_string($value),
            'Nothing was read aloud.'
        );
        if (\core_text::strlen($first) > $max) {
            throw new ExpectationException("The first text read aloud is longer than {$max}: {$first}", $this->getSession());
        }
    }

    /**
     * Drop several files at once on the panel, as dragging them from the computer does.
     *
     * @When /^I drop the files "(?P<names_string>[^"]+)" on the Jedomi panel$/
     * @param string $names Comma separated file names; each file gets a short text.
     * @return void
     */
    public function i_drop_the_files_on_the_jedomi_panel(string $names): void {
        $this->install_voice_stub();
        $files = array_map('trim', explode(',', $names));
        $this->execute_script('(function() { var dt = new DataTransfer();'
            . ' ' . json_encode($files) . '.forEach(function(n) { dt.items.add(new File(["Text of " + n], n,'
            . ' {type: n.slice(-4) === ".gif" ? "image/gif" : "text/plain"})); });'
            . " var panel = document.getElementById('jedomi-sidebar');"
            . " ['dragenter', 'dragover', 'drop'].forEach(function(type) {"
            . ' var e = new DragEvent(type, {bubbles: true, cancelable: true, dataTransfer: dt});'
            . ' panel.dispatchEvent(e); }); })();');
    }

    /**
     * Each sentence read aloud lasts the given time, so the speaking state can be observed.
     *
     * @Given /^Jedomi takes "(?P<seconds_number>\d+)" seconds to read each sentence aloud$/
     * @param int $seconds Duration of each sentence.
     * @return void
     */
    public function jedomi_takes_seconds_to_read_each_sentence(int $seconds): void {
        $this->install_voice_stub();
        $this->execute_script("window.jedomiVoice.seconds = {$seconds};");
    }

    /**
     * The voice of the browser said a notice of the voice conversation aloud a number of times.
     *
     * @Then /^Jedomi should have said "(?P<text_string>(?:[^"]|\\")*)" aloud "(?P<count_number>\d+)" times?$/
     * @param string $text Text of the notice.
     * @param int $count Expected number of times.
     * @return void
     */
    public function jedomi_should_have_said_aloud(string $text, int $count): void {
        $text = $this->escape($text);
        $this->wait_for_value(
            'return (window.jedomiVoice && window.jedomiVoice.notices || []).filter(function(t) {'
                . ' return t === ' . json_encode($text) . '; }).length;',
            static fn($value): bool => (int) $value === $count,
            "\"{$text}\" was not said aloud {$count} times."
        );
    }

    /**
     * How many times a text appears in the chat of the panel.
     *
     * @Then /^I should see "(?P<text_string>(?:[^"]|\\")*)" "(?P<count_number>\d+)" times? in the Jedomi chat$/
     * @param string $text Text.
     * @param int $count Expected number of times.
     * @return void
     */
    public function i_should_see_times_in_the_jedomi_chat(string $text, int $count): void {
        $text = $this->escape($text);
        $this->wait_for_value(
            "return (function() { var m = document.getElementById('jedomi-messages');"
                . ' return m ? m.innerText.split(' . json_encode($text) . ').length - 1 : -1; })();',
            static fn($value): bool => (int) $value === $count,
            "\"{$text}\" is not shown {$count} times in the chat."
        );
    }

    /**
     * The chat permissions of the page have expired: the chat endpoint refuses every question as the server does.
     *
     * @Given /^the Jedomi chat permissions have expired$/
     * @return void
     */
    public function the_jedomi_chat_permissions_have_expired(): void {
        $message = json_encode(['error' => get_string('token_expired', 'local_jedomi')]);
        $this->execute_script('(function() { var next = window.fetch.bind(window);'
            . ' window.fetch = function(url, options) {'
            . " if (String(url).indexOf('/local/jedomi/chatproxy.php') !== -1) {"
            . ' return Promise.resolve(new Response(' . json_encode($message) . ', {status: 403,'
            . " headers: {'Content-Type': 'application/json'}})); }"
            . ' return next(url, options); }; })();');
    }
}
