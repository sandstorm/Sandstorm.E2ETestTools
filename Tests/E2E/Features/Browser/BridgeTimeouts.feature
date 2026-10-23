# The playwright-bridge: a step fails with Playwright's message, not with a bare timeout of the request to the bridge.
# Package-internal (BridgeTimeoutTrait) - not an example to copy. Takes about 20 seconds.
@playwright
Feature: Bridge timeouts

  Scenario: a context a script opens itself waits 15 seconds, below the 30 seconds of a request
    Then a context a script opens itself should time out after 15000 ms

  Scenario: a request to the bridge that takes too long explains why
    Then a script waiting 3 s with a request limit of 1 s should fail with "The playwright-bridge didn't answer within 1 seconds - a wait in the script took longer"
