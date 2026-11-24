@local @local_jedomi @javascript
Feature: Location of the page sent to the Jedomi AI
  In order to get answers about the page I am on
  As a Moodle user
  I need the Jedomi panel to tell the AI which kind of page I am on

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "activities" exist:
      | activity | name   | course |
      | page     | Page 1 | C1     |

  @MDL-UNIT-073
  Scenario Outline: Each kind of page is sent to the AI with its location
    Given I log in as "admin"
    And I visit "<url>"
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Noted."
    When I send "Where am I?" to Jedomi
    Then the last Jedomi request should be sent with the location "<location>"

    Examples:
      | url                                | location  |
      | /admin/search.php                  | admin     |
      | /my/                               | dashboard |
      | /message/index.php                 | messages  |
      | /user/profile.php                  | profile   |
      | /calendar/view.php?view=month      | calendar  |
      | /tag/search.php                    | unknown   |

  # The location is read by the server from the page, so the private files page is not taken for the profile.
  @MDL-UNIT-073
  Scenario: The private files page is sent with the files location
    Given I log in as "admin"
    And I visit "/user/files.php"
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Noted."
    When I send "Where am I?" to Jedomi
    Then the last Jedomi request should be sent with the location "files"

  @MDL-UNIT-073
  Scenario: A course page is sent with the course location
    Given I am on the "Course 1" course page logged in as "admin"
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Noted."
    When I send "Where am I?" to Jedomi
    Then the last Jedomi request should be sent with the location "course"

  @MDL-UNIT-073
  Scenario: An activity page is sent with the activity location
    Given I am on the "Page 1" "page activity" page logged in as "admin"
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Noted."
    When I send "Where am I?" to Jedomi
    Then the last Jedomi request should be sent with the location "activity"

  @MDL-UNIT-073
  Scenario: A grades page is sent with the grades location
    Given I am on the "Course 1" "grades > Grader report > View" page logged in as "admin"
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Noted."
    When I send "Where am I?" to Jedomi
    Then the last Jedomi request should be sent with the location "grades"
