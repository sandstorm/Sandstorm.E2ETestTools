# Example features

Neos 9 examples for the steps this package provides. They can't run inside this package (there's no site package) —
copy them into your site package's `Tests/Behavior/Features/`, replace `Your.SitePackageKey` and adapt NodeTypes,
properties and texts to your project.

| Example | Shows |
|---|---|
| [FusionComponent/Button.feature](FusionComponent/Button.feature) | render a Fusion component without nodes, assert HTML, store in the style guide |
| [FusionIntegration/Button.feature](FusionIntegration/Button.feature) | render a NodeType integration with a context node (incl. node links) |
| [PageRendering/Homepage.feature](PageRendering/Homepage.feature) | visit a page in the browser, assert status and text, take a screenshot |
| [PageRendering/FusionPageSnapshot.feature](PageRendering/FusionPageSnapshot.feature) | render a whole page via Fusion, store desktop + mobile screenshots in the style guide |
| [Fixtures/FromFile.feature](Fixtures/FromFile.feature) + [homepage.yaml](Fixtures/homepage.yaml) | create nodes from a YAML file, overwrite properties |
| [Fixtures/References.feature](Fixtures/References.feature) | set node references |
| [Backend/Login.feature](Backend/Login.feature) | create a backend user and log into the Neos backend |
| [PersistentResources/Download.feature](PersistentResources/Download.feature) | create a file asset fixture and reference it from a node |
