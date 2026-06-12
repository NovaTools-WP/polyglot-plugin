import React, { useState, useEffect } from "react";
import "./addon.css";
import Dashboard from "./components/Dashboard";
import Languages from "./components/Languages";
import Translations from "./components/Translations";
import StringTranslation from "./components/StringTranslation";
import Scan from "./components/Scan";
import Settings from "./components/Settings";
import ImportWpml from "./components/ImportWpml";

const navItems = [
  { path: "polyglot", label: "Dashboard" },
  { path: "polyglot/languages", label: "Languages" },
  { path: "polyglot/translations", label: "Translations" },
  { path: "polyglot/string-translation", label: "String Translation" },
  { path: "polyglot/scan", label: "Scan" },
  { path: "polyglot/settings", label: "Settings" },
  { path: "polyglot/import-wpml", label: "Import WPML" },
];

function getHashPath() {
  const hash = window.location.hash || "#/";
  return hash.replace(/^#\/?/, "").split("?")[0];
}

function AddonLayout({ children }) {
  const [current, setCurrent] = useState(getHashPath());

  useEffect(() => {
    const onHashChange = () => setCurrent(getHashPath());
    window.addEventListener("hashchange", onHashChange);
    return () => window.removeEventListener("hashchange", onHashChange);
  }, []);

  return (
    <div className="flex min-h-screen bg-gray-50">
      <aside className="w-60 shrink-0 border-r border-gray-200 bg-white p-4">
        <h1 className="mb-6 text-lg font-semibold text-gray-900">Polyglot</h1>
        <nav className="space-y-1">
          {navItems.map((item) => (
            <button
              key={item.path}
              onClick={() => {
                window.location.hash = "#/" + item.path;
              }}
              className={`w-full rounded-md px-3 py-2 text-left text-sm transition-colors ${
                current === item.path
                  ? "bg-gray-100 font-medium text-gray-900"
                  : "text-gray-600 hover:bg-gray-50 hover:text-gray-900"
              }`}
            >
              {item.label}
            </button>
          ))}
        </nav>
      </aside>
      <main className="flex-1 p-6">{children}</main>
    </div>
  );
}

function withLayout(Component) {
  return function WrappedComponent() {
    return (
      <AddonLayout>
        <Component />
      </AddonLayout>
    );
  };
}

// Register components on the global NovaTools addon registry.
window.NovaToolsAddons = window.NovaToolsAddons || {};
window.NovaToolsAddons["novatools-polyglot"] = {
  PolyglotDashboard: withLayout(Dashboard),
  Languages: withLayout(Languages),
  Translations: withLayout(Translations),
  StringTranslation: withLayout(StringTranslation),
  Scan: withLayout(Scan),
  PolyglotSettings: withLayout(Settings),
  ImportWpml: withLayout(ImportWpml),
};

console.log("[Polyglot Addon] Registered components on NovaToolsAddons");
