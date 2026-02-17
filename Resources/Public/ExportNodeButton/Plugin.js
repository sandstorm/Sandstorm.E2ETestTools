(() => {
  var __create = Object.create;
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __getProtoOf = Object.getPrototypeOf;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __esm = (fn, res) => function __init() {
    return fn && (res = (0, fn[__getOwnPropNames(fn)[0]])(fn = 0)), res;
  };
  var __commonJS = (cb, mod) => function __require() {
    return mod || (0, cb[__getOwnPropNames(cb)[0]])((mod = { exports: {} }).exports, mod), mod.exports;
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toESM = (mod, isNodeMode, target) => (target = mod != null ? __create(__getProtoOf(mod)) : {}, __copyProps(
    // If the importer is in node compatibility mode or this is not an ESM
    // file that has been converted to a CommonJS file using a Babel-
    // compatible transform (i.e. "__esModule" has not been set), then set
    // "default" to the CommonJS "module.exports" for node compatibility.
    isNodeMode || !mod || !mod.__esModule ? __defProp(target, "default", { value: mod, enumerable: true }) : target,
    mod
  ));
  var __decorateClass = (decorators, target, key, kind) => {
    var result = kind > 1 ? void 0 : kind ? __getOwnPropDesc(target, key) : target;
    for (var i = decorators.length - 1, decorator; i >= 0; i--)
      if (decorator = decorators[i])
        result = (kind ? decorator(target, key, result) : decorator(result)) || result;
    if (kind && result) __defProp(target, key, result);
    return result;
  };

  // node_modules/@neos-project/neos-ui-extensibility/dist/readFromConsumerApi.js
  function readFromConsumerApi(key) {
    return (...args) => {
      if (window["@Neos:HostPluginAPI"] && window["@Neos:HostPluginAPI"][`@${key}`]) {
        return window["@Neos:HostPluginAPI"][`@${key}`](...args);
      }
      throw new Error("You are trying to read from a consumer api that hasn't been initialized yet!");
    };
  }
  var init_readFromConsumerApi = __esm({
    "node_modules/@neos-project/neos-ui-extensibility/dist/readFromConsumerApi.js"() {
    }
  });

  // node_modules/@neos-project/neos-ui-extensibility/dist/shims/vendor/react/index.js
  var require_react = __commonJS({
    "node_modules/@neos-project/neos-ui-extensibility/dist/shims/vendor/react/index.js"(exports, module) {
      init_readFromConsumerApi();
      module.exports = readFromConsumerApi("vendor")().React;
    }
  });

  // node_modules/@neos-project/neos-ui-extensibility/dist/shims/vendor/prop-types/index.js
  var require_prop_types = __commonJS({
    "node_modules/@neos-project/neos-ui-extensibility/dist/shims/vendor/prop-types/index.js"(exports, module) {
      init_readFromConsumerApi();
      module.exports = readFromConsumerApi("vendor")().PropTypes;
    }
  });

  // node_modules/@neos-project/neos-ui-extensibility/dist/shims/vendor/react-redux/index.js
  var require_react_redux = __commonJS({
    "node_modules/@neos-project/neos-ui-extensibility/dist/shims/vendor/react-redux/index.js"(exports, module) {
      init_readFromConsumerApi();
      module.exports = readFromConsumerApi("vendor")().reactRedux;
    }
  });

  // node_modules/@neos-project/neos-ui-extensibility/dist/index.js
  init_readFromConsumerApi();
  var dist_default = readFromConsumerApi("manifest");

  // src/ExportNodeButton.tsx
  var import_react = __toESM(require_react());
  var import_prop_types = __toESM(require_prop_types());
  var import_react_redux = __toESM(require_react_redux());
  var ExportNodeButton = class extends import_react.PureComponent {
    constructor() {
      super(...arguments);
      this.exportNodeButtonOnClick = () => {
        console.log(this.props.nodeIdentifier);
        console.log(this.props.currentUri);
        const parts = this.props.currentUri.split("/");
        const neosIndex = parts.indexOf("neos");
        const baseUri = parts.slice(0, neosIndex === -1 ? parts.length : neosIndex).join("/");
        const redirectUri = baseUri + "/api/export-node/" + this.props.nodeIdentifier;
        console.log(redirectUri);
        window.location.href = redirectUri;
      };
    }
    render() {
      return /* @__PURE__ */ import_react.default.createElement(
        "button",
        {
          onClick: this.exportNodeButtonOnClick
        },
        "Export Node"
      );
    }
  };
  ExportNodeButton.propTypes = {
    value: import_prop_types.default.string,
    commit: import_prop_types.default.func.isRequired,
    nodeIdentifier: import_prop_types.default.string,
    currentUri: import_prop_types.default.string
  };
  ExportNodeButton = __decorateClass([
    (0, import_react_redux.connect)((state) => {
      const nodeContextPath = state.cr.nodes.focused.contextPaths[0];
      return {
        nodeIdentifier: state.cr.nodes.byContextPath[nodeContextPath]?.identifier,
        currentUri: state.ui.contentCanvas.src
      };
    })
  ], ExportNodeButton);

  // src/manifest.ts
  dist_default("Sandstorm.E2ETestTools:ExportNodeButton", {}, (globalRegistry) => {
    const editorsRegistry = globalRegistry.get("inspector").get("editors");
    editorsRegistry.set("Sandstorm.E2ETestTools/Inspector/Editors/ExportNodeButton", {
      component: ExportNodeButton
    });
  });
})();
