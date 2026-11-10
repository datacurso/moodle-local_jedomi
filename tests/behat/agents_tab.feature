@local @local_jedomi @javascript
Feature: Scheduled agents and their queries in the Jedomi settings
  In order to automate reports and check what they cost
  As an administrator
  I need to manage the life cycle of the agents, preview them and analyse their queries

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |

  @MDL-E2E-007
  Scenario: An agent created without a name gets a name from its prompt and can be viewed, edited and cancelled
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    When I set the field "Prompt" to "How many users logged in this week? Show the list."
    And I press "Create Agent"
    Then I should see "Agent \"How many users logged in\" created successfully."
    And the Jedomi agent "How many users logged in" should exist
    And I click on "Agent actions" "button" in the "How many users logged in" "table_row"
    And I click on "View details" "link" in the "How many users logged in" "table_row"
    And I should see "How many users logged in this week? Show the list." in the ".modal-dialog" "css_element"
    And I click on "Close" "button" in the ".modal-dialog" "css_element"
    And I click on "Agent actions" "button" in the "How many users logged in" "table_row"
    And I click on "Edit" "link" in the "How many users logged in" "table_row"
    And the field "Prompt" matches value "How many users logged in this week? Show the list."
    And the field "Name" matches value "How many users logged in"
    And I click on "[data-action='local_jedomi/admin/agents/cancel-edit']" "css_element"
    And the field "Prompt" matches value ""
    And I should see "Create Agent" in the "[data-region='local_jedomi/admin/agents/form-heading']" "css_element"

  @MDL-E2E-007
  Scenario: An agent is enabled, queued to run now and deleted after confirming
    Given the following Jedomi agents exist:
      | name          | enabled |
      | Weekly logins | 0       |
    And I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    When I click on "Agent actions" "button" in the "Weekly logins" "table_row"
    And I click on "Enable" "link" in the "Weekly logins" "table_row"
    Then I should see "Enabled" in the "Weekly logins" "table_row"
    And I click on "Agent actions" "button" in the "Weekly logins" "table_row"
    And I click on "Run Now" "link" in the "Weekly logins" "table_row"
    And I should see "Agent queued for execution (next cron cycle)."
    And I click on "Agent actions" "button" in the "Weekly logins" "table_row"
    And I click on "Delete" "link" in the "Weekly logins" "table_row"
    And I click on "Delete" "button" in the ".modal-dialog" "css_element"
    And I should not see "Weekly logins" in the "table" "css_element"
    And the Jedomi agent "Weekly logins" should not exist

  @MDL-E2E-007
  Scenario: Purging the agents of a course removes them from the table with a success notice
    Given the following Jedomi agents exist:
      | name          | context | reference |
      | Course report | course  | C1        |
      | Site report   | site    |           |
    And I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    And I should see "Course report" in the "table" "css_element"
    When I set the field "Context type" to "Course"
    And I set the field "Context ID" in the "[data-form='local_jedomi/admin/agents/purge']" "css_element" to the id of the course "C1"
    And I press "Purge"
    And I click on "Delete" "button" in the ".modal-dialog" "css_element"
    Then I should see "deleted successfully." in the "[data-region='local_jedomi/admin/agents/purge-message']" "css_element"
    And I should not see "Course report" in the "table" "css_element"
    And I should see "Site report" in the "table" "css_element"
    And the Jedomi agent "Course report" should not exist

  @MDL-E2E-008
  Scenario: The query profiler shows indicators, filters by minimum duration, sorts by duration and leads to the agent
    Given the following Jedomi agents exist:
      | name          | prompt                   |
      | Slow report   | Report for Slow report   |
      | Quick report  | Report for Quick report  |
    And the following Jedomi agent queries are logged:
      | agent        | query                         | duration | rows |
      | Slow report  | SELECT slowest FROM {course}  | 2500     | 10   |
      | Slow report  | SELECT slower FROM {course}   | 1500     | 4    |
      | Quick report | SELECT quick FROM {user}      | 20       | 1    |
    And I log in as "admin"
    When I visit "/local/jedomi/admin_settings.php?tab=profiler"
    Then I should see "Total queries"
    And I should see "Slow queries (>1s)"
    And I should see "Slowest queries (avg)"
    And I should see "SELECT quick FROM {user}"
    And I set the field "Minimum duration (ms)" to "1000"
    And I set the field "Sort by" to "Duration"
    And I press "Apply Filters"
    And "SELECT quick FROM {user}" "table_row" should not exist
    And "SELECT slowest FROM {course}" "text" should appear before "SELECT slower FROM {course}" "text"
    And I click on "a" "css_element" in the "SELECT slowest FROM {course}" "table_row"
    And the url should match "edit_agent="
    And the field "Prompt" matches value "Report for Slow report"
    And I should see "Edit" in the "[data-region='local_jedomi/admin/agents/form-heading']" "css_element"

  # The schedule is computed in the site time zone (Australia/Perth on the Behat site).
  @SYS-E2E-009
  Scenario: An enabled daily agent is scheduled for its hour in the site time zone
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    And I set the field "Prompt" to "How many courses are there in the site?"
    And I set the field "Name" to "Daily courses"
    And I set the field "Schedule" to "Daily"
    And I press "Create Agent"
    And I should see "Agent \"Daily courses\" created successfully."
    When I click on "Agent actions" "button" in the "Daily courses" "table_row"
    And I click on "Enable" "link" in the "Daily courses" "table_row"
    And I should see "Enabled" in the "Daily courses" "table_row"
    And I click on "Agent actions" "button" in the "Daily courses" "table_row"
    And I click on "View details" "link" in the "Daily courses" "table_row"
    Then I should see "12:00 AM" in the ".modal-dialog" "css_element"

  # Needs the real AI service (JEDOMI_BEHAT_LICENSE).
  @SYS-E2E-010
  Scenario: The preview of an agent shows its timeline, tools, total time and answer, and uses the cache until cleared
    Given the Jedomi AI service is available for acceptance tests
    And I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    And I set the field "Prompt" to "How many courses exist in the site? Query the database to count them."
    When I click on "[data-action='local_jedomi/admin/agents/preview']" "css_element"
    Then I should see "Conversation timeline" in the "[data-region='local_jedomi/admin/agents/preview-body']" "css_element"
    And I should see "Total:" in the "[data-region='local_jedomi/admin/agents/preview-body']" "css_element"
    And I should see "execute_sql" in the "[data-region='local_jedomi/admin/agents/preview-body']" "css_element"
    And I should see "rows" in the "[data-region='local_jedomi/admin/agents/preview-body']" "css_element"
    And I should not see "denied" in the "[data-region='local_jedomi/admin/agents/preview-body']" "css_element"
    And I click on "[data-action='local_jedomi/admin/agents/preview']" "css_element"
    And I should see "Cached result from" in the "[data-region='local_jedomi/admin/agents/preview-body']" "css_element"
    And I press "Clear cache"
    And I click on "[data-action='local_jedomi/admin/agents/preview']" "css_element"
    And I should not see "Cached result from" in the "[data-region='local_jedomi/admin/agents/preview-body']" "css_element"
    And I should see "Conversation timeline" in the "[data-region='local_jedomi/admin/agents/preview-body']" "css_element"
