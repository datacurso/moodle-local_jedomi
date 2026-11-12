@local @local_jedomi @javascript
Feature: Web chat of Jedomi from the question to the answer, the actions and the attachments
  In order to work with Moodle data from any page
  As a Moodle user
  I need the web chat to send my questions, show the answers, confirm actions and read my attachments

  # The AI service is a true external boundary. Scenarios that only need a given answer stub it in the browser for
  # determinism (the request still goes through the panel and the stub records what it would send). Scenarios that
  # need the service itself start with "the Jedomi AI service is available for acceptance tests" and are skipped,
  # as an environment limitation, when no licence is configured for the Behat site.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
      | student2 | Sue       | Student  | student2@example.com |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
      | manager1 | Mia       | Manager  | manager1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |

  # The AI answer is stubbed in the browser: this checks the web flow (multiline input, progress, formatting, CSV,
  # copy and rating). Answers built from real data are verified against the service in SYS-EVAL-001.
  @SYS-E2E-001
  Scenario: A multiline question sent with Enter shows the progress, a formatted answer that can be reused and rated
    Given I am on the "Course 1" "course" page logged in as "admin"
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI works on "Course 1 has two students." until released
    And I click on "#jedomi-input" "css_element"
    And I type "How many students"
    And I press the shift enter key
    And I type "are in this course?"
    When I press the enter key
    Then "//div[@id='jedomi-thinking-wrapper' and not(contains(@class, 'jedomi-hidden'))]" "xpath_element" should exist
    And the "#jedomi-send" "css_element" should be disabled
    And the last Jedomi request should carry "1" messages
    And the last Jedomi request should include the message "How many students"
    And the last Jedomi request should include the message "are in this course?"
    And the Jedomi AI finishes its answer
    And I should see "Course 1 has two students." in the "#jedomi-messages" "css_element"
    And the "#jedomi-send" "css_element" should be enabled
    And the Jedomi AI works on "Found them." using the tool "execute_sql" until released
    And I send "Look them up" to Jedomi
    And I should see "Querying database..." in the "#jedomi-messages" "css_element"
    And the "#jedomi-send" "css_element" should be disabled
    And the Jedomi AI finishes its answer
    And I should see "Found them." in the "#jedomi-messages" "css_element"
    And the Jedomi AI answers:
      """
      **2 students** in [Course 1](/course/view.php?id=2):

      | Name | Email |
      |---|---|
      | Sam Student | student1@example.com |
      | Sue Student | student2@example.com |
      """
    And I send "List them" to Jedomi
    And "//div[@id='jedomi-messages']//strong[normalize-space(.)='2 students']" "xpath_element" should exist
    And "//div[@id='jedomi-messages']//a[contains(@class, 'jedomi-moodle-link')]//span[contains(@class, 'jedomi-link-badge')]" "xpath_element" should exist
    And I click on "(//div[@id='jedomi-messages']//button[@data-csv-download])[last()]" "xpath_element"
    And the downloaded Jedomi CSV should contain the line:
      """
      "Sam Student","student1@example.com"
      """
    And I click on "(//button[@data-action='local_jedomi/sidebar/copy'])[last()]" "xpath_element"
    And I click on "(//button[@data-action='local_jedomi/sidebar/good'])[last()]" "xpath_element"
    And a Jedomi rating "good" should be stored for the user "admin"

  # The answers are stubbed in the browser; the check is the question each quick action sends for the page. The admin
  # actions of the administration and the course outside a course are covered by MDL-UNIT-018.
  @SYS-E2E-002
  Scenario: An administrator gets the course actions with the current course and the site actions on other pages
    Given I am on the "Course 1" "course" page logged in as "admin"
    And I click on "#jedomi-trigger" "css_element"
    And "//button[contains(@class, 'jedomi-quick-btn') and contains(@data-msg, 'Course 1')]" "xpath_element" should exist
    When I click on "Top student" "button" in the "#jedomi-quick-actions" "css_element"
    Then the last Jedomi request should include the message "Who is the top student in course"
    And the last Jedomi request should include the message "Course 1"
    And I am on site homepage
    And I click on "#jedomi-trigger" "css_element"
    And I click on "Overview" "button" in the "#jedomi-quick-actions" "css_element"
    And the last Jedomi request should include the message "Give me a summary of the overall platform status"

  @SYS-E2E-002
  Scenario: A manager gets the course actions inside a course and the global actions outside
    Given I am on the "Course 1" "course" page logged in as "manager1"
    And I click on "#jedomi-trigger" "css_element"
    When I click on "Enrolled" "button" in the "#jedomi-quick-actions" "css_element"
    Then the last Jedomi request should include the message "How many students are enrolled in course"
    And I am on site homepage
    And I click on "#jedomi-trigger" "css_element"
    And I should see "Global risk" in the "#jedomi-quick-actions" "css_element"
    And I should see "Inactive users" in the "#jedomi-quick-actions" "css_element"

  @SYS-E2E-002
  Scenario: A teacher gets the teacher actions of the course, including the ones of their permissions
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I click on "#jedomi-trigger" "css_element"
    And I should see "Missing work" in the "#jedomi-quick-actions" "css_element"
    And I should see "Notify group" in the "#jedomi-quick-actions" "css_element"
    And I should see "Top students" in the "#jedomi-quick-actions" "css_element"
    When I click on "Performance" "button" in the "#jedomi-quick-actions" "css_element"
    Then the last Jedomi request should include the message "What is the average grade in course"
    And the last Jedomi request should include the message "Course 1"
    And I am on site homepage
    And I click on "#jedomi-trigger" "css_element"
    And I should see "My courses" in the "#jedomi-quick-actions" "css_element"

  @SYS-E2E-002
  Scenario: A quick action replaces the typed text and the actions stay visible during the conversation
    Given I am on the "Course 1" "course" page logged in as "student1"
    And I click on "#jedomi-trigger" "css_element"
    And I set the field with xpath "//textarea[@id='jedomi-input']" to "Draft I was writing"
    When I click on "My tasks" "button" in the "#jedomi-quick-actions" "css_element"
    Then the last Jedomi request should not include the message "Draft I was writing"
    And I should see "OK" in the "#jedomi-messages" "css_element"
    And I should see "My tasks" in the "#jedomi-quick-actions" "css_element"
    And I should see "My progress" in the "#jedomi-quick-actions" "css_element"
    And I am on site homepage
    And I click on "#jedomi-trigger" "css_element"
    And I should see "My courses" in the "#jedomi-quick-actions" "css_element"

  # The proposal of the AI is stubbed; the action is held, confirmed, cancelled and expired by the real server.
  @SYS-E2E-003
  Scenario: A high level action is confirmed from its card and its result is shown and applied in Moodle
    Given I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "I will create the course." proposing to run "ws_execute" with:
      """
      {"wsname": "core_course_create_courses", "params": "{\"courses\": [{\"fullname\": \"Jedomi created course\", \"shortname\": \"JEDC1\", \"categoryid\": 1}]}"}
      """
    And I send "Create a course called Jedomi created course" to Jedomi
    And "//div[contains(@class, 'jedomi-action-pending--high')]" "xpath_element" should exist
    When I click on "[data-action='local_jedomi/sidebar/confirm-yes']" "css_element"
    Then I should see "✅" in the "#jedomi-messages" "css_element"
    And I am on course index
    And I should see "Jedomi created course"

  @SYS-E2E-003
  Scenario: A critical action needs the confirmation word and a cancelled action is not run
    Given the following Jedomi agents exist:
      | name           |
      | Critical agent |
    And I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "I will delete it." proposing to run "delete_agent" with:
      """
      {"id": %agent:Critical agent%}
      """
    And I send "Delete the agent Critical agent" to Jedomi
    And "//div[contains(@class, 'jedomi-action-pending--critical')]" "xpath_element" should exist
    When I click on "[data-action='local_jedomi/sidebar/confirm-yes']" "css_element"
    Then I should see "Type CONFIRM to proceed:" in the "#jedomi-messages" "css_element"
    And the Jedomi agent "Critical agent" should exist
    And I click on "[data-action='local_jedomi/sidebar/confirm-no']" "css_element"
    And I should see "cancelled" in the "#jedomi-messages" "css_element"
    And the Jedomi agent "Critical agent" should exist

  @SYS-E2E-003
  Scenario: An action confirmed after its time limit is not run and the expired message is shown
    Given I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "I will create the agent." proposing to run "create_agent" with:
      """
      {"name": "Late agent", "prompt": "Daily summary", "schedule_cron": "0 8 * * *"}
      """
    And I send "Create a daily summary agent" to Jedomi
    And "[data-action='local_jedomi/sidebar/confirm-yes']" "css_element" should exist
    And the held Jedomi actions have expired
    When I click on "[data-action='local_jedomi/sidebar/confirm-yes']" "css_element"
    Then I should see "This action has expired. Ask Jedomi again." in the "#jedomi-messages" "css_element"
    And the Jedomi agent "Late agent" should not exist

  # Needs the real AI service to find the recipients and propose the sending. The text and the count of the card are
  # covered by MDL-UNIT-023.
  @SYS-E2E-004
  Scenario: A teacher asks to notify the group and the message reaches the students after confirming
    Given the Jedomi AI service is available for acceptance tests
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And I click on "#jedomi-trigger" "css_element"
    When I send "Send a message to all the students of this course saying: the class of Friday is cancelled" to Jedomi
    Then I should see "Send this message to 2 student(s)?" in the "#jedomi-messages" "css_element"
    And I should see "the class of Friday is cancelled" in the "#jedomi-messages" "css_element"
    And I click on "[data-action='local_jedomi/sidebar/confirm-yes']" "css_element"
    And I should see "✅" in the "#jedomi-messages" "css_element"
    And I log out
    And I log in as "student1"
    And I open messaging
    And I should see "the class of Friday is cancelled"

  # Needs the real AI service for reading the image and the PDF.
  @SYS-E2E-005
  Scenario: An image and a PDF are read and their content can be asked about
    Given the Jedomi AI service is available for acceptance tests
    And the following config values are set as admin:
      | files_enabled | 1 | local_jedomi |
    And I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    When I attach the Jedomi test file "jedomi_text.png" to the panel image input
    Then "//div[@id='jedomi-image-preview' and not(contains(@class, 'jedomi-hidden'))]" "xpath_element" should exist
    And I send "Which number is written in the image?" to Jedomi
    And "//div[contains(@class, 'jedomi-message-user')]//img[contains(@class, 'jedomi-msg-image')]" "xpath_element" should exist
    And I should see "4271" in the "#jedomi-messages" "css_element"
    And I attach the Jedomi test file "jedomi_document.pdf" to the panel file input
    And I should see "jedomi_document.pdf" in the "#jedomi-file-preview" "css_element"
    And I click on "#jedomi-send" "css_element"
    And I should see "PDF: 1 pages" in the "#jedomi-messages" "css_element"
    And I should see "pineapple" in the "#jedomi-messages" "css_element"

  @SYS-E2E-005
  Scenario: A file type that cannot be read is refused with its message
    Given the following config values are set as admin:
      | files_enabled | 1 | local_jedomi |
    And I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    When I attach the Jedomi test file "archive.zip" to the panel file input
    Then I should see "Could not process" in the "#jedomi-messages" "css_element"
    And I should see "archive.zip" in the "#jedomi-messages" "css_element"
    And "//div[@id='jedomi-file-preview' and not(contains(@class, 'jedomi-hidden'))]" "xpath_element" should not exist

  # [Pendiente:skip] When several files are dropped only the last one is kept. The reading is answered in the browser.
  # Fails until fixed.
  @MDL-INT-052
  Scenario: Several dropped files are all attached
    Given I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    When I drop the files "first.txt, second.txt" on the Jedomi panel
    Then I should see "second.txt" in the "#jedomi-file-preview" "css_element"
    And I should see "first.txt" in the "#jedomi-file-preview" "css_element"

  # A file is read (and charged) only when the message is sent. The reading is answered in the browser.
  @MDL-INT-052
  Scenario: An attachment removed before sending is not read nor charged
    Given I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And I drop the files "notes.txt" on the Jedomi panel
    And I should see "notes.txt" in the "#jedomi-file-preview" "css_element"
    When I click on "#jedomi-remove-file" "css_element"
    Then "0" Jedomi file readings should have been requested

  # The browser of the Behat site has no microphone permission, as when a user denies it.
  @SYS-E2E-006
  Scenario: Without microphone permission the user is told how to allow it
    Given the following config values are set as admin:
      | audio_enabled | 1 | local_jedomi |
    And I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    When I click on "#jedomi-voice-btn" "css_element"
    Then I should see "Could not access the microphone. Please check your browser permissions." in the "#jedomi-messages" "css_element"

  # Needs the real AI service to transcribe the audio.
  @SYS-E2E-006
  Scenario: An attached audio file is transcribed when the message is sent and shows its duration
    Given the Jedomi AI service is available for acceptance tests
    And the following config values are set as admin:
      | audio_enabled | 1 | local_jedomi |
    And I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    When I attach the Jedomi test file "jedomi_tone.wav" to the panel audio input
    And I should see "jedomi_tone.wav" in the "#jedomi-file-preview" "css_element"
    And I click on "#jedomi-send" "css_element"
    Then I should see "Audio: 2 seconds" in the "#jedomi-messages" "css_element"
    And I should see "jedomi_tone.wav" in the "#jedomi-messages" "css_element"

  @MDL-UNIT-068 @MDL-INT-006
  Scenario: The voice conversation button is shown only while the voice conversation is enabled
    Given the following config values are set as admin:
      | audio_enabled | 1 | local_jedomi |
      | voice_enabled | 1 | local_jedomi |
    And I log in as "admin"
    And I am on homepage
    When I click on "#jedomi-trigger" "css_element"
    Then "#jedomi-voice-mode-btn" "css_element" should exist
    And the following config values are set as admin:
      | voice_enabled | 0 | local_jedomi |
    And I reload the page
    And I click on "#jedomi-trigger" "css_element"
    And "#jedomi-voice-btn" "css_element" should exist
    And "#jedomi-voice-mode-btn" "css_element" should not exist

  # Needs the real AI service for the summary. The context of the current activity is covered by MDL-INT-039, so the
  # activity is named in the question.
  @SYS-E2E-007
  Scenario: A teacher uses the assistant with the teacher actions and the real context of the course
    Given the Jedomi AI service is available for acceptance tests
    And the following config values are set as admin:
      | files_enabled | 1 | local_jedomi |
    And the following "activities" exist:
      | activity | name          | course | idnumber | defaultfilename                                          |
      | resource | Jedomi reader | C1     | res1     | local/jedomi/tests/fixtures/behat/jedomi_document.pdf    |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher2 | Tom       | Teacher  | teacher2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 2 | C2        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher2 | C2     | editingteacher |
    And I am on the "Course 1" "course" page logged in as "teacher1"
    When I click on "#jedomi-trigger" "css_element"
    Then I should see "Course 1" in the "#jedomi-context-label" "css_element"
    And I should see "Top students" in the "#jedomi-quick-actions" "css_element"
    And I should see "Notify group" in the "#jedomi-quick-actions" "css_element"
    And I send "Summarise the PDF of the activity Jedomi reader of Course 1" to Jedomi
    And I should see "pineapple" in the "#jedomi-messages" "css_element"
    And I log out
    And I am on the "Course 2" "course" page logged in as "teacher2"
    And I click on "#jedomi-trigger" "css_element"
    And I send "Summarise the PDF of the activity Jedomi reader of Course 1" to Jedomi
    And I should not see "pineapple" in the "#jedomi-messages" "css_element"

  # Without a licence the server answers the licence error, which is the same in every channel; the other failures
  # (no credit, usage limit, service down, timeout) need the AI service and are mapped by MDL-UNIT tests of the errors.
  @SYS-E2E-011
  Scenario: The web chat shows the specific message when the AI licence is not valid
    Given the Jedomi chat requests reach the server
    And I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    When I send "How many courses are there?" to Jedomi
    Then I should see "The AI license of this site is missing or not valid. Contact your site administrator." in the "#jedomi-messages" "css_element"

  @SYS-E2E-011
  Scenario: The agent preview shows the specific message when the AI licence is not valid
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    And I set the field "Prompt" to "How many courses are there?"
    When I click on "[data-action='local_jedomi/admin/agents/preview']" "css_element"
    Then I should see "The AI license of this site is missing or not valid. Contact your site administrator." in the "[data-region='local_jedomi/admin/agents/preview-body']" "css_element"
