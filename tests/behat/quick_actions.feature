@local @local_jedomi @javascript
Feature: Quick actions of the Jedomi panel by role and page
  In order to start a conversation with one click
  As a Moodle user
  I need the Jedomi panel to offer the quick actions of my role on the current page

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

  @MDL-UNIT-017
  Scenario: A student inside a course gets the course actions completed with the course
    Given I am on the "Course 1" "course" page logged in as "student1"
    When I click on "#jedomi-trigger" "css_element"
    Then I should see "My tasks" in the "#jedomi-quick-actions" "css_element"
    And I should see "My grade" in the "#jedomi-quick-actions" "css_element"
    And I should see "My progress" in the "#jedomi-quick-actions" "css_element"
    And I should see "Help" in the "#jedomi-quick-actions" "css_element"
    And I should not see "This week" in the "#jedomi-quick-actions" "css_element"
    And "//button[contains(@class, 'jedomi-quick-btn') and contains(@data-msg, 'with ID')]" "xpath_element" should exist

  @MDL-UNIT-017
  Scenario: A student outside a course gets the general student actions
    Given I log in as "student1"
    And I am on homepage
    When I click on "#jedomi-trigger" "css_element"
    Then I should see "My courses" in the "#jedomi-quick-actions" "css_element"
    And I should see "My grades" in the "#jedomi-quick-actions" "css_element"
    And I should see "This week" in the "#jedomi-quick-actions" "css_element"
    And I should not see "My tasks" in the "#jedomi-quick-actions" "css_element"

  @MDL-UNIT-017
  Scenario: An administrator outside a course gets the site overview actions
    Given I log in as "admin"
    And I am on homepage
    When I click on "#jedomi-trigger" "css_element"
    Then I should see "Overview" in the "#jedomi-quick-actions" "css_element"
    And I should see "Alerts" in the "#jedomi-quick-actions" "css_element"
    And I should see "Top courses" in the "#jedomi-quick-actions" "css_element"
    And I should see "Storage" in the "#jedomi-quick-actions" "css_element"
