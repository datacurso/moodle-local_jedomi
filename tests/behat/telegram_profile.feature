@local @local_jedomi
Feature: Telegram link page when the bot is not configured
  In order to link my account only when the bot can be used
  As a Moodle user
  I need the Telegram page to be reachable only when the bot is configured, except for administrators

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | manager1 | Mia       | Manager  |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |

  @MDL-UNIT-057 @MDL-INT-061
  Scenario: A user who is not an administrator is sent to the profile while the bot is not configured
    Given I log in as "student1"
    When I visit "/local/jedomi/telegram_profile.php"
    Then the url should match "/user/profile\.php"
    And I should not see "Link Telegram"

  @MDL-UNIT-057 @MDL-INT-061
  Scenario: An administrator sees the not configured state with a link to the settings
    Given I log in as "admin"
    When I visit "/local/jedomi/telegram_profile.php"
    Then I should see "Telegram is not configured (only administrators see this message)"
    And "//a[contains(@href, '/local/jedomi/admin_settings.php?tab=integrations')]" "xpath_element" should exist

  @MDL-INT-061
  Scenario: With the bot configured and an allowed role the profile has the link to the Telegram page
    Given the Jedomi Telegram bot is configured
    And I log in as "student1"
    When I follow "Profile" in the user menu
    And I follow "Link Telegram"
    Then I should see "Not linked"
    And "Generate code" "button" should exist

  @MDL-INT-061
  Scenario: A user whose role is not allowed sees the role notice and cannot link
    Given the Jedomi Telegram bot is configured
    And the role "manager" is blocked for Jedomi Telegram
    And I log in as "manager1"
    When I visit "/local/jedomi/telegram_profile.php"
    Then I should see "Your role does not have access to Jedomi Telegram."
    And "Generate code" "button" should not exist

  @MDL-INT-058 @javascript
  Scenario: Unlinking from the profile asks for confirmation and removes the link
    Given the Jedomi Telegram bot is configured
    And the user "student1" is linked to the Jedomi Telegram chat "781000001"
    And I log in as "student1"
    And I visit "/local/jedomi/telegram_profile.php"
    When I press "Unlink"
    And I click on "Unlink" "button" in the ".modal-dialog" "css_element"
    Then I should see "Unlinked. Send /start to link again."
    And I should see "Not linked"

  # [Pendiente:fail] A manager with the permission to update users can generate the link code of any user.
  @MDL-INT-059
  Scenario: Nobody but the user can generate the link code of an account
    Given the Jedomi Telegram bot is configured
    And I log in as "manager1"
    When I open the Jedomi Telegram page of the user "admin"
    Then "Generate code" "button" should not exist
    And I open the Jedomi Telegram page of the user "student1"
    And "Generate code" "button" should not exist

  @MDL-INT-059
  Scenario: Each user can generate their own link code
    Given the Jedomi Telegram bot is configured
    And I log in as "student1"
    And I visit "/local/jedomi/telegram_profile.php"
    When I press "Generate code"
    Then I should see "Not linked"
    And "Generate code" "button" should exist

  # [Pendiente:fail] Unlinking from the web leaves the conversation and the state of the bot.
  @MDL-INT-060 @javascript
  Scenario: Unlinking from the web removes every data of the bot
    Given the Jedomi Telegram bot is configured
    And the user "student1" is linked to the Jedomi Telegram chat "781000002"
    And I log in as "student1"
    And I visit "/local/jedomi/telegram_profile.php"
    When I press "Unlink"
    And I click on "Unlink" "button" in the ".modal-dialog" "css_element"
    Then I should see "Unlinked. Send /start to link again."
    And the Jedomi Telegram chat "781000002" should have no link and no conversation

  # [Pendiente:fail] A linked user whose role is later blocked cannot unlink from the web.
  @MDL-INT-060 @javascript
  Scenario: A linked user whose role is blocked can still unlink from the web
    Given the Jedomi Telegram bot is configured
    And the user "manager1" is linked to the Jedomi Telegram chat "781000003"
    And the role "manager" is blocked for Jedomi Telegram
    And I log in as "manager1"
    And I visit "/local/jedomi/telegram_profile.php"
    When I press "Unlink"
    And I click on "Unlink" "button" in the ".modal-dialog" "css_element"
    Then the Jedomi Telegram chat "781000003" should have no link and no conversation

  # The answers of the bot go to the Telegram Bot API, a true external boundary that cannot be read from the
  # browser; the link is checked on the page and in the stored link of the chat.
  @MDL-E2E-009 @javascript
  Scenario: A user links Telegram with the code from the profile and unlinks it after confirming
    Given the Jedomi Telegram bot is configured
    And I log in as "student1"
    And I follow "Profile" in the user menu
    And I follow "Link Telegram"
    When I press "Generate code"
    Then "//a[contains(@href, 'https://t.me/jedomi_test_bot?start=')]" "xpath_element" should exist
    And the Jedomi Telegram chat "782000001" sends the link code shown on the page
    And the Jedomi Telegram chat "782000001" should be linked to the user "student1"
    And I reload the page
    And I should see "Connected"
    And I press "Unlink"
    And I click on "Unlink" "button" in the ".modal-dialog" "css_element"
    And I should see "Not linked"
    And the Jedomi Telegram chat "782000001" sends "Hello"
    And the Jedomi Telegram chat "782000001" should not be linked to the user "student1"
