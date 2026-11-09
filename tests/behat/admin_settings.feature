@local @local_jedomi
Feature: Jedomi settings page
  In order to configure Jedomi
  As an administrator
  I need a settings page with one tab per area that saves its changes and is only open to administrators

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | manager1 | Mia       | Manager  |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |

  @MDL-INT-020
  Scenario: The administrator sees the seven tabs and the AI service card of the General tab
    Given I log in as "admin"
    When I visit "/local/jedomi/admin_settings.php"
    Then I should see "General"
    And I should see "Course availability"
    And I should see "Privacy"
    And I should see "Integrations"
    And I should see "APIs"
    And I should see "Agents"
    And I should see "Query Profiler"
    And "//a[contains(@href, '/ai/provider/datacurso/admin/plugin_settings.php') and contains(@href, 'component=local_jedomi')]" "xpath_element" should exist

  @MDL-INT-020
  Scenario: Saving a tab shows the saved notice and keeps the open tab in the address
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=privacy"
    When I press "Save changes"
    Then I should see "Changes saved"
    And the url should match "tab=privacy"

  @MDL-INT-020 @MDL-INT-075
  Scenario: A user without the site configuration permission cannot open the settings
    Given the following "permission overrides" exist:
      | capability         | permission | role    | contextlevel | reference |
      | moodle/site:config | Prohibit   | manager | System       |           |
    And I log in as "manager1"
    When I visit "/local/jedomi/admin_settings.php"
    Then I should not see "Query Profiler"
    And I should see "Sorry, but you do not currently have permissions to do that"

  @MDL-INT-016 @MDL-INT-075
  Scenario Outline: The webhook diagnostic actions require the site configuration permission
    Given I log in as "manager1"
    When I visit "<action>"
    Then I should see "Sorry, but you do not currently have permissions to do that"

    Examples:
      | action                                         |
      | /local/jedomi/ajax/telegram_webhook_status.php |
      | /local/jedomi/ajax/telegram_drop_pending.php   |

  # [Pendiente:fail] Saving replaces the list with the boxes of the visible page or filter only.
  @MDL-INT-018
  Scenario: Saving the web service list from another filter keeps the functions allowed in the first one
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=apis&role=teacher&ap_search=core_course_get_courses"
    And I set the field "core_course_get_courses" to "1"
    And I press "Save changes"
    And I visit "/local/jedomi/admin_settings.php?tab=apis&role=teacher&ap_search=core_user_get_users_by_field"
    And I set the field "core_user_get_users_by_field" to "1"
    And I press "Save changes"
    When I visit "/local/jedomi/admin_settings.php?tab=apis&role=teacher&ap_search=core_course_get_courses"
    Then the field "core_course_get_courses" matches value "1"

  @MDL-INT-021 @javascript
  Scenario: An agent is created, queued to run now and deleted after confirming
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    When I set the field "Prompt" to "How many users logged in this week?"
    And I set the field "Name" to "Weekly logins"
    And I press "Create Agent"
    Then I should see "Agent \"Weekly logins\" created successfully."
    And the Jedomi agent "Weekly logins" should exist
    And I click on "Agent actions" "button" in the "Weekly logins" "table_row"
    And I click on "Run Now" "link" in the "Weekly logins" "table_row"
    And I should see "Agent queued for execution (next cron cycle)."
    And I click on "Agent actions" "button" in the "Weekly logins" "table_row"
    And I click on "Delete" "link" in the "Weekly logins" "table_row"
    And I click on "Delete" "button" in the ".modal-dialog" "css_element"
    And I should not see "Weekly logins" in the "table" "css_element"
    And the Jedomi agent "Weekly logins" should not exist

  @MDL-INT-025 @javascript
  Scenario: The preview asks for a prompt first
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    When I click on "[data-action='local_jedomi/admin/agents/preview']" "css_element"
    Then I should see "Write a prompt first."

  # [Pendiente:fail] Purging by Site deletes every agent and the final message keeps an unreplaced placeholder.
  @MDL-INT-027 @javascript
  Scenario: Purging by Site deletes only the site agents and says how many were deleted
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following Jedomi agents exist:
      | name          | context | reference |
      | Site report   | site    |           |
      | Site digest   | site    |           |
      | Course report | course  | C1        |
    And I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    When I set the field "Context type" to "Site"
    And I press "Purge"
    And I click on "Delete" "button" in the ".modal-dialog" "css_element"
    Then I should see "2 agent(s) deleted successfully."
    And the Jedomi agent "Site report" should not exist
    And the Jedomi agent "Site digest" should not exist
    And the Jedomi agent "Course report" should exist

  @MDL-INT-075
  Scenario Outline: The APIs tab and its function table require the site configuration permission
    Given I log in as "manager1"
    When I visit "<page>"
    Then I should see "Sorry, but you do not currently have permissions to do that"

    Examples:
      | page                                        |
      | /local/jedomi/admin_settings.php?tab=apis   |
      | /local/jedomi/ajax/ws_table.php             |
