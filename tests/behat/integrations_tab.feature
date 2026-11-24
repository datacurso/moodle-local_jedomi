@local @local_jedomi @javascript
Feature: Telegram configuration in the Integrations tab
  In order to connect the Telegram bot without manual steps in Telegram
  As an administrator
  I need the Integrations tab to switch the bot on, check its token and webhook and save its limits and roles

  @MDL-E2E-006
  Scenario: The integration switch is saved at once, without submitting the form
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=integrations"
    When I click on "Enable Telegram integration" "checkbox"
    And I wait until the page is ready
    And I reload the page
    Then the field "Enable Telegram integration" matches value "1"

  @MDL-E2E-006
  Scenario: The switch alone shows or hides the Telegram settings, with no collapse arrow
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=integrations"
    And "[data-action='local_jedomi/admin/integrations/expand']" "css_element" should not exist
    And I should not see "Telegram limits and response time"
    When I click on "Enable Telegram integration" "checkbox"
    And I wait until the page is ready
    Then I should see "Telegram bot" in the "[data-region='local_jedomi/admin/integrations/body']" "css_element"
    And I should see "Telegram limits and response time"
    And I should see "Telegram access by role"
    And I should not see "Expand all"
    And I click on "Enable Telegram integration" "checkbox"
    And I wait until the page is ready
    And I should not see "Telegram limits and response time"

  @MDL-E2E-006
  Scenario: An invalid token is refused with an error and the bot stays disconnected
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=integrations"
    And I click on "Enable Telegram integration" "checkbox"
    And I wait until the page is ready
    When I set the field "Bot token" to "123456:not-a-real-token"
    And I press "Save changes"
    Then I should see "Invalid bot token. Check the token from @BotFather and try again."
    And I should not see "Changes saved"

  @MDL-E2E-006
  Scenario: The limits and the roles allowed in Telegram are saved with the saved notice
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=integrations"
    And I click on "Enable Telegram integration" "checkbox"
    And I wait until the page is ready
    When I set the following fields to these values:
      | Rate limit                 | 12 |
      | Cooldown                   | 7  |
      | Maximum conversation turns | 30 |
      | Student                    | 0  |
    And I press "Save changes"
    Then I should see "Changes saved"
    And the following fields match these values:
      | Rate limit                 | 12 |
      | Cooldown                   | 7  |
      | Maximum conversation turns | 30 |
      | Student                    | 0  |

  # Needs a real bot (JEDOMI_BEHAT_TELEGRAM_TOKEN) and a site reachable by HTTPS; skipped otherwise.
  @MDL-E2E-006
  Scenario: A valid token connects the bot and the webhook check and the pending updates show their real result
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php?tab=integrations"
    And I click on "Enable Telegram integration" "checkbox"
    And I wait until the page is ready
    When I set the field "Bot token" to the real Jedomi Telegram bot token
    And I press "Save changes"
    Then I should see "Changes saved"
    And I should see "Connected"
    And I should see "@" in the "[data-region='local_jedomi/admin/integrations/telegram']" "css_element"
    And I click on "[data-action='local_jedomi/admin/integrations/check-webhook']" "css_element"
    And I should see "Webhook URL:" in the "[data-region='local_jedomi/admin/integrations/webhook-live']" "css_element"
    And I click on "[data-action='local_jedomi/admin/integrations/drop-pending']" "css_element"
    And I should see "Pending updates dropped successfully." in the "[data-region='local_jedomi/admin/integrations/drop-pending-result']" "css_element"
