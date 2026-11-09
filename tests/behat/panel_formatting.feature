@local @local_jedomi @javascript
Feature: Formatting, links and tables of the Jedomi answers
  In order to read and reuse the answers safely
  As a Moodle user
  I need the answers formatted, with only safe links, no code from the AI run in the page and tables exported as CSV

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And I log in as "student1"
    And I am on homepage
    And I click on "#jedomi-trigger" "css_element"

  @MDL-UNIT-041
  Scenario: Bold, italics, code, links and lists are shown with their format
    Given the Jedomi AI answers:
      """
      **Bold text** and *plain* with _italic text_ and `inline code`.

      - First item
      - Second item

      See [Moodle docs](https://docs.moodle.org/) for more.
      """
    When I send "Format test" to Jedomi
    Then "//div[@id='jedomi-messages']//strong[normalize-space(.)='Bold text']" "xpath_element" should exist
    And "//div[@id='jedomi-messages']//em[normalize-space(.)='italic text']" "xpath_element" should exist
    And "//div[@id='jedomi-messages']//code[normalize-space(.)='inline code']" "xpath_element" should exist
    And "//div[@id='jedomi-messages']//ul/li[normalize-space(.)='First item']" "xpath_element" should exist
    And "//div[@id='jedomi-messages']//ul/li[normalize-space(.)='Second item']" "xpath_element" should exist
    And "//div[@id='jedomi-messages']//a[@href='https://docs.moodle.org/']" "xpath_element" should exist

  @MDL-UNIT-041
  Scenario: Only web and site links are links, they open in the same tab and Moodle links show an icon and a type label
    Given the Jedomi AI answers:
      """
      Open [My course](/course/view.php?id=1), [Docs](https://docs.moodle.org/), [Script](javascript:window.jedomiXss=1), [Data](data:text/html,hello), [Mail](mailto:someone@example.com) and [Protocol relative](//evil.example.com/x).
      """
    When I send "Links test" to Jedomi
    Then "//div[@id='jedomi-messages']//a[contains(@href, '/course/view.php?id=1') and contains(@class, 'jedomi-moodle-link')]//span[contains(@class, 'jedomi-link-badge') and normalize-space(.) != '']" "xpath_element" should exist
    And "//div[@id='jedomi-messages']//a[@href='https://docs.moodle.org/']" "xpath_element" should exist
    And "//div[@id='jedomi-messages']//a[starts-with(@href, 'javascript') or starts-with(@href, 'data') or starts-with(@href, 'mailto') or starts-with(@href, '//')]" "xpath_element" should not exist
    And I should see "Script" in the "#jedomi-messages" "css_element"
    And I should see "Data" in the "#jedomi-messages" "css_element"
    And I should see "Mail" in the "#jedomi-messages" "css_element"
    And "//div[@id='jedomi-messages']//a[@target and @target != '_self']" "xpath_element" should not exist

  @MDL-UNIT-041
  Scenario: HTML in an answer is shown as text and never runs
    Given the Jedomi AI answers:
      """
      Result: <img src="x" onerror="window.jedomiXss=1"> <script>window.jedomiXss=1</script> <b>raw</b> <a href="javascript:window.jedomiXss=1">click</a>
      """
    When I send "HTML test" to Jedomi
    Then I should see "<b>raw</b>" in the "#jedomi-messages" "css_element"
    And "//div[@id='jedomi-messages']//img" "xpath_element" should not exist
    And "//div[@id='jedomi-messages']//script" "xpath_element" should not exist
    And "//div[@id='jedomi-messages']//b[normalize-space(.)='raw']" "xpath_element" should not exist
    And "//div[@id='jedomi-messages']//a[starts-with(@href, 'javascript')]" "xpath_element" should not exist
    And no script of the Jedomi answer should have run

  @MDL-UNIT-043
  Scenario: Each table of an answer is downloaded as its own CSV with the header, every row and escaped values
    Given the Jedomi AI answers:
      """
      Here are the users:

      | Name | Note |
      |---|---|
      | Smith, John | He said "hi" |
      | Ana | ok |

      And the courses:

      | Course | Users |
      |---|---|
      | Course 1 | 3 |
      """
    And I send "Tables test" to Jedomi
    And "(//div[@id='jedomi-messages']//button[@data-csv-download])[2]" "xpath_element" should exist
    And "(//div[@id='jedomi-messages']//button[@data-csv-download])[3]" "xpath_element" should not exist
    When I click on "(//div[@id='jedomi-messages']//button[@data-csv-download])[1]" "xpath_element"
    Then the downloaded Jedomi CSV should contain the line:
      """
      "Name","Note"
      """
    And the downloaded Jedomi CSV should contain the line:
      """
      "Smith, John","He said ""hi"""
      """
    And the downloaded Jedomi CSV should contain the line:
      """
      "Ana","ok"
      """
    And the downloaded Jedomi CSV should not contain "Course 1"
    And I click on "(//div[@id='jedomi-messages']//button[@data-csv-download])[2]" "xpath_element"
    And the downloaded Jedomi CSV should contain the line:
      """
      "Course","Users"
      """
    And the downloaded Jedomi CSV should contain the line:
      """
      "Course 1","3"
      """
    And the downloaded Jedomi CSV should not contain "Smith"

  # [Pendiente:fail] The CSV does not neutralise values starting with =, +, - or @, which a spreadsheet runs as
  # formulas, and it copies the icon and the type label of Moodle links into the cells. Must fail until fixed.
  @MDL-UNIT-043
  Scenario: Values that start with a formula character are exported as text and link cells export only their text
    Given the Jedomi AI answers:
      """
      | User | Value |
      |---|---|
      | =HYPERLINK("http://example.com","x") | +1 |
      | -2 | @SUM(A1) |
      | [Course 1](/course/view.php?id=1) | link |
      """
    And I send "Formula test" to Jedomi
    When I click on "(//div[@id='jedomi-messages']//button[@data-csv-download])[1]" "xpath_element"
    Then no cell of the downloaded Jedomi CSV should start with a formula character
    And the downloaded Jedomi CSV should contain the line:
      """
      "Course 1","link"
      """
