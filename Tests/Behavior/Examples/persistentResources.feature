@playwright
@fixtures
Feature: Persistent Resources

  In this example we assume there is a content unit JavaScriptWidget which allows the editor to select a
  JavaScript file from the persistent resources. This widget is then loaded and executed.
  Also the JavaScriptWidget generates div-tags with given IDs as anchors for the script.

  Hints for the test setup:

  While developing this feature I had trouble loading my persistent resource. This was due to some split-brain
  behavior due to a duplicate flow configuration. During the execution of the steps the active FLOW_CONTEXT is
  "Testing/Behat". During the fetch of the page and resource it has been "Development/Behat". The persistent
  resource was not found since the storage settings (ie the storage- and the target-path) did not match.

  Scenario: JavaScript widget loads and executes
    Given I have a site for Site Node "website"
    And I have a textual persistent resource "74d819f0-0bf4-44df-ae36-0c7639c1afcc" named "my-widget.js" with the following content:
        """
            document.getElementById('my-container-1').appendChild(document.createTextNode('Hello Container 1!'));
            document.getElementById('my-container-2').appendChild(document.createTextNode('Hello Container 2!'));
        """
    And I have the following nodes:
      | Path                                   | Node Type                | Properties                                                                                                                                                                                        | HiddenInIndex |
      | /sites                                 | unstructured             | []                                                                                                                                                                                                | false         |
      | /sites/website                         | Neos.Neos:Document       | {"uriPathSegment":"website","title":"Website","privacyPage":"b9d32958-9bc0-4502-bdd2-274b54f1777e"}                                                                                               | false         |
      | /sites/website/main/node-2ph9cgxvafhxe | My.Cool:JavaScriptWidget | {"javaScriptSourceFile":{"__flow_object_type": "Neos\\Media\\Domain\\Model\\Document","__identifier": "74d819f0-0bf4-44df-ae36-0c7639c1afcc"},"anchorElementIds":"my-container-1,my-container-2"} | false         |
    When I access the URI path "/"
    Then there should be the text "Hello Container 1!" on the page
    And there should be the text "Hello Container 2!" on the page
