@local @local_jedomi @javascript
Feature: Contract of the Telegram webhook
  In order to receive the bot messages reliably and safely
  As the Telegram Bot API
  I need the webhook to refuse unauthenticated requests and acknowledge valid ones at once

  Background:
    Given the Jedomi Telegram bot is configured
    And I am on site homepage

  @MDL-CTR-008
  Scenario: A request without the right secret is refused
    When I send a POST request to the Jedomi Telegram webhook with a wrong secret and a valid update
    Then the Jedomi Telegram webhook should answer with status "403"

  @MDL-CTR-008
  Scenario: Only POST requests with a valid JSON body are accepted
    When I send a GET request to the Jedomi Telegram webhook with the right secret and a valid update
    Then the Jedomi Telegram webhook should answer with status "405"
    And I send a POST request to the Jedomi Telegram webhook with the right secret and an invalid JSON body
    And the Jedomi Telegram webhook should answer with status "400"

  @MDL-CTR-008
  Scenario: A valid authenticated request is acknowledged with success and the real body length
    When I send a POST request to the Jedomi Telegram webhook with the right secret and a valid update
    Then the Jedomi Telegram webhook should answer with status "200"
    And the Jedomi Telegram webhook answer should declare the real body length
