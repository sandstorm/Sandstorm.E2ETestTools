# Shaping E2E Neos Package - export and import nodes

### Current Situation
- node to test and all of its parents have to be added to test file
- difficult to see through on first sight
- more than just the node one wants to test is in file
- difficult to editing node config


## Shape Up

### Structure
- 1. Problem
  - The raw idea, a use case, or something we’ve seen that motivates us to work on this
- 2. Appetite
  - How much time we want to spend and how that constrains the solution
- 3. Solution
  - The core elements we came up with, presented in a form that’s easy for people to immediately understand
- 4. Rabbit Holes
  - Details about the solution worth calling out to avoid problems
- 5. No Gos
  - Anything specifically excluded from the concept: functionality or use cases we intentionally aren’t covering to fit the appetite or make the problem tractable

https://basecamp.com/shapeup/1.5-chapter-06#ingredient-1-problem


### 1. Problem
- 1st problem:
  - test fixtures
  - difficult to test nodes because of:
    - pain to create new fixtures
      - currently completely inline with config etc
- 2nd problem:
  - understand tests
  - difficult to understand failing tests
    - lot of boilerplate code because of inline fixtures
    - difficult to correlate between assertions and fixtures
      - possible issue: ratio between important fixtures and simple setup fixtures
        - important fixtures: needed for assertions
        - setup fixtures: prevent neos from crashing / renders page
          - mostly parents or references

### 2. Appetite
- budget: 10k€
- time: until 06.03.2026
- high value on priorities
- small, focused and finished milestones/goals
- no unfinished merge requests after Praxisphase
- problems:
  - easier fixture setup
  - easily understandable tests


### 3. Solution
- download button in Neos Backend
  - download document / node as YAML
  - create new editor
    - add to Neos.Neos:Node -> all Nodes exportable
  - redirects to e.g. website.de/neos/export-node/{uuid}
  - Controller gets node through uuid/identifier
    - converts to yaml
    - maybe use already existing export (to xml) method
- YAML structured to be readable and editable
  - (possibly leave out unnecessary data)
    - e.g. IDs, Creation Timestamps etc
      - still allow import -> generate dummy data on import
  - ! leave out boilerplate code from important one
    - hybrid fixture setup?
    - yaml support placeholders?
      - insertable values form within the test
      - optional?
  - beware of node references
- use YAML to include node(s) in e2e tests to test them

- possible solutions:
  - delegated and/or hybrid fixture setup
  - "I have following nodes from file 123.yaml"
  - initial breakthrough:
    - export node with all its parents -> import
    - initial breakthrough does not include further readability/editability adjustments
  - possible readability/editability adjustments:
    - 1) placeholders:
      - node tree hierarchy has designated placeholder properties which can be overwritten/replaced
      - boilerplate code relocated in yaml file while for assertions important properties are in test file
        - either by:
          - manually mark them in yaml file, e.g. with a # before the property name
            - placeholder syntax could break yaml editor syntax
            - dev has to manually open yaml file and find node they want to test and edit
          - "export wizard"
            - shown when export node button is clicked
            - dev can choose what properties they want to be placeholders
            ```I have following nodes from file: 123.yaml with following values:
               | placeholder | value |
               | ----------- | ----- |
               |             |       |
           ```
      - possible yaml:
          ```yaml
              - yaml: 
                   - placeholders:
                       - identifier:
                           - disabled:
                           - title:
                       - node2:
                           - disabled:
                           - title:
                   - nodes:
                       - parent1:
                           - parent2:
           ```

    - 2) fusion-approach: everything overwrittable from within test file
      - every property from node tree can be overwritten
          ```I have following nodes from file: 123.yaml with following values:
          | node-identifier | property | value |
          | --------------- | -------- | ----- |
          |                 |          |       |
          ```
      - How to make finding node identifier easier for dev?
          ```yaml
              - yaml:
                  - nodeSummary:
                      - node1-identifier:
                          - nodeType:
                      - node2-identifier:
                          - nodeType:
                  - nodes:
                      - parent1:
                          - parent2:
                              - node1-identifier:
                                  - node2-identifier:
                                      - properties: 
                                          - title: myTitle
                                          - text: My Text
                                      - dimension:
                                          - language: de
          ```



### 4. Rabbit Holes
- binary assets: placeholder
- parent nodes:
  - need to be downloaded as well
  - YAML structure focus on important node
    - would be solved by hybrid fixture setup
  - problem:
    - need more than just parents to render or to test
    - solutions:
      - export document, document's parents and all content nodes connected to document
        - document node, parents and siblings
      - 2nd button: export node <-> export closest parent document with all content node's siblings
  - breakthrough:
    - export node with all parents
- if placeholders in yaml:
  - warning! placeholder syntax could break yaml editor syntax
- YAML structure:
  - standard hierarchy
      ```yaml
      nodes:
          id:
              [type]:
                  [properties]
              children:
                - 
              dimension:
                language: de

      nodes:
        # ....
        1234-1234-1234-1234-1234:
          "Neos.Neos:Text":
            text: Mein Text
          children:
            -
            -
            - ```
  - see 3. Solution
- project structure
  - either store node YAMLs:
    - in test feature directory
    - in separate directory on same level as feature directory
      - use 1 node yaml in multiple feature tests

### 5. No Gos
- change initial test setup / setup of package
- No fancy UI for selecting nodes or dynamic values (not enough appetite)
- if dynamic values:
  - do not bloat yaml generator scope by implementing dynamic values on low level framework layer
  - implement in separate, more high level layer e.g. only a thing the step knows + manual work around like introducing placeholders


## Solution Approaches:
- Focus:
  - functionality, import and export nodes -> use in tests to render page
  - understandable tests
    - less boilerplate code in test file
  - (editability)

## How to decide:
- ask team
- create "survey" questions
- create simple prototypes
- then ask neos developers
  - what they value most
  - what approach they prefer

