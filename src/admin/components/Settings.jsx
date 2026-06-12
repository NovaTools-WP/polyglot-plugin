import React, { useState, useEffect } from "react";
import { api } from "@/admin/lib/api";
import { LoadingSpinner, ErrorState, Toast, PolyglotNav } from "@/admin/components/shared";

const URL_STRATEGIES = [
	{ value: "directory", label: "Directory", description: "example.com/en/page" },
	{ value: "subdomain", label: "Subdomain", description: "en.example.com/page" },
	{ value: "domain", label: "Domain", description: "example.fr/page" },
	{ value: "query_param", label: "Query Parameter", description: "example.com/page?lang=en" },
];

const WOO_CURRENCIES = ["USD", "EUR", "GBP", "JPY", "CAD", "AUD", "CHF", "CNY", "INR", "BRL"];

export default function Settings() {
	const [settings, setSettings] = useState({
		url_strategy: { method: "directory", hide_default_prefix: true },
		browser_redirect: false,
		translation_api: { provider: "", deepl_key: "", google_key: "", openai_key: "" },
		post_types: [],
		taxonomies: [],
		custom_fields: [],
		media: { translate_alt_text: true, translate_captions: false, translate_descriptions: false },
		woocommerce: { multi_currency: { enabled: false, mode: "by_language", rates: {} } },
	});
	const [languages, setLanguages] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [toast, setToast] = useState(null);
	const [activeTab, setActiveTab] = useState("api");
	const [saving, setSaving] = useState(false);

	const fetchData = async () => {
		setLoading(true);
		setError(null);
		try {
			const [langs, settingsData] = await Promise.all([
				api.get("/languages?active_only=true"),
				api.get("/settings").catch(() => null),
			]);
			setLanguages(langs);

			if (settingsData) {
				setSettings((prev) => ({ ...prev, ...settingsData }));
			} else {
				const globalData = window.novaToolsPolyglot || {};
				if (globalData.settings) {
					setSettings((prev) => ({ ...prev, ...globalData.settings }));
				}
			}
		} catch (err) {
			setError(err.message);
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		fetchData();
	}, []);

	const handleSave = async () => {
		setSaving(true);
		try {
			await api.post("/settings", settings);
			setToast({ message: "Settings saved successfully", type: "success" });
		} catch (err) {
			setToast({ message: err.message, type: "error" });
		} finally {
			setSaving(false);
		}
	};

	const hasWooCommerce = window.novaToolsPolyglot?.hasWooCommerce ?? false;

	const tabs = [
		{ id: "api", label: "Translation API" },
		{ id: "url", label: "URL Strategy" },
		{ id: "post_types", label: "Post Types & Taxonomies" },
		{ id: "media", label: "Media" },
		{ id: "custom_fields", label: "Custom Fields" },
		{ id: "scanning", label: "String Scanning" },
		...(hasWooCommerce ? [{ id: "woocommerce", label: "WooCommerce" }] : []),
	];

	if (loading) return <LoadingSpinner />;
	if (error) return <ErrorState message={error} onRetry={fetchData} />;

	return (
		<div className="p-6">
			<PolyglotNav />
			<div className="mb-6 flex items-center justify-between">
				<h1 className="text-2xl font-bold text-gray-900">Settings</h1>
				<button
					onClick={handleSave}
					disabled={saving}
					className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
				>
					{saving ? "Saving..." : "Save Settings"}
				</button>
			</div>

			<div className="mb-6 border-b border-gray-200">
				<nav className="-mb-px flex gap-4">
					{tabs.map((tab) => (
						<button
							key={tab.id}
							onClick={() => setActiveTab(tab.id)}
							className={`border-b-2 px-1 py-3 text-sm font-medium ${
								activeTab === tab.id
									? "border-blue-500 text-blue-600"
									: "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700"
							}`}
						>
							{tab.label}
						</button>
					))}
				</nav>
			</div>

			{activeTab === "api" && (
				<div className="space-y-6">
					<div>
						<label className="mb-2 block text-sm font-medium text-gray-700">
							Default Provider
						</label>
						<select
							value={settings.translation_api.provider}
							onChange={(e) =>
								setSettings({
									...settings,
									translation_api: { ...settings.translation_api, provider: e.target.value },
								})
							}
							className="w-full rounded-md border border-gray-300 px-3 py-2"
						>
							<option value="">Select provider...</option>
							<option value="deepl">DeepL</option>
							<option value="google">Google Translate</option>
							<option value="openai">OpenAI</option>
						</select>
					</div>
					<div>
						<label className="mb-2 block text-sm font-medium text-gray-700">
							DeepL API Key
						</label>
						<input
							type="password"
							value={settings.translation_api.deepl_key}
							onChange={(e) =>
								setSettings({
									...settings,
									translation_api: { ...settings.translation_api, deepl_key: e.target.value },
								})
							}
							placeholder="Enter your DeepL API key"
							className="w-full rounded-md border border-gray-300 px-3 py-2"
						/>
					</div>
					<div>
						<label className="mb-2 block text-sm font-medium text-gray-700">
							Google Translate API Key
						</label>
						<input
							type="password"
							value={settings.translation_api.google_key}
							onChange={(e) =>
								setSettings({
									...settings,
									translation_api: { ...settings.translation_api, google_key: e.target.value },
								})
							}
							placeholder="Enter your Google Translate API key"
							className="w-full rounded-md border border-gray-300 px-3 py-2"
						/>
					</div>
					<div>
						<label className="mb-2 block text-sm font-medium text-gray-700">
							OpenAI API Key
						</label>
						<input
							type="password"
							value={settings.translation_api.openai_key}
							onChange={(e) =>
								setSettings({
									...settings,
									translation_api: { ...settings.translation_api, openai_key: e.target.value },
								})
							}
							placeholder="Enter your OpenAI API key"
							className="w-full rounded-md border border-gray-300 px-3 py-2"
						/>
					</div>
				</div>
			)}

			{activeTab === "url" && (
				<div className="space-y-6">
					<div>
						<label className="mb-2 block text-sm font-medium text-gray-700">
							URL Strategy
						</label>
						<div className="space-y-2">
							{URL_STRATEGIES.map((strategy) => (
								<label
									key={strategy.value}
									className="flex cursor-pointer items-start gap-3 rounded-lg border p-4 hover:bg-gray-50"
								>
									<input
										type="radio"
										name="url_strategy"
										value={strategy.value}
										checked={settings.url_strategy.method === strategy.value}
										onChange={(e) =>
											setSettings({
												...settings,
												url_strategy: { ...settings.url_strategy, method: e.target.value },
											})
										}
										className="mt-1"
									/>
									<div>
										<div className="font-medium text-gray-900">{strategy.label}</div>
										<div className="text-sm text-gray-500">{strategy.description}</div>
									</div>
								</label>
							))}
						</div>
					</div>

					{settings.url_strategy.method === "directory" && (
						<div>
							<label className="flex items-center gap-2">
								<input
									type="checkbox"
									checked={settings.url_strategy.hide_default_prefix}
									onChange={(e) =>
										setSettings({
											...settings,
											url_strategy: {
												...settings.url_strategy,
												hide_default_prefix: e.target.checked,
											},
										})
									}
									className="rounded"
								/>
								<span className="text-sm font-medium text-gray-700">
									Hide default language prefix
								</span>
							</label>
						</div>
					)}

					<div>
						<label className="flex items-center gap-2">
							<input
								type="checkbox"
								checked={settings.browser_redirect}
								onChange={(e) =>
									setSettings({ ...settings, browser_redirect: e.target.checked })
								}
								className="rounded"
							/>
							<span className="text-sm font-medium text-gray-700">
								Enable browser language redirect
							</span>
						</label>
						<p className="mt-1 text-sm text-gray-500">
							Automatically redirect visitors to their browser language
						</p>
					</div>
				</div>
			)}

			{activeTab === "post_types" && (
				<div className="space-y-6">
					<div>
						<h3 className="mb-3 text-sm font-medium text-gray-700">Translatable Post Types</h3>
						<div className="space-y-2">
							{["post", "page", "product", "custom"].map((type) => (
								<label key={type} className="flex items-center gap-2">
									<input
										type="checkbox"
										checked={settings.post_types.includes(type)}
										onChange={(e) => {
											const newTypes = e.target.checked
												? [...settings.post_types, type]
												: settings.post_types.filter((t) => t !== type);
											setSettings({ ...settings, post_types: newTypes });
										}}
										className="rounded"
									/>
									<span className="text-sm text-gray-700 capitalize">{type}</span>
								</label>
							))}
						</div>
					</div>

					<div>
						<h3 className="mb-3 text-sm font-medium text-gray-700">
							Translatable Taxonomies
						</h3>
						<div className="space-y-2">
							{["category", "post_tag", "product_cat", "product_tag"].map((tax) => (
								<label key={tax} className="flex items-center gap-2">
									<input
										type="checkbox"
										checked={settings.taxonomies.includes(tax)}
										onChange={(e) => {
											const newTaxonomies = e.target.checked
												? [...settings.taxonomies, tax]
												: settings.taxonomies.filter((t) => t !== tax);
											setSettings({ ...settings, taxonomies: newTaxonomies });
										}}
										className="rounded"
									/>
									<span className="text-sm text-gray-700">{tax}</span>
								</label>
							))}
						</div>
					</div>
				</div>
			)}

		{activeTab === "custom_fields" && (
			<div className="space-y-4">
				<p className="text-sm text-gray-600">
					Custom field translation is handled automatically based on your post type selections.
				</p>
				<div className="rounded-lg border bg-gray-50 p-4">
					<h3 className="mb-2 text-sm font-medium text-gray-700">Translation Modes</h3>
					<div className="space-y-2">
						{["Don't translate", "Translate once", "Copy to all languages", "Sync always"].map(
							(mode) => (
								<label key={mode} className="flex items-center gap-2">
									<input type="radio" name="custom_field_mode" className="mt-1" />
									<span className="text-sm text-gray-700">{mode}</span>
								</label>
							)
						)}
					</div>
				</div>
			</div>
		)}

		{activeTab === "media" && (
			<div className="space-y-6">
				<div>
					<h3 className="mb-3 text-sm font-medium text-gray-700">Media Translation</h3>
					<p className="mb-4 text-sm text-gray-500">
						Configure how media attachments are translated across languages.
					</p>
					<div className="space-y-3">
						<label className="flex items-center gap-2">
							<input
								type="checkbox"
								checked={settings.media.translate_alt_text}
								onChange={(e) =>
									setSettings({
										...settings,
										media: { ...settings.media, translate_alt_text: e.target.checked },
									})
								}
								className="rounded"
							/>
							<span className="text-sm text-gray-700">Translate alt text</span>
						</label>
						<label className="flex items-center gap-2">
							<input
								type="checkbox"
								checked={settings.media.translate_captions}
								onChange={(e) =>
									setSettings({
										...settings,
										media: { ...settings.media, translate_captions: e.target.checked },
									})
								}
								className="rounded"
							/>
							<span className="text-sm text-gray-700">Translate captions</span>
						</label>
						<label className="flex items-center gap-2">
							<input
								type="checkbox"
								checked={settings.media.translate_descriptions}
								onChange={(e) =>
									setSettings({
										...settings,
										media: { ...settings.media, translate_descriptions: e.target.checked },
									})
								}
								className="rounded"
							/>
							<span className="text-sm text-gray-700">Translate descriptions</span>
						</label>
					</div>
				</div>
			</div>
		)}

		{activeTab === "scanning" && (
			<div className="space-y-6">
				<div>
					<h3 className="mb-3 text-sm font-medium text-gray-700">Auto-Scan on Activation</h3>
					<p className="mb-4 text-sm text-gray-500">
						Automatically scan and register translatable strings when a plugin or theme is activated.
					</p>
					<label className="flex items-center gap-2">
						<input
							type="checkbox"
							checked={settings.auto_scan_on_activation ?? true}
							onChange={(e) =>
								setSettings({
									...settings,
									auto_scan_on_activation: e.target.checked,
								})
							}
							className="rounded"
						/>
						<span className="text-sm text-gray-700">Enable auto-scan on plugin/theme activation</span>
					</label>
				</div>
			</div>
		)}

		{activeTab === "woocommerce" && hasWooCommerce && (
			<div className="space-y-6">
				<div>
					<h3 className="mb-3 text-sm font-medium text-gray-700">Multi-Currency</h3>
					<p className="mb-4 text-sm text-gray-500">
						Enable multi-currency support for WooCommerce products.
					</p>
					<label className="flex items-center gap-2">
						<input
							type="checkbox"
							checked={settings.woocommerce.multi_currency.enabled}
							onChange={(e) =>
								setSettings({
									...settings,
									woocommerce: {
										...settings.woocommerce,
										multi_currency: {
											...settings.woocommerce.multi_currency,
											enabled: e.target.checked,
										},
									},
								})
							}
							className="rounded"
						/>
						<span className="text-sm text-gray-700">Enable multi-currency</span>
					</label>
				</div>

				{settings.woocommerce.multi_currency.enabled && (
					<>
						<div>
							<label className="mb-2 block text-sm font-medium text-gray-700">
								Currency Mode
							</label>
							<select
								value={settings.woocommerce.multi_currency.mode}
								onChange={(e) =>
									setSettings({
										...settings,
										woocommerce: {
											...settings.woocommerce,
											multi_currency: {
												...settings.woocommerce.multi_currency,
												mode: e.target.value,
											},
										},
									})
								}
								className="w-full rounded-md border border-gray-300 px-3 py-2"
							>
								<option value="by_language">By Language</option>
								<option value="manual">Manual</option>
							</select>
						</div>

						{settings.woocommerce.multi_currency.mode === "manual" && (
							<div>
								<h4 className="mb-3 text-sm font-medium text-gray-700">
									Exchange Rates
								</h4>
								<div className="space-y-2">
									{WOO_CURRENCIES.filter((c) => c !== "USD").map((currency) => (
										<div key={currency} className="flex items-center gap-3">
											<span className="w-12 text-sm text-gray-600">{currency}</span>
											<input
												type="number"
												step="0.0001"
												min="0"
												value={settings.woocommerce.multi_currency.rates[currency] || ""}
												onChange={(e) =>
													setSettings({
														...settings,
														woocommerce: {
															...settings.woocommerce,
															multi_currency: {
																...settings.woocommerce.multi_currency,
																rates: {
																	...settings.woocommerce.multi_currency.rates,
																	[currency]: parseFloat(e.target.value) || 0,
																},
															},
														},
													})
												}
												placeholder="1.0"
												className="w-32 rounded-md border border-gray-300 px-3 py-2"
											/>
										</div>
									))}
								</div>
							</div>
						)}
					</>
				)}
			</div>
		)}

			{toast && (
				<Toast
					message={toast.message}
					type={toast.type}
					onClose={() => setToast(null)}
				/>
			)}
		</div>
	);
}
