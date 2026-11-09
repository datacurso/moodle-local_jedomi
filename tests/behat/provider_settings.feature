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
      | Chat model               | DeepSeek V4.1 Flash |
      | Enable audio processing  | 1                   |
      | Audio model              | Qwen3-ASR 1.7B      |
      | Max tokens               | 8192                |
