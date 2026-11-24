@local @local_jedomi
Feature: Personalization of the Jedomi panel
  In order to present the assistant with the identity of my institution
  As an administrator
  I need to set the avatar, the position, the name, the welcome message and the site instructions of Jedomi

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

  Scenario: The Personalization tab is the last tab and keeps the saved values
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php"
    And I should see "Personalization"
    When I visit "/local/jedomi/admin_settings.php?tab=personalization"
    And I click on "//input[@name='avatar' and @value='03']" "xpath_element"
    And I set the following fields to these values:
      | Button corner                | Top right                  |
      | Horizontal distance (px)     | 40                         |
      | Vertical distance (px)       | 30                         |
      | Panel opens from             | Right side                 |
      | Assistant name               | Robo                       |
      | Welcome message              | Hello {firstname}, I am {assistantname}. |
      | Site instructions for the AI | Answer in a friendly tone. |
    And I press "Save changes"
    Then I should see "Changes saved"
    And the url should match "tab=personalization"
    And the field "Assistant name" matches value "Robo"
    And the field "Button corner" matches value "Top right"
    And the field "Horizontal distance (px)" matches value "40"
    And the field "Site instructions for the AI" matches value "Answer in a friendly tone."
    And "//input[@name='avatar' and @value='03' and @checked]" "xpath_element" should exist

  Scenario: A distance outside the allowed range is not saved
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=personalization"
    When I set the field "Horizontal distance (px)" to "900"
    And I press "Save changes"
    Then I should see "Enter a whole number of pixels between 0 and 500."

  @javascript
  Scenario: The panel shows the configured name, avatar, position and welcome message
    Given the following config values are set as admin:
      | assistant_name  | Robo                                      | local_jedomi |
      | avatar          | 03                                        | local_jedomi |
      | avatar_position | {"corner":"top-right","x":40,"y":30,"side":"right"} | local_jedomi |
      | welcome_message | Hello {firstname}, I am {assistantname}. | local_jedomi |
    And I log in as "student1"
    And I am on homepage
    Then "//button[@id='jedomi-trigger' and contains(@class, 'jedomi-fab-top-right') and @aria-label='Open Robo']" "xpath_element" should exist
    And "//button[@id='jedomi-trigger']/img[contains(@src, '/local/jedomi/pix/avatars/avatar_03.png') and @alt='Robo']" "xpath_element" should exist
    And I click on "#jedomi-trigger" "css_element"
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-side-right') and contains(@class, 'jedomi-open') and @aria-label='Robo']" "xpath_element" should exist
    And I should see "Hello Sam, I am Robo." in the "#jedomi-messages" "css_element"
    And I should see "Robo" in the ".jedomi-header" "css_element"
