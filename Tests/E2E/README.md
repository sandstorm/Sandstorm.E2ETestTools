# E2E suite

The package's own Behat suite. It runs against the test site of the development distribution
(`Tests/SystemUnderTest/DistributionPackages/Sandstorm.E2ETestTools.TestSite`) and doubles as the examples for the steps this
package provides: copy a feature into your site package's `Tests/Behavior/Features/`, replace
`Sandstorm.E2ETestTools.TestSite` with your site package key and adapt NodeTypes, properties and texts.

Run it from the package root (see "Development distribution" in the [README](../../README.md#development-distribution)):

```bash
mise run start
mise run tests:e2e
```

| Feature | Shows |
|---|---|
| [FusionComponent/Button.feature](Features/FusionComponent/Button.feature) | render a Fusion component without nodes, assert HTML |
| [FusionIntegration/Button.feature](Features/FusionIntegration/Button.feature) | render a NodeType integration with a context node (incl. node links) |
| [PageRendering/Homepage.feature](Features/PageRendering/Homepage.feature) | visit a page in the browser, assert status and text, take a screenshot |
| [PageRendering/FusionPage.feature](Features/PageRendering/FusionPage.feature) | render a whole page via Fusion (no request, no browser), assert HTML |
| [Fixtures/FromFile.feature](Features/Fixtures/FromFile.feature) + [homepage.yaml](Features/Fixtures/homepage.yaml) | create nodes from a YAML file, overwrite properties |
| [Fixtures/References.feature](Features/Fixtures/References.feature) | set node references |
| [Fixtures/DefaultDimension.feature](Features/Fixtures/DefaultDimension.feature) | default dimension space point: tables and YAML without the column, mixed with rows of another dimension, another default per scenario |
| [Backend/Login.feature](Features/Backend/Login.feature) | create a backend user, log into the Neos backend, use the document tree, main menu and dashboard |
| [PersistentResources/Download.feature](Features/PersistentResources/Download.feature) | create a file asset fixture and reference it from a node |
| [PersistentResources/Images.feature](Features/PersistentResources/Images.feature) | create an image asset fixture and render it |
| [Browser/FormInteraction.feature](Features/Browser/FormInteraction.feature) | fill, check, choose and select fields by label, click buttons/links/test ids, upload a file, press keys |
| [Browser/PageAssertions.feature](Features/Browser/PageAssertions.feature) | page title, texts on the page and in elements, element states by test id, viewport |
| [Browser/BrowserState.feature](Features/Browser/BrowserState.feature) | check and preset cookies and web storage (e.g. a consent decision) |
| [WireMock/Weather.feature](Features/WireMock/Weather.feature) | answer a server-side API call of the site with WireMock: base stubs, per-scenario responses, tapes, call counts |
| [Export/Button.feature](Features/Export/Button.feature) | *package-internal:* the "Export Node" button - download as admin, round trip through the import, disabled for editors |
| [Export/Command.feature](Features/Export/Command.feature) | *package-internal:* `./flow e2efixture:export` - by id, by URI path, as Gherkin, round trip, errors |

The [FeatureContext](Features/Bootstrap/FeatureContext.php) uses every shipped trait - a new feature can use any step
from the README. Pages for the browser steps come from the test site's Fusion fixtures
(`Resources/Private/Fusion/TestMarkup/`), placed with a `Content.TestMarkup` node: `{"markup":"Form"}` renders
`TestMarkup.Form`.

The export features test the package itself and use steps only this suite has
([FixtureExportTrait](Features/Bootstrap/FixtureExportTrait.php)) - they aren't examples to copy.

Not covered here: "I pause for debugging" (needs a visible browser - its guard has a unit test), "I debug the
playwright script" (prints the script), and "I get the node" without a dimension (the test site has a language
dimension).
