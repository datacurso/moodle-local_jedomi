@local @local_jedomi @javascript
Feature: Voice conversation with Jedomi in the web chat
  In order to talk to Jedomi without typing
  As a Moodle user
  I need to speak to the panel, hear the answers in a few pieces and end the conversation in any way

  # The microphone, the transcription and the reading aloud are true boundaries (device and AI service): the browser
  # gets a fake microphone with a scripted level, the transcriptions answer queued texts and each sentence read aloud
  # gets a short silent audio, so the real voice code of the panel runs. The chat answers are stubbed as in the other
  # features; the actions that change data are held and resolved by the real server. The server side of the reading
  # aloud is covered by speech_service_test and the AI instructions of the voice mode by system_message_test.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
    And the following config values are set as admin:
      | audio_enabled | 1 | local_jedomi |
      | voice_enabled | 1 | local_jedomi |

  @MDL-UNIT-111
  Scenario: Without the microphone the voice view is not opened and the microphone notice is shown
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that denies access
    When I click on "#jedomi-voice-mode-btn" "css_element"
    Then I should see "Could not access the microphone. Please check your browser permissions." in the "#jedomi-messages" "css_element"
    And the Jedomi voice conversation should be closed

  @MDL-UNIT-111 @MDL-UNIT-113 @MDL-UNIT-114 @SYS-E2E-012
  Scenario: The user speaks, Jedomi thinks, reads the answer sentence by sentence and listens again
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And Jedomi takes "2" seconds to read each sentence aloud
    And the Jedomi transcription of what I say is "What is due this week?"
    And the Jedomi AI works on "Your essay is due on Friday. Nothing else is due this week." until released
    When I click on "#jedomi-voice-mode-btn" "css_element"
    Then the Jedomi voice conversation should be open
    And the Jedomi voice conversation should be listening
    And ".jedomi-voice-mode-orb" "css_element" should be visible
    And "[data-action='local_jedomi/sidebar/voice-mode-exit']" "css_element" should be visible
    And I speak to Jedomi for "800" milliseconds
    And the Jedomi voice conversation should be thinking
    And I should see "🎤 What is due this week?" in the "#jedomi-messages" "css_element"
    And "1" Jedomi transcription should have been requested
    And the last Jedomi request should include the message "What is due this week?"
    And the Jedomi AI finishes its answer
    And the Jedomi voice conversation should be speaking
    And Jedomi should have read aloud "Your essay is due on Friday."
    And I should see "Your essay is due on Friday." in the "#jedomi-messages" "css_element"
    And Jedomi should have read aloud "Nothing else is due this week."
    And the Jedomi voice conversation should be listening
    And I should see "Your essay is due on Friday. Nothing else is due this week." in the "#jedomi-messages" "css_element"
    And "(//div[@id='jedomi-messages']//button[@data-action='local_jedomi/sidebar/copy'])[last()]" "xpath_element" should exist
    And "(//div[@id='jedomi-messages']//button[@data-action='local_jedomi/sidebar/good'])[last()]" "xpath_element" should exist
    And "(//div[@id='jedomi-messages']//button[@data-action='local_jedomi/sidebar/bad'])[last()]" "xpath_element" should exist

  @MDL-UNIT-113
  Scenario: Sounds shorter than the minimum are ignored and the end of the sentence is detected after the silence
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi AI answers "OK."
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And the Jedomi voice conversation should be listening
    When I speak to Jedomi for "150" milliseconds
    And I wait "2" seconds
    Then "0" Jedomi transcriptions should have been requested
    And the Jedomi voice conversation should be listening
    And I speak to Jedomi for "600" milliseconds
    And the Jedomi voice conversation should be listening
    And the Jedomi voice conversation should be thinking
    And "1" Jedomi transcription should have been requested

  @MDL-UNIT-122
  Scenario: A short answer of two sentences is read aloud with a single request
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And Jedomi takes "2" seconds to read each sentence aloud
    And the Jedomi AI answers "Your essay is due on Friday and the quiz opens on Monday morning. Nothing else is due this week."
    And I click on "#jedomi-voice-mode-btn" "css_element"
    When I speak to Jedomi for "800" milliseconds
    Then the Jedomi voice conversation should be speaking
    And Jedomi should have read aloud "Your essay is due on Friday and the quiz opens on Monday morning. Nothing else is due this week."
    And the Jedomi voice conversation should be listening
    And Jedomi should have sent "1" text to be read aloud

  @MDL-UNIT-122
  Scenario: The first part groups sentences up to a minimum and the next sentences are read aloud in one longer piece
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And Jedomi takes "2" seconds to read each sentence aloud
    And the Jedomi AI answers "Hello Sam. Your essay is due on Friday. The quiz opens on Monday. The forum closes on Tuesday. The workshop starts on Wednesday. Nothing else is due this week."
    And I click on "#jedomi-voice-mode-btn" "css_element"
    When I speak to Jedomi for "800" milliseconds
    Then the Jedomi voice conversation should be speaking
    And the first text Jedomi read aloud should be at most "160" characters long
    And Jedomi should have read aloud "Hello Sam. Your essay is due on Friday. The quiz opens on Monday. The forum closes on Tuesday."
    And Jedomi should have read aloud "The workshop starts on Wednesday. Nothing else is due this week."
    And the Jedomi voice conversation should be listening
    And Jedomi should have sent "2" texts to be read aloud
    And I should see "Hello Sam. Your essay is due on Friday. The quiz opens on Monday." in the "#jedomi-messages" "css_element"

  @MDL-UNIT-114
  Scenario: Only the speakable text is read aloud, a long first sentence is cut, and the full answer stays in the chat
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi AI answers:
      """
      **Great work**, Sam: the average of the course is 15.38 out of 20, which is above the class average this term and keeps you among the best students of the group this year. 📊

      | Name | Grade |
      |---|---|
      | Sam | 15.38 |

      ```
      SELECT finalgrade FROM mdl_grade_grades
      ```

      See [the report](https://example.com/report) or https://example.com/raw for details.
      """
    And I click on "#jedomi-voice-mode-btn" "css_element"
    When I speak to Jedomi for "800" milliseconds
    Then Jedomi should have read aloud "See the report or for details."
    And the first text Jedomi read aloud should be at most "160" characters long
    And Jedomi should have read aloud "Great work, Sam: the average of the course is 15.38 out of 20"
    And Jedomi should have read aloud "this year."
    And Jedomi should not have read aloud "Grade"
    And Jedomi should not have read aloud "SELECT"
    And Jedomi should not have read aloud "https"
    And Jedomi should not have read aloud "📊"
    And Jedomi should not have read aloud "**"
    And the Jedomi voice conversation should be listening
    And "//div[@id='jedomi-messages']//table" "xpath_element" should exist
    And "//div[@id='jedomi-messages']//a[contains(@href, 'example.com/report')]" "xpath_element" should exist
    And "(//div[@id='jedomi-messages']//button[@data-action='local_jedomi/sidebar/copy'])[last()]" "xpath_element" should exist

  @MDL-UNIT-114
  Scenario: A one sentence answer without punctuation is read and an answer without speakable text is only shown
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi AI answers "Your essay is due on Friday"
    And I click on "#jedomi-voice-mode-btn" "css_element"
    When I speak to Jedomi for "800" milliseconds
    Then Jedomi should have read aloud "Your essay is due on Friday"
    And the Jedomi voice conversation should be listening
    And the Jedomi AI answers:
      """
      ```
      SELECT id FROM mdl_course
      ```
      """
    And I speak to Jedomi for "800" milliseconds
    And I should see "SELECT id FROM mdl_course" in the "#jedomi-messages" "css_element"
    And Jedomi should not have read aloud "SELECT"
    And the Jedomi voice conversation should be listening

  @MDL-UNIT-115 @SYS-E2E-012
  Scenario: A reading that fails is told once per answer and the text stays in the chat
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And reading aloud fails for Jedomi
    And the Jedomi AI answers "First sentence. Second sentence. Third sentence."
    And I click on "#jedomi-voice-mode-btn" "css_element"
    When I speak to Jedomi for "800" milliseconds
    Then I should see "First sentence. Second sentence. Third sentence." in the "#jedomi-messages" "css_element"
    And I should see "The answer could not be read aloud; it is shown in the chat." "1" time in the Jedomi chat
    And the Jedomi voice conversation should be listening

  @MDL-UNIT-115
  Scenario: A transcription that fails shows its reason and Jedomi listens again
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi transcription fails with the message "You have no credits left."
    And I click on "#jedomi-voice-mode-btn" "css_element"
    When I speak to Jedomi for "800" milliseconds
    Then I should see "You have no credits left." in the "#jedomi-messages" "css_element"
    And the Jedomi voice conversation should be listening

  @MDL-UNIT-112 @SYS-E2E-012
  Scenario: The voice conversation ends with its button and when the panel is closed, without leaving the microphone open
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And Jedomi takes "5" seconds to read each sentence aloud
    And the Jedomi AI answers "This is a long answer that is still being read. It has a second sentence."
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And I speak to Jedomi for "800" milliseconds
    And the Jedomi voice conversation should be speaking
    When I click on "[data-action='local_jedomi/sidebar/voice-mode-exit']" "css_element"
    Then the Jedomi voice conversation should be closed
    And the Jedomi microphone should be released and no answer should be playing
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And the Jedomi voice conversation should be listening
    And I click on "#jedomi-close" "css_element"
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-closed')]" "xpath_element" should exist
    And the Jedomi microphone should be released and no answer should be playing
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi voice conversation should be closed

  @MDL-UNIT-112 @SYS-E2E-012
  Scenario: The first Escape only ends the voice conversation and the second one closes the panel
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And the Jedomi voice conversation should be listening
    When I press the escape key
    Then the Jedomi voice conversation should be closed
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-open')]" "xpath_element" should exist
    And the Jedomi microphone should be released and no answer should be playing
    And I press the escape key
    And "//div[@id='jedomi-sidebar' and contains(@class, 'jedomi-closed')]" "xpath_element" should exist

  @MDL-UNIT-112 @SYS-E2E-012
  Scenario: The voice conversation ends after 60 seconds listening without hearing a voice, and not before
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And the Jedomi voice conversation should be listening
    When I wait "57" seconds
    Then the Jedomi voice conversation should be open
    And I wait "5" seconds
    And the Jedomi voice conversation should be closed
    And the Jedomi microphone should be released and no answer should be playing

  @SYS-E2E-013
  Scenario: Speaking over Jedomi stops the reading, keeps the full answer and listens again
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And Jedomi takes "5" seconds to read each sentence aloud
    And the Jedomi AI answers "The first sentence takes a while to read. The second sentence is never heard."
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And I speak to Jedomi for "800" milliseconds
    And the Jedomi voice conversation should be speaking
    When I speak to Jedomi for "600" milliseconds
    Then the Jedomi voice conversation should be listening
    And I should see "The first sentence takes a while to read. The second sentence is never heard." in the "#jedomi-messages" "css_element"

  @SYS-E2E-013
  Scenario: Speaking while Jedomi thinks cancels the request
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi transcription of what I say is "Tell me about my week"
    And the Jedomi AI works on "This answer was cancelled." until released
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And I speak to Jedomi for "800" milliseconds
    And I should see "🎤 Tell me about my week" in the "#jedomi-messages" "css_element"
    And the Jedomi voice conversation should be thinking
    When I speak to Jedomi for "600" milliseconds
    Then the Jedomi voice conversation should be listening
    And the Jedomi AI finishes its answer
    And I wait "2" seconds
    And Jedomi should not have read aloud "This answer was cancelled."

  @SYS-E2E-013
  Scenario: An action that changes data waits for the confirmation on screen, and confirming or cancelling listens again
    Given the following Jedomi agents exist:
      | name           |
      | Critical agent |
    And I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi AI answers "I will delete the agent." proposing to run "delete_agent" with:
      """
      {"id": %agent:Critical agent%}
      """
    And I click on "#jedomi-voice-mode-btn" "css_element"
    When I speak to Jedomi for "800" milliseconds
    Then Jedomi should have read aloud "I will delete the agent."
    And Jedomi should have read aloud "Please confirm the action on screen."
    And the Jedomi voice conversation should be waiting for the confirmation
    And "//div[contains(@class, 'jedomi-action-pending--critical')]" "xpath_element" should exist
    And the Jedomi agent "Critical agent" should exist
    And I set the field with xpath "//input[@data-region='local_jedomi/sidebar/confirm-keyword']" to "CONFIRM"
    And I click on "[data-action='local_jedomi/sidebar/confirm-yes']" "css_element"
    And I should see "✅" in the "#jedomi-messages" "css_element"
    And the Jedomi agent "Critical agent" should not exist
    And the Jedomi voice conversation should be listening
    And the Jedomi AI answers "I will create the agent." proposing to run "create_agent" with:
      """
      {"name": "Voice agent", "prompt": "Daily summary", "schedule_cron": "0 8 * * *"}
      """
    And I speak to Jedomi for "800" milliseconds
    And the Jedomi voice conversation should be waiting for the confirmation
    And I click on "(//button[@data-action='local_jedomi/sidebar/confirm-no'])[last()]" "xpath_element"
    And I should see "cancelled" in the "#jedomi-messages" "css_element"
    And the Jedomi agent "Voice agent" should not exist
    And the Jedomi voice conversation should be listening

  # The utterance that meets the expiry is still transcribed (the transcription does not check the chat permissions,
  # pending in MDL-INT-057); the refusal of its answer ends the conversation, so nothing more is transcribed.
  @MDL-INT-111
  Scenario: With the chat permissions expired the voice conversation ends instead of transcribing again
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi chat permissions have expired
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And I speak to Jedomi for "800" milliseconds
    And "1" Jedomi transcription should have been requested
    And the Jedomi voice conversation should be closed
    And the Jedomi microphone should be released and no answer should be playing
    When I speak to Jedomi for "800" milliseconds
    And I wait "3" seconds
    Then "1" Jedomi transcription should have been requested

  # An utterance must be heard above the speech level for at least half a second to be transcribed (and charged).
  @MDL-INT-112
  Scenario: A short background noise is not transcribed as a sentence
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi AI answers "OK."
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And the Jedomi voice conversation should be listening
    When I speak to Jedomi for "400" milliseconds
    And I wait "3" seconds
    Then "0" Jedomi transcriptions should have been requested

  # A failed transcription is also said aloud, and the same notice right after itself is not repeated in the chat.
  @MDL-INT-112
  Scenario: Repeated failed transcriptions do not fill the chat and the failure is said aloud
    Given I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi transcription fails with the message "The audio could not be read."
    And the Jedomi transcription fails with the message "The audio could not be read."
    And I click on "#jedomi-voice-mode-btn" "css_element"
    When I speak to Jedomi for "800" milliseconds
    And Jedomi should have said "The audio could not be read." aloud "1" time
    And the Jedomi voice conversation should be listening
    And I speak to Jedomi for "800" milliseconds
    And "2" Jedomi transcriptions should have been requested
    And the Jedomi voice conversation should be listening
    Then I should see "The audio could not be read." "1" time in the Jedomi chat
    And Jedomi should have said "The audio could not be read." aloud "1" time

  @SYS-E2E-014
  Scenario: Speaking over the confirm on screen notice keeps the wait, and an unresolved wait ends after 60 seconds
    Given the following Jedomi agents exist:
      | name           |
      | Critical agent |
    And I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi AI answers "I will delete the agent." proposing to run "delete_agent" with:
      """
      {"id": %agent:Critical agent%}
      """
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And I speak to Jedomi for "800" milliseconds
    And the Jedomi voice conversation should be waiting for the confirmation
    When I speak to Jedomi for "800" milliseconds
    Then the Jedomi voice conversation should be waiting for the confirmation
    And "1" Jedomi transcription should have been requested
    And "//div[contains(@class, 'jedomi-action-pending--critical')]" "xpath_element" should exist
    And I wait "62" seconds
    And the Jedomi voice conversation should be closed
    And the Jedomi microphone should be released and no answer should be playing
    And the Jedomi agent "Critical agent" should exist

  @SYS-E2E-014
  Scenario: Speaking while Jedomi thinks an answer that already holds an action keeps its confirmation card
    Given the following Jedomi agents exist:
      | name           |
      | Critical agent |
    And I log in as "admin"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"
    And the Jedomi browser has a microphone that grants access
    And the Jedomi AI works on "I will delete the agent." proposing to run "delete_agent" until released with:
      """
      {"id": %agent:Critical agent%}
      """
    And I click on "#jedomi-voice-mode-btn" "css_element"
    And I speak to Jedomi for "800" milliseconds
    And the Jedomi voice conversation should be thinking
    When I speak to Jedomi for "600" milliseconds
    And the Jedomi AI finishes its answer
    Then the Jedomi voice conversation should be waiting for the confirmation
    And "//div[contains(@class, 'jedomi-action-pending--critical')]" "xpath_element" should exist
    And "1" Jedomi transcription should have been requested
    And the Jedomi agent "Critical agent" should exist
