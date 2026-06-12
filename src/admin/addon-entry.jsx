import React from "react";
import "./addon.css";
import Dashboard from "./components/Dashboard";
import Languages from "./components/Languages";
import Translations from "./components/Translations";
import StringTranslation from "./components/StringTranslation";
import Scan from "./components/Scan";
import Settings from "./components/Settings";

// Register components on the global NovaTools addon registry.
window.NovaToolsAddons = window.NovaToolsAddons || {};
window.NovaToolsAddons["novatools-polyglot"] = {
	PolyglotDashboard: Dashboard,
	Languages,
	Translations,
	StringTranslation,
	Scan,
	PolyglotSettings: Settings,
};

console.log("[Polyglot Addon] Registered components on NovaToolsAddons");
