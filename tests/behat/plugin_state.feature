@local @local_jedomi
Feature: Jedomi follows its Enabled switch in the Datacurso AI provider
  In order to stop every Jedomi channel at once
  As an administrator
  I need Jedomi pages and the side panel to answer the provider message while Jedomi is disabled

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |

  @MDL-INT-002
  Scenario: With Jedomi disabled a user gets the not enabled message of the provider on the Jedomi pages
    Given the following config values are set as admin:
      | plugin_enabled_local_jedomi | 0 | aiprovider_datacurso |
    And I log in as "student1"
    When I visit "/local/jedomi/telegram_profile.php"
    Then I should see "Jedomi is not enabled on this site. Please contact your site administrator."
    And "//a[contains(@href, 'tab=pluginslist')]" "xpath_element" should not exist

  @MDL-INT-002
  Scenario: With Jedomi disabled an administrator also gets the link to the plugins list of the provider
    Given the following config values are set as admin:
      | plugin_enabled_local_jedomi | 0 | aiprovider_datacurso |
    And I log in as "admin"
    When I visit "/local/jedomi/telegram_profile.php"
    Then I should see "Jedomi is disabled in the Datacurso AI provider."
    And "//a[contains(@href, '/ai/provider/datacurso/admin/report_sections.php') and contains(@href, 'tab=pluginslist')]" "xpath_element" should exist

  @MDL-INT-002 @javascript
  Scenario: With Jedomi disabled the side panel is not injected and it comes back when Jedomi is enabled again
    Given the following config values are set as admin:
      | plugin_enabled_local_jedomi | 0 | aiprovider_datacurso |
    And I log in as "student1"
    When I am on homepage
    Then "#jedomi-trigger" "css_element" should not exist
    And the following config values are set as admin:
      | plugin_enabled_local_jedomi | 1 | aiprovider_datacurso |
    And I reload the page
    And "#jedomi-trigger" "css_element" should exist
