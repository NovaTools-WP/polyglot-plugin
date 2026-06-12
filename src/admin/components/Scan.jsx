import React, { useState, useEffect } from "react";
import { api } from "@/admin/lib/api";
import { LoadingSpinner, ErrorState, Toast, PolyglotNav } from "@/admin/components/shared";

export default function Scan() {
	const [scope, setScope] = useState("plugin");
	const [slug, setSlug] = useState("");
	const [plugins, setPlugins] = useState([]);
	const [themes, setThemes] = useState([]);
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState(null);
	const [toast, setToast] = useState(null);

	const [scanResult, setScanResult] = useState(null);
	const [registerResult, setRegisterResult] = useState(null);
	const [selectedLanguages, setSelectedLanguages] = useState([]);
	const [importResult, setImportResult] = useState(null);
	const [staleResult, setStaleResult] = useState(null);
	const [confirmCleanup, setConfirmCleanup] = useState(false);

	useEffect(() => {
		if (window.novaToolsPolyglot?.plugins) {
			setPlugins(window.novaToolsPolyglot.plugins);
		}
		if (window.novaToolsPolyglot?.themes) {
			setThemes(window.novaToolsPolyglot.themes);
		}
	}, []);

	const targets = scope === "plugin" ? plugins : themes;

	const handleScan = async () => {
		if (!slug) return;

		setLoading(true);
		setError(null);
		setScanResult(null);
		setRegisterResult(null);
		setImportResult(null);
		setStaleResult(null);

		try {
			const data = await api.post("/scan", { scope, slug });
			setScanResult(data);
		} catch (err) {
			setError(err.message);
		} finally {
			setLoading(false);
		}
	};

	const handleRegister = async () => {
		setLoading(true);
		setError(null);
		setRegisterResult(null);

		try {
			const data = await api.post("/scan/register", { scope, slug });
			setRegisterResult(data);
			setToast({
				message: `Registered: ${data.registered}, Updated: ${data.updated}, Skipped: ${data.skipped}`,
				type: "success",
			});
		} catch (err) {
			setError(err.message);
		} finally {
			setLoading(false);
		}
	};

	const handleImportPo = async () => {
		setLoading(true);
		setError(null);
		setImportResult(null);

		try {
			const data = await api.post("/scan/import-po", {
				scope,
				slug,
				languages: selectedLanguages.length > 0 ? selectedLanguages : undefined,
			});
			setImportResult(data);
			setToast({ message: "PO import completed", type: "success" });
		} catch (err) {
			setError(err.message);
		} finally {
			setLoading(false);
		}
	};

	const handleDetectStale = async () => {
		setLoading(true);
		setError(null);
		setStaleResult(null);

		try {
			const data = await api.post("/scan/detect-stale", { scope, slug });
			setStaleResult(data);
		} catch (err) {
			setError(err.message);
		} finally {
			setLoading(false);
		}
	};

	const handleCleanupStale = async () => {
		setLoading(true);
		setError(null);

		try {
			const data = await api.post("/scan/cleanup-stale", {
				scope,
				slug,
				confirm: true,
			});
			setToast({
				message: `Deleted ${data.deleted_strings} strings and ${data.deleted_translations} translations`,
				type: "success",
			});
			setStaleResult(null);
			setConfirmCleanup(false);
		} catch (err) {
			setError(err.message);
		} finally {
			setLoading(false);
		}
	};

	const toggleLanguage = (lang) => {
		setSelectedLanguages((prev) =>
			prev.includes(lang) ? prev.filter((l) => l !== lang) : [...prev, lang]
		);
	};

	return (
		<div className="p-6">
			<PolyglotNav />
			<div className="mb-6">
				<h1 className="text-2xl font-bold text-gray-900">String Scanner</h1>
				<p className="mt-1 text-sm text-gray-500">
					Scan plugins and themes for translatable strings
				</p>
			</div>

			{error && (
				<div className="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-700">
					{error}
				</div>
			)}

			<div className="mb-6 rounded-lg border bg-white p-6 shadow-sm">
				<div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
					<div>
						<label className="mb-1 block text-sm font-medium text-gray-700">
							Scope
						</label>
						<select
							value={scope}
							onChange={(e) => {
								setScope(e.target.value);
								setSlug("");
								setScanResult(null);
							}}
							className="w-full rounded-md border border-gray-300 px-3 py-2"
						>
							<option value="plugin">Plugin</option>
							<option value="theme">Theme</option>
						</select>
					</div>

					<div>
						<label className="mb-1 block text-sm font-medium text-gray-700">
							Target
						</label>
						<select
							value={slug}
							onChange={(e) => setSlug(e.target.value)}
							className="w-full rounded-md border border-gray-300 px-3 py-2"
						>
							<option value="">Select {scope}...</option>
							{targets.map((item) => (
								<option key={item.slug} value={item.slug}>
									{item.name}
								</option>
							))}
						</select>
					</div>

					<div className="flex items-end">
						<button
							onClick={handleScan}
							disabled={!slug || loading}
							className="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
						>
							{loading ? "Scanning..." : "Scan"}
						</button>
					</div>
				</div>
			</div>

			{loading && <LoadingSpinner />}

			{scanResult && (
				<div className="mb-6 rounded-lg border bg-white p-6 shadow-sm">
					<h2 className="mb-4 text-lg font-semibold text-gray-900">Scan Results</h2>

					<div className="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
						<div className="rounded-md bg-gray-50 p-3">
							<p className="text-sm text-gray-500">Total Strings</p>
							<p className="text-xl font-semibold text-gray-900">
								{scanResult.total}
							</p>
						</div>
						{Object.entries(scanResult.domain_counts).map(([domain, count]) => (
							<div key={domain} className="rounded-md bg-gray-50 p-3">
								<p className="text-sm text-gray-500">{domain}</p>
								<p className="text-xl font-semibold text-gray-900">{count}</p>
							</div>
						))}
					</div>

					{scanResult.strings.length > 0 && (
						<div className="mb-4 max-h-60 overflow-y-auto rounded-md border">
							<table className="w-full">
								<thead className="sticky top-0 bg-gray-50">
									<tr className="text-left text-sm font-medium text-gray-500">
										<th className="px-3 py-2">String</th>
										<th className="px-3 py-2">Domain</th>
										<th className="px-3 py-2">Context</th>
									</tr>
								</thead>
								<tbody>
									{scanResult.strings.slice(0, 100).map((str, idx) => (
										<tr key={idx} className="border-t text-sm">
											<td className="px-3 py-2 font-mono text-gray-900">
												{str.msgid.substring(0, 80)}
												{str.msgid.length > 80 && "..."}
											</td>
											<td className="px-3 py-2 text-gray-600">
												{str.domain || "—"}
											</td>
											<td className="px-3 py-2 text-gray-600">
												{str.msgctxt || "—"}
											</td>
										</tr>
									))}
								</tbody>
							</table>
							{scanResult.strings.length > 100 && (
								<div className="border-t bg-gray-50 px-3 py-2 text-sm text-gray-500">
									Showing 100 of {scanResult.strings.length} strings
								</div>
							)}
						</div>
					)}

					<div className="flex flex-wrap gap-3">
						<button
							onClick={handleRegister}
							disabled={loading}
							className="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
						>
							Register All
						</button>
						<button
							onClick={handleDetectStale}
							disabled={loading}
							className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
						>
							Detect Stale
						</button>
					</div>

					{registerResult && (
						<div className="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-700">
							Registered: {registerResult.registered} | Updated:{" "}
							{registerResult.updated} | Skipped: {registerResult.skipped}
						</div>
					)}
				</div>
			)}

			{scanResult && scanResult.po_files && scanResult.po_files.length > 0 && (
				<div className="mb-6 rounded-lg border bg-white p-6 shadow-sm">
					<h2 className="mb-4 text-lg font-semibold text-gray-900">
						Import PO Translations
					</h2>
					<p className="mb-3 text-sm text-gray-500">
						{scanResult.po_files.length} PO file(s) discovered
					</p>

					<div className="mb-4 flex flex-wrap gap-2">
						{scanResult.po_files.map((file) => {
							const locale = file.match(/([a-z]{2,3}_[A-Z]{2})/)?.[1] || "unknown";
							const isSelected = selectedLanguages.includes(locale);

							return (
								<button
									key={file}
									onClick={() => toggleLanguage(locale)}
									className={`rounded-md px-3 py-1.5 text-sm font-medium ${
										isSelected
											? "bg-blue-100 text-blue-800"
											: "bg-gray-100 text-gray-700 hover:bg-gray-200"
									}`}
								>
									{locale}
								</button>
							);
						})}
					</div>

					<button
						onClick={handleImportPo}
						disabled={loading}
						className="rounded-md bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-50"
					>
						{selectedLanguages.length > 0
							? `Import ${selectedLanguages.length} Selected`
							: "Import All"}
					</button>

					{importResult && importResult.imported && (
						<div className="mt-4 space-y-2">
							{Object.entries(importResult.imported).map(([locale, stats]) => (
								<div
									key={locale}
									className="rounded-md bg-gray-50 p-3 text-sm"
								>
									<span className="font-medium">{locale}:</span>{" "}
									{stats.strings_imported} strings imported,{" "}
									{stats.translations_imported} translations imported
									{stats.errors.length > 0 && (
										<span className="text-red-600">
											{" "}
											({stats.errors.length} errors)
										</span>
									)}
								</div>
							))}
						</div>
					)}
				</div>
			)}

			{staleResult && (
				<div className="mb-6 rounded-lg border bg-white p-6 shadow-sm">
					<h2 className="mb-4 text-lg font-semibold text-gray-900">
						Stale Strings
					</h2>

					{staleResult.total === 0 ? (
						<p className="text-sm text-gray-500">No stale strings found.</p>
					) : (
						<>
							<p className="mb-3 text-sm text-gray-600">
								{staleResult.total} string(s) registered but not found in source
							</p>
							<div className="mb-4 max-h-60 overflow-y-auto rounded-md border">
								<table className="w-full">
									<thead className="sticky top-0 bg-gray-50">
										<tr className="text-left text-sm font-medium text-gray-500">
											<th className="px-3 py-2">String</th>
											<th className="px-3 py-2">Domain</th>
										</tr>
									</thead>
									<tbody>
										{staleResult.stale.map((str) => (
											<tr key={str.id} className="border-t text-sm">
												<td className="px-3 py-2 font-mono text-gray-900">
													{str.value?.substring(0, 80) || str.name}
												</td>
												<td className="px-3 py-2 text-gray-600">
													{str.domain}
												</td>
											</tr>
										))}
									</tbody>
								</table>
							</div>

							{!confirmCleanup ? (
								<button
									onClick={() => setConfirmCleanup(true)}
									className="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
								>
									Delete Stale Strings
								</button>
							) : (
								<div className="flex items-center gap-3">
									<span className="text-sm text-red-600 font-medium">
										Confirm deletion of {staleResult.total} strings?
									</span>
									<button
										onClick={handleCleanupStale}
										disabled={loading}
										className="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
									>
										Yes, Delete
									</button>
									<button
										onClick={() => setConfirmCleanup(false)}
										className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
									>
										Cancel
									</button>
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
