@local @local_jedomi
Feature: Jedomi settings in the Datacurso AI provider
  In order to choose how Jedomi uses the AI service
  As an administrator
  I need to save or cancel the Jedomi settings on its page in the provider

  @MDL-INT-005
  Scenario: Saving the Jedomi settings goes back to the plugins list with the saved notice and keeps the values
    Given I log in as "admin"
    And I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    When I set the field "Max tokens" to "4096"
    And I press "Save changes"
    Then I should see "The settings of Jedomi have been saved."
    And the url should match "/ai/provider/datacurso/admin/report_sections\.php"
    And I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    And the field "Max tokens" matches value "4096"

  @MDL-INT-005
  Scenario: Cancelling goes back without saving
    Given the following config values are set as admin:
      | max_tokens | 2048 | local_jedomi |
    And I log in as "admin"
    And I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    When I set the field "Max tokens" to "9000"
    And I press "Cancel"
    Then the url should match "/ai/provider/datacurso/admin/report_sections\.php"
    And I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    And the field "Max tokens" matches value "2048"

  @MDL-E2E-001 @javascript
  Scenario: The administrator configures Jedomi in the provider from the General tab and the values are kept
    Given I log in as "admin"
    And I visit "/local/jedomi/admin_settings.php"
    And I follow "Open the Jedomi settings in the Datacurso AI provider"
    When I set the following fields to these values:
      | Chat model               | DeepSeek V4.1 Flash |
      | Enable audio processing  | 1                   |
      | Max tokens               | 8192                |
    And I press "Save changes"
    Then I should see "The settings of Jedomi have been saved."
    And the url should match "/ai/provider/datacurso/admin/report_sections\.php"
    And I visit "/local/jedomi/admin_settings.php"
    And I follow "Open the Jedomi settings in the Datacurso AI provider"
    And the following fields match these values:
      | Chat model               | DeepSeek V4.1 Flash    |
      | Enable audio processing  | 1                      |
      | Audio model              | Whisper large-v3 turbo |
      | Max tokens               | 8192                   |

  Scenario: The AI service is one select with the Datacurso service chosen by default
    Given I log in as "admin"
    When I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    Then the field "AI service" matches value "Datacurso service"
    And I should not see "Use Datacurso service"
    And I should not see "Use custom service"

  Scenario: The custom service cannot be saved without verifying its connection
    Given I log in as "admin"
    And I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    When I set the following fields to these values:
      | AI service  | Custom service          |
      | Service URL | https://api.example.com |
      | API key     | sk-behat                |
    And I press "Save changes"
    Then I should see "Verify the connection with this address and key before saving."

  Scenario: A custom service that fails to save keeps the Datacurso service and its values
    Given the following config values are set as admin:
      | max_tokens | 2048 | local_jedomi |
    And I log in as "admin"
    And I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    When I set the following fields to these values:
      | AI service | Custom service |
      | Max tokens | 9000           |
    And I press "Save changes"
    Then I should see "Verify the connection with this address and key before saving."
    And I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    And the field "AI service" matches value "Datacurso service"
    And the field "Max tokens" matches value "2048"

  @javascript
  Scenario: Each option of the AI service select shows only its own fields
    Given I log in as "admin"
    And I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    And I should see "Chat model"
    And I should not see "Service URL"
    When I set the field "AI service" to "Custom service"
    Then I should see "Service URL"
    And I should see "Allow HTTP for local models"
    And "Verify connection" "button" should be visible
    And I should not see "Chat model"
    # The model fields of the custom service appear only once the connection is verified.
    And I should not see "Chat model (custom service)"
    And I set the field "AI service" to "Datacurso service"
    And I should see "Chat model"
    And I should not see "Service URL"

  @javascript
  Scenario: The verification of the custom service explains why an address is refused
    Given I log in as "admin"
    And I visit "/ai/provider/datacurso/admin/plugin_settings.php?component=local_jedomi"
    And I set the field "AI service" to "Custom service"
    When I set the field "Service URL" to "http://203.0.113.10"
    And I press "Verify connection"
    Then I should see "Plain HTTP is only allowed for a local model with \"Allow HTTP for local models\" on. Use HTTPS."
    And I should not see "Chat model (custom service)"
