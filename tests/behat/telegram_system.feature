@local @local_jedomi @javascript
Feature: Conversation with Jedomi through Telegram
  In order to ask Moodle from my phone
  As a linked Telegram user
  I need the bot to answer my messages with real data of the site

  # The updates are posted to the webhook as Telegram does. The answers of the bot go to the Telegram Bot API, which
  # the browser cannot read, so the answer is checked in the conversation the plugin stores for the chat. Photos,
  # documents, voice and long answers sent as files need the Telegram file API and are covered by the PHPUnit tests of
  # the webhook media handling. Needs the real AI service (JEDOMI_BEHAT_LICENSE); skipped otherwise.

  @SYS-E2E-008
  Scenario: A linked administrator asks by Telegram and gets an answer with real data of the site
    Given the Jedomi AI service is available for acceptance tests
    And the following "courses" exist:
      | fullname              | shortname |
      | Jedomi Telegram Lab   | JTL1      |
    And the Jedomi Telegram bot is configured
    And the user "admin" is linked to the Jedomi Telegram chat "783000101"
    And I log in as "admin"
    When the Jedomi Telegram chat "783000101" sends "What is the full name of the course with the short name JTL1?"
    Then the last Jedomi Telegram answer of the chat "783000101" should contain "Jedomi Telegram Lab"
