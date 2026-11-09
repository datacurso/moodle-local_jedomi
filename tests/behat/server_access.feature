@local @local_jedomi @javascript
Feature: The server applies the course availability and the role visibility of Jedomi
  In order to control who uses the assistant
  As an administrator
  I need the server to refuse the requests the panel would not allow, and not only hide the button

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | creator1 | Cora      | Creator  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | creator1 | C1     | student |

  @MDL-INT-009
  Scenario: In an available course the server answers the conversation history
    Given I am on the "Course 1" "course" page logged in as "student1"
    When I request the Jedomi conversation history from the browser
    Then the Jedomi server should accept the last request

  # [Pendiente:fail] The course availability only hides the button; the server answers the history and reads files.
  @MDL-INT-009
  Scenario: In a course where Jedomi is not available the server refuses the history and the file reading
    Given Jedomi is not available in the course "C1"
    And I am on the "Course 1" "course" page logged in as "student1"
    When I request the Jedomi conversation history from the browser
    Then the Jedomi server should refuse the last request
    And I request the Jedomi image reading from the browser
    And the Jedomi server should refuse the last request

  # [Pendiente:fail] With every role hidden the server only refuses the file reading; the history is answered.
  @MDL-INT-010
  Scenario: The server refuses the history and the file reading of a user whose roles are all hidden
    Given the following "role assigns" exist:
      | user     | role          | contextlevel | reference |
      | creator1 | coursecreator | System       |           |
    And the role "coursecreator" is hidden from Jedomi
    And the role "student" is hidden from Jedomi
    And I am on the "Course 1" "course" page logged in as "creator1"
    When I request the Jedomi image reading from the browser
    Then the Jedomi server should refuse the last request
    And I request the Jedomi conversation history from the browser
    And the Jedomi server should refuse the last request

  # [Pendiente:fail] A student whose course role is hidden still sees the panel inside the activities of the course.
  @MDL-INT-010
  Scenario: A student whose role is hidden does not see the panel in the course nor in its activities
    Given the following "activities" exist:
      | activity | name    | course | idnumber |
      | page     | Page 1  | C1     | page1    |
    And the role "student" is hidden from Jedomi
    When I am on the "Course 1" "course" page logged in as "student1"
    Then "#jedomi-trigger" "css_element" should not exist
    And I am on the "Page 1" "page activity" page
    And "#jedomi-trigger" "css_element" should not exist

  # [Pendiente:fail] The panel is hidden from guests, but the server hands out chat permissions to the guest account,
  # accepts its queries and answers its history.
  @MDL-INT-092
  Scenario: A guest session gets no chat permissions and its queries and history are refused
    Given I log in as "guest"
    And I am on site homepage
    When I request the Jedomi panel configuration from the browser
    Then the Jedomi server should refuse the last request
    And I request the Jedomi chat query from the browser
    And the Jedomi server should refuse the last request
    And I request the Jedomi conversation history from the browser
    And the Jedomi server should refuse the last request

  # [Pendiente:fail] A request without a session must get the same refusal as a guest.
  @MDL-INT-092
  Scenario: A request without a session is refused
    Given I am on site homepage
    When I request the Jedomi chat query from the browser
    Then the Jedomi server should refuse the last request
    And I request the Jedomi conversation history from the browser
    And the Jedomi server should refuse the last request
