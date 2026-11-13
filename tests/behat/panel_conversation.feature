@local @local_jedomi @javascript
Feature: Conversation in the Jedomi panel
  In order to work with the AI assistant without leaving the page
  As a Moodle user
  I need the Jedomi panel to show its progress, adapt its width and let me edit and export the conversation

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |

  @MDL-UNIT-060
  Scenario: The panel changes its width with the half and full screen modes and the active mode toggles back
    Given I log in as "student1"
    And I change viewport size to "1366x768"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And I remember the width of the Jedomi panel
    When I click on "#jedomi-expand-half" "css_element"
    Then "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-expand-half')]" "xpath_element" should exist
    And the Jedomi panel should be wider than the remembered width
    When I click on "#jedomi-expand-full" "css_element"
    Then "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-expand-full')]" "xpath_element" should exist
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-expand-half')]" "xpath_element" should not exist
    And the Jedomi panel should fill the window width
    When I click on "#jedomi-expand-full" "css_element"
    Then "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-expand-')]" "xpath_element" should not exist
    And the Jedomi panel should be as wide as the remembered width

  @MDL-UNIT-060
  Scenario: The chosen width is not kept when navigating to another page
    Given I log in as "student1"
    And I change viewport size to "1366x768"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And I click on "#jedomi-expand-half" "css_element"
    When I am on "Course 1" course homepage
    And I click on "#jedomi-trigger" "css_element"
    Then "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-expand-')]" "xpath_element" should not exist

  @MDL-UNIT-060
  Scenario: On a mobile screen the panel takes the whole screen
    Given I log in as "student1"
    And I change viewport size to "mobile"
    And I am on homepage
    When I click on "#jedomi-trigger" "css_element"
    Then the Jedomi panel should fill the window width

  @MDL-UNIT-066
  Scenario: A long page title is cut in the panel header
    Given I log in as "admin"
    And the site home title is "60" characters long for Jedomi
    When I click on "#jedomi-trigger" "css_element"
    Then the Jedomi header should show the page title cut with an ellipsis within "35" characters

  @MDL-UNIT-066
  Scenario: A short page title is shown complete in the panel header
    Given I log in as "admin"
    And the site home title is "20" characters long for Jedomi
    When I click on "#jedomi-trigger" "css_element"
    Then the Jedomi header should show the page title complete within "35" characters

  @MDL-UNIT-066
  Scenario: A page title exactly at the limit is shown complete in the panel header
    Given I log in as "admin"
    And the site home title is "35" characters long for Jedomi
    When I click on "#jedomi-trigger" "css_element"
    Then the Jedomi header should show the page title complete within "35" characters

  @MDL-UNIT-066
  Scenario: A page title one character over the limit is cut in the panel header
    Given I log in as "admin"
    And the site home title is "36" characters long for Jedomi
    When I click on "#jedomi-trigger" "css_element"
    Then the Jedomi header should show the page title cut with an ellipsis within "35" characters

  @MDL-UNIT-067
  Scenario: Without a conversation the panel greets the user and warns that the AI can make mistakes
    Given I log in as "student1"
    And I am on homepage
    When I click on "#jedomi-trigger" "css_element"
    Then I should see "Hi, Sam Student!" in the "#jedomi-messages" "css_element"
    And I should see "I'm Jedomi, your Moodle AI assistant." in the "#jedomi-messages" "css_element"
    And I should see "Jedomi is an AI and can make mistakes." in the "#jedomi-sidebar" "css_element"

  @MDL-UNIT-075
  Scenario: The panel shows that the assistant is thinking and which tool it is using
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI works on "You have one course." until released
    When I send "How many courses do I have?" to Jedomi
    Then "//div[@id='jedomi-thinking-wrapper' and not(contains(@class, 'jedomi-hidden'))]" "xpath_element" should exist
    And the Jedomi AI finishes its answer
    And I should see "You have one course." in the "#jedomi-messages" "css_element"
    And the Jedomi AI works on "There are 3 tables." using the tool "search_tables" until released
    When I send "Which tables store grades?" to Jedomi
    Then I should see "Searching tables..." in the "#jedomi-messages" "css_element"
    And the Jedomi AI finishes its answer
    And I should see "There are 3 tables." in the "#jedomi-messages" "css_element"

  @MDL-UNIT-075
  Scenario: A tool without its own description shows the generic status
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI works on "Done." using the tool "mystery_tool" until released
    When I send "Do something" to Jedomi
    Then I should see "Running tool..." in the "#jedomi-messages" "css_element"
    And the Jedomi AI finishes its answer
    And I should see "Done." in the "#jedomi-messages" "css_element"

  @MDL-UNIT-076
  Scenario: The export file has every message of the user and of the assistant and the date in its name
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi conversation export is captured
    And the Jedomi AI answers "Your next task is the essay."
    And I send "What is my next task?" to Jedomi
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"
    When I click on "#jedomi-export" "css_element"
    Then the exported Jedomi conversation should contain "What is my next task?"
    And the exported Jedomi conversation should contain "Your next task is the essay."
    And the exported Jedomi conversation file name should include the date of today

  @MDL-UNIT-076
  Scenario: Without messages nothing is downloaded
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And I click on "#jedomi-clear" "css_element"
    And the Jedomi conversation export is captured
    When I click on "#jedomi-export" "css_element"
    Then no Jedomi conversation should have been exported

  @MDL-UNIT-077
  Scenario: Edit puts the last message back in the field and removes the last exchange
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "First answer."
    And I send "First question" to Jedomi
    And I should see "First answer." in the "#jedomi-messages" "css_element"
    And the Jedomi AI answers "Second answer."
    And I send "Second question" to Jedomi
    And I should see "Second answer." in the "#jedomi-messages" "css_element"
    When I click on "(//button[@data-action='local_jedomi/sidebar/edit'])[last()]" "xpath_element"
    Then the Jedomi message field should contain "Second question"
    And I should not see "Second answer." in the "#jedomi-messages" "css_element"
    And I should not see "Second question" in the "#jedomi-messages" "css_element"
    And I should see "First question" in the "#jedomi-messages" "css_element"
    And I should see "First answer." in the "#jedomi-messages" "css_element"

  @MDL-INT-054
  Scenario: The conversation is kept when navigating and reloading during the session
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Your next task is the essay."
    And I send "What is my next task?" to Jedomi
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"
    When I reload the page
    And I click on "#jedomi-trigger" "css_element"
    Then I should see "What is my next task?" in the "#jedomi-messages" "css_element"
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"
    And I am on "Course 1" course homepage
    And I click on "#jedomi-trigger" "css_element"
    And I should see "What is my next task?" in the "#jedomi-messages" "css_element"
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"

  @MDL-INT-054
  Scenario: The conversation is lost when the session is closed
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Your next task is the essay."
    And I send "What is my next task?" to Jedomi
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"
    When I log out
    And I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    Then I should not see "What is my next task?" in the "#jedomi-messages" "css_element"
    And I should see "Hi, Sam Student!" in the "#jedomi-messages" "css_element"

  @MDL-INT-054
  Scenario: New conversation clears the history and shows the new conversation message
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Your next task is the essay."
    And I send "What is my next task?" to Jedomi
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"
    When I click on "#jedomi-clear" "css_element"
    Then I should see "New conversation started. How can I help you?" in the "#jedomi-messages" "css_element"
    And I should not see "What is my next task?" in the "#jedomi-messages" "css_element"
    And I reload the page
    And I click on "#jedomi-trigger" "css_element"
    And I should not see "What is my next task?" in the "#jedomi-messages" "css_element"

  @MDL-E2E-010
  Scenario: The conversation is kept during the session, exported, restarted on request and gone after logging out
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Your next task is the essay."
    And I send "What is my next task?" to Jedomi
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"
    When I am on "Course 1" course homepage
    And I reload the page
    Then I should see "What is my next task?" in the "#jedomi-messages" "css_element"
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"
    And I open the current page in a new browser window
    And I click on "#jedomi-trigger" "css_element"
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"
    And the Jedomi conversation export is captured
    And I click on "#jedomi-export" "css_element"
    And the exported Jedomi conversation should contain "What is my next task?"
    And the exported Jedomi conversation should contain "Your next task is the essay."
    And the exported Jedomi conversation file name should include the date of today
    And I click on "#jedomi-clear" "css_element"
    And I should see "New conversation started. How can I help you?" in the "#jedomi-messages" "css_element"
    And I should not see "What is my next task?" in the "#jedomi-messages" "css_element"
    And the Jedomi AI answers "Course 1 has two sections."
    And I send "Describe my course" to Jedomi
    And I should see "Course 1 has two sections." in the "#jedomi-messages" "css_element"
    And I log out
    And I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And I should not see "Describe my course" in the "#jedomi-messages" "css_element"
    And I should see "Hi, Sam Student!" in the "#jedomi-messages" "css_element"

  # Only the last question can be edited, and the removed messages do not come back after reloading.
  @MDL-E2E-011
  Scenario: Only the last question can be edited and the removed messages do not come back after reloading
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "First answer."
    And I send "First question" to Jedomi
    And I should see "First answer." in the "#jedomi-messages" "css_element"
    And the Jedomi AI answers "Second answer."
    And I send "Second question" to Jedomi
    And I should see "Second answer." in the "#jedomi-messages" "css_element"
    And "//button[@data-action='local_jedomi/sidebar/edit']" "xpath_element" should exist
    And "(//button[@data-action='local_jedomi/sidebar/edit'])[2]" "xpath_element" should not exist
    When I click on "//button[@data-action='local_jedomi/sidebar/edit']" "xpath_element"
    Then the Jedomi message field should contain "Second question"
    And I should not see "Second answer." in the "#jedomi-messages" "css_element"
    And I should see "First answer." in the "#jedomi-messages" "css_element"
    And I reload the page
    And I click on "#jedomi-trigger" "css_element"
    And I should see "First answer." in the "#jedomi-messages" "css_element"
    And I should not see "Second answer." in the "#jedomi-messages" "css_element"
    And I should not see "Second question" in the "#jedomi-messages" "css_element"

  # The stop button ends a written answer in progress and the user can write again.
  @MDL-E2E-012
  Scenario: An answer in progress can be stopped and the user can write again
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI works on "A very long answer." until released
    And I send "Write a long report" to Jedomi
    When I click on "Stop" "button" in the "#jedomi-sidebar" "css_element"
    Then the "#jedomi-send" "css_element" should be enabled
    And I should not see "A very long answer." in the "#jedomi-messages" "css_element"

  # A message whose answer failed leaves the history, so it is not sent again as if it had been answered.
  @MDL-E2E-012
  Scenario: A message whose answer failed does not stay in the history without an answer
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI fails with the message "Jedomi could not answer this time."
    When I send "Question that fails" to Jedomi
    Then I should see "Jedomi could not answer this time." in the "#jedomi-messages" "css_element"
    And the stored Jedomi conversation should not include the message "Question that fails"

  @MDL-E2E-014
  Scenario: An action waiting for confirmation is still offered after reloading the page
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "I can create it for you." proposing to run "create_agent" with:
      """
      {"name": "Reload agent", "prompt": "Daily summary", "schedule_cron": "0 8 * * *"}
      """
    And I send "Create a daily summary agent" to Jedomi
    And "[data-action='local_jedomi/sidebar/confirm-yes']" "css_element" should exist
    When I reload the page
    And I click on "#jedomi-trigger" "css_element"
    Then "[data-action='local_jedomi/sidebar/confirm-yes']" "css_element" should exist
    And "[data-action='local_jedomi/sidebar/confirm-no']" "css_element" should exist

  @MDL-UNIT-045
  Scenario: A conversation just at the limit is sent to the AI complete
    Given I log in as "student1"
    And I am on homepage
    And I have a Jedomi conversation of "29" messages
    And I click on "#jedomi-trigger" "css_element"
    And I should see "Message 029" in the "#jedomi-messages" "css_element"
    When I send "Message 030" to Jedomi
    Then the last Jedomi request should carry "30" messages
    And the last Jedomi request should include the message "Message 001"
    And the last Jedomi request should include the message "Message 030"

  @MDL-UNIT-045
  Scenario: A conversation over the limit sends only the most recent messages to the AI
    Given I log in as "student1"
    And I am on homepage
    And I have a Jedomi conversation of "30" messages
    And I click on "#jedomi-trigger" "css_element"
    And I should see "Message 030" in the "#jedomi-messages" "css_element"
    When I send "Message 031" to Jedomi
    Then the last Jedomi request should carry "30" messages
    And the last Jedomi request should not include the message "Message 001"
    And the last Jedomi request should include the message "Message 002"
    And the last Jedomi request should include the message "Message 031"

  @MDL-UNIT-045
  Scenario: The history saved in the session keeps about its maximum and drops the oldest messages
    Given I log in as "student1"
    And I am on homepage
    When I have a Jedomi conversation of "40" messages
    Then the stored Jedomi conversation should have exactly "40" messages
    And the stored Jedomi conversation should include the message "Message 001"
    And I have a Jedomi conversation of "1" messages
    And the stored Jedomi conversation should have at most "40" messages
    And the stored Jedomi conversation should not include the message "Message 002"
    And the stored Jedomi conversation should include the message "Message 040"
