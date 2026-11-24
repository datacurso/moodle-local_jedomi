@local @local_jedomi @javascript
Feature: Availability of the Jedomi panel by course and visibility by role
  In order to offer the assistant only where and to whom it is useful
  As an administrator
  I need the Courses and Privacy tabs to decide in which courses and for which roles the panel appears

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | teacher1 | Tina      | Teacher  |
      | multi1   | Max       | Multi    |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
      | Course 2 | C2        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student1 | C2     | student        |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C2     | editingteacher |
      | multi1   | C1     | student        |
      | multi1   | C1     | editingteacher |

  @MDL-E2E-002
  Scenario: Hiding the panel in a selected course hides it there only
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=courses"
    And I click on "//input[@name='course_mode' and @value='exclude']" "xpath_element"
    And I set the field "Selected courses" to "Course 1"
    And I press "Save changes"
    And I should see "Changes saved"
    And I log out
    When I am on the "Course 1" "course" page logged in as "student1"
    Then "#jedomi-trigger" "css_element" should not exist
    And I am on "Course 2" course homepage
    And "#jedomi-trigger" "css_element" should exist

  @MDL-E2E-002
  Scenario: Showing the panel only in a selected course shows it there only, among the courses
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=courses"
    And I click on "//input[@name='course_mode' and @value='include']" "xpath_element"
    And I set the field "Selected courses" to "Course 1"
    And I press "Save changes"
    And I should see "Changes saved"
    And I log out
    When I am on the "Course 1" "course" page logged in as "student1"
    Then "#jedomi-trigger" "css_element" should exist
    And I am on "Course 2" course homepage
    And "#jedomi-trigger" "css_element" should not exist

  @MDL-E2E-002
  Scenario: Hiding a role in the Privacy tab hides the panel from users with only that role
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=privacy"
    And I set the field "Student" to "0"
    And I press "Save changes"
    And I should see "Changes saved"
    And I log out
    When I am on the "Course 1" "course" page logged in as "student1"
    Then "#jedomi-trigger" "css_element" should not exist

  @MDL-E2E-002
  Scenario: A user with another visible role and the administrator still see the panel when a role is hidden
    Given the role "student" is hidden from Jedomi
    When I am on the "Course 1" "course" page logged in as "multi1"
    Then "#jedomi-trigger" "css_element" should exist
    And I log out
    And I am on the "Course 1" "course" page logged in as "teacher1"
    And "#jedomi-trigger" "css_element" should exist
    And I log out
    And I am on the "Course 1" "course" page logged in as "admin"
    And "#jedomi-trigger" "css_element" should exist
