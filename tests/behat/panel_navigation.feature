@local @local_jedomi @javascript
Feature: Opening, closing, keyboard and accessibility of the Jedomi panel
  In order to reach the assistant from any page without losing my place
  As a Moodle user
  I need the Jedomi panel to open and close in every expected way, keep its state while navigating and be accessible

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

  @MDL-E2E-003
  Scenario: The panel opens with the button and the shortcut and closes with the X, Escape and the shortcut
    Given I log in as "student1"
    And I am on homepage
    When I click on "#jedomi-trigger" "css_element"
    Then "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-open')]" "xpath_element" should exist
    And I click on "#jedomi-close" "css_element"
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-closed')]" "xpath_element" should exist
    And I press the ctrl+shift j key
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-open')]" "xpath_element" should exist
    And I press the escape key
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-closed')]" "xpath_element" should exist
    And I press the ctrl+shift j key
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-open')]" "xpath_element" should exist
    And I press the ctrl+shift j key
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-closed')]" "xpath_element" should exist

  @MDL-E2E-003
  Scenario: The open panel stays open after navigating to another page
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    When I am on "Course 1" course homepage
    Then "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-open')]" "xpath_element" should exist

  @MDL-E2E-003
  Scenario: The new conversation shortcut opens the panel with a new conversation
    Given I log in as "student1"
    And I am on homepage
    When I press the ctrl+shift n key
    Then "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-open')]" "xpath_element" should exist
    And I should see "New conversation started. How can I help you?" in the "#jedomi-messages" "css_element"

  @MDL-E2E-003
  Scenario: The panel offers half screen, full screen and a full screen view on mobile
    Given I log in as "student1"
    And I change viewport size to "1366x768"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And I remember the width of the Jedomi panel
    When I click on "#jedomi-expand-half" "css_element"
    Then the Jedomi panel should be wider than the remembered width
    And I click on "#jedomi-expand-full" "css_element"
    And the Jedomi panel should fill the window width
    And I change viewport size to "mobile"
    And I reload the page
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-open')]" "xpath_element" should exist
    And the Jedomi panel should fill the window width

  # [Pendiente:skip] The closed panel is reachable with Tab and by screen readers, does not keep nor give back the
  # focus, answers are not announced, several buttons only have a tooltip and the backdrop never appears.
  # This scenario asserts the expected behaviour and fails until fixed; see the skipped PHPUnit companion.
  @MDL-E2E-004
  Scenario: The panel is hidden from the keyboard while closed, keeps the focus while open and announces answers
    Given I log in as "student1"
    When I am on homepage
    Then "//div[@id='jedomi-sidebar' and (@aria-hidden='true' or @inert)]" "xpath_element" should exist
    And I click on "#jedomi-trigger" "css_element"
    And "//div[@id='jedomi-sidebar' and not(@aria-hidden='true') and not(@inert)]" "xpath_element" should exist
    And the focused element is "#jedomi-input" "css_element"
    And "//div[@id='jedomi-messages' and (@aria-live='polite' or @role='log')]" "xpath_element" should exist
    And "//div[@id='jedomi-sidebar']//button[not(normalize-space(.)) and not(@aria-label) and not(@aria-labelledby)]" "xpath_element" should not exist
    And I press the escape key
    And the focused element is "#jedomi-trigger" "css_element"

  # [Pendiente:skip] See the scenario above.
  @MDL-E2E-004
  Scenario: On mobile touching outside the panel closes it
    Given I log in as "student1"
    And I change viewport size to "mobile"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    When I click on "#jedomi-overlay" "css_element"
    Then "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-closed')]" "xpath_element" should exist

  # [Pendiente:skip] The shortcuts match browser shortcuts (console and private window) and the new conversation
  # shortcut clears the conversation without confirmation. Asserts the expected behaviour; fails until fixed.
  @MDL-E2E-005
  Scenario: The new conversation shortcut asks for confirmation before clearing a conversation
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi AI answers "Your next task is the essay."
    And I send "What is my next task?" to Jedomi
    And I should see "Your next task is the essay." in the "#jedomi-messages" "css_element"
    When I press the ctrl+shift n key
    Then ".modal-dialog" "css_element" should exist
    And I should see "What is my next task?" in the "#jedomi-messages" "css_element"

  # [Pendiente:skip] See the scenario above: Ctrl+Shift+J opens the browser console and Ctrl+Shift+N a private window.
  @MDL-E2E-005
  Scenario: The panel does not use the shortcuts the browsers reserve
    Given I log in as "student1"
    And I am on homepage
    When I press the ctrl+shift j key
    Then "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-closed')]" "xpath_element" should exist
    And I press the ctrl+shift n key
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-closed')]" "xpath_element" should exist

  # [Pendiente:skip] When the panel configuration cannot be loaded the panel is shown empty and does not work,
  # without a notice. Asserts the expected behaviour; fails until fixed.
  @MDL-E2E-013
  Scenario: The user is told when the panel configuration cannot be loaded
    Given I log in as "student1"
    And I am on homepage
    And the Jedomi panel configuration fails to load
    When I click on "#jedomi-trigger" "css_element"
    Then "//div[@id='jedomi-sidebar']//*[@role='alert' or contains(@class, 'alert')]" "xpath_element" should exist
