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
    And I press "Create agent"
    Then I should see "Agent \"How many users logged in\" created successfully."
    And the Jedomi agent "How many users logged in" should exist
    And I press "View details" action in the "How many users logged in" report row
    And I should see "How many users logged in this week? Show the list." in the ".modal-dialog" "css_element"
    And I click on "Close" "button" in the ".modal-dialog" "css_element"
    And I press "Edit" action in the "How many users logged in" report row
    And the field "Prompt" matches value "How many users logged in this week? Show the list."
    And the field "Name" matches value "How many users logged in"
    And I click on "[data-action='local_jedomi/admin/agents/cancel-edit']" "css_element"
    And the field "Prompt" matches value ""
    And I should see "Create agent" in the "[data-region='local_jedomi/admin/agents/form-heading']" "css_element"

  @MDL-E2E-007
  Scenario: An agent is enabled, queued to run now and deleted after confirming
    Given the following Jedomi agents exist:
      | name          | enabled |
      | Weekly logins | 0       |
    And I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    When I press "Run now" action in the "Weekly logins" report row
    And I should see "This agent is disabled, so it was not queued. Enable it to run it."
    And I press "Enable" action in the "Weekly logins" report row
    Then I should see "Enabled" in the "Weekly logins" "table_row"
    And I press "Run now" action in the "Weekly logins" report row
    And I should see "Agent queued for execution (next cron cycle)."
    And I press "Delete" action in the "Weekly logins" report row
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
    And I set the field "Context ID" in the "[data-form='local_jedomi/admin/agents/purge']" "css_element" to the context id of the course "C1"
    And I press "Purge context agents"
    And I click on "Delete" "button" in the ".modal-dialog" "css_element"
    Then I should see "deleted successfully." in the "[data-region='local_jedomi/admin/agents/purge-message']" "css_element"
    And I should not see "Course report" in the "table" "css_element"
    And I should see "Site report" in the "table" "css_element"
    And the Jedomi agent "Course report" should not exist

  @MDL-E2E-008
  Scenario: The query profiler shows indicators, filters by minimum duration, groups the slowest queries and leads to the agent
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
    And "SELECT slowest FROM {course}" "text" should appear before "SELECT quick FROM {user}" "text"
    And I should see "SELECT quick FROM {user}" in the "[data-region='local_jedomi/admin/profiler/recent']" "css_element"
    And I click on "Filters" "button" in the "[data-region='local_jedomi/admin/profiler/recent']" "css_element"
    And I set the following fields in the "Minimum duration (ms)" "core_reportbuilder > Filter" to these values:
      | Minimum duration (ms) operator | Greater than or equal |
      | Minimum duration (ms) value    | 1000                  |
    And I click on "Apply" "button" in the "[data-region='local_jedomi/admin/profiler/recent'] [data-region='report-filters']" "css_element"
    And I should not see "SELECT quick FROM {user}" in the "[data-region='local_jedomi/admin/profiler/recent']" "css_element"
    And I should see "2" in the "[data-indicator='total']" "css_element"
    And I click on "a" "css_element" in the "SELECT slowest FROM {course}" "table_row"
    And the url should match "edit_agent="
    And the field "Prompt" matches value "Report for Slow report"
    And I should see "Edit" in the "[data-region='local_jedomi/admin/agents/form-heading']" "css_element"

  @MDL-E2E-007
  Scenario: The agents report filters the agents and the charts by status
    Given the following Jedomi agents exist:
      | name         | enabled |
      | Active agent | 1       |
      | Paused agent | 0       |
    And I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    And I should see "Paused agent" in the "table" "css_element"
    When I click on "Filters" "button"
    And I set the following fields in the "Status" "core_reportbuilder > Filter" to these values:
      | Status operator | Is equal to |
      | Status value    | Enabled     |
    And I click on "Apply" "button" in the "[data-region='report-filters']" "css_element"
    Then I should see "Active agent" in the "table" "css_element"
    And I should not see "Paused agent" in the "table" "css_element"

  # The schedule is computed in the site time zone (Australia/Perth on the Behat site).
  @SYS-E2E-009
  Scenario: An enabled daily agent is scheduled for its hour in the site time zone
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=agents"
    And I set the field "Prompt" to "How many courses are there in the site?"
    And I set the field "Name" to "Daily courses"
    And I set the field "Schedule" to "Daily"
    And I press "Create agent"
    And I should see "Agent \"Daily courses\" created successfully."
    When I press "Enable" action in the "Daily courses" report row
    And I should see "Enabled" in the "Daily courses" "table_row"
    And I press "View details" action in the "Daily courses" report row
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
