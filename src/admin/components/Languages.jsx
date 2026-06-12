import React, { useState, useEffect, useMemo } from "react";
import { api } from "@/admin/lib/api";
import { LoadingSpinner, ErrorState, Toast, PolyglotNav } from "@/admin/components/shared";

export default function Languages() {
	const [languages, setLanguages] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [toast, setToast] = useState(null);
	const [showAddDialog, setShowAddDialog] = useState(false);
	const [selectedCode, setSelectedCode] = useState("");
	const [searchQuery, setSearchQuery] = useState("");
	const [confirmDelete, setConfirmDelete] = useState(null);

	const fetchLanguages = async () => {
		setLoading(true);
		setError(null);
		try {
			const data = await api.get("/languages");
			setLanguages(data);
		} catch (err) {
			setError(err.message);
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		fetchLanguages();
	}, []);

	const handleAddLanguage = async () => {
		if (!selectedCode) return;

		const locale = availableToAdd.find((l) => l.code === selectedCode);
		if (!locale) return;

		try {
			await api.post("/languages", {
				code: locale.code,
				locale: locale.locale,
				english_name: locale.english_name,
				native_name: locale.native_name,
			});
			setToast({
				message: `${locale.english_name} activated successfully`,
				type: "success",
			});
			setShowAddDialog(false);
			setSelectedCode("");
			setSearchQuery("");
			fetchLanguages();
		} catch (err) {
			setToast({ message: err.message, type: "error" });
		}
	};

	const handleDeactivate = async (code) => {
		try {
			await api.del(`/languages/${code}`);
			setToast({ message: "Language deactivated", type: "success" });
			setConfirmDelete(null);
			fetchLanguages();
		} catch (err) {
			setToast({ message: err.message, type: "error" });
		}
	};

	const handleSetDefault = async (code) => {
		try {
			await api.put(`/languages/${code}`, { is_default: true });
			setToast({ message: "Default language updated", type: "success" });
			fetchLanguages();
		} catch (err) {
			setToast({ message: err.message, type: "error" });
		}
	};

	const activeLanguages = languages.filter((l) => l.is_active);

	const availableToAdd = useMemo(
		() =>
			languages
				.filter((l) => !l.is_active)
				.sort((a, b) => a.english_name.localeCompare(b.english_name)),
		[languages]
	);

	const filteredToAdd = useMemo(() => {
		if (!searchQuery.trim()) return availableToAdd;
		const q = searchQuery.toLowerCase();
		return availableToAdd.filter(
			(l) =>
				l.english_name.toLowerCase().includes(q) ||
				l.native_name.toLowerCase().includes(q) ||
				l.code.toLowerCase().includes(q)
		);
	}, [availableToAdd, searchQuery]);

	if (loading) return <LoadingSpinner />;
	if (error) return <ErrorState message={error} onRetry={fetchLanguages} />;

	return (
		<div className="p-6">
			<PolyglotNav />
			<div className="mb-6 flex items-center justify-between">
				<h1 className="text-2xl font-bold text-gray-900">Languages</h1>
				<button
					onClick={() => setShowAddDialog(true)}
					disabled={availableToAdd.length === 0}
					className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
				>
					Add Language
				</button>
			</div>

			<div className="rounded-lg border bg-white shadow-sm">
				<div className="overflow-x-auto">
					<table className="w-full">
						<thead>
							<tr className="border-b bg-gray-50 text-left text-sm font-medium text-gray-500">
								<th className="px-4 py-3">Language</th>
								<th className="px-4 py-3">Code</th>
								<th className="px-4 py-3">Native Name</th>
								<th className="px-4 py-3">Default</th>
								<th className="px-4 py-3">Actions</th>
							</tr>
						</thead>
						<tbody>
							{activeLanguages.length === 0 ? (
								<tr>
									<td colSpan={5} className="px-4 py-8 text-center text-gray-500">
										No active languages. Use "Add Language" to activate one.
									</td>
								</tr>
							) : (
								activeLanguages.map((lang) => (
									<tr key={lang.code} className="border-b last:border-b-0">
										<td className="px-4 py-3 font-medium text-gray-900">
											{lang.english_name}
										</td>
										<td className="px-4 py-3 text-gray-600">{lang.code}</td>
										<td className="px-4 py-3 text-gray-600">{lang.native_name}</td>
										<td className="px-4 py-3">
											{lang.is_default ? (
												<span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
													Default
												</span>
											) : (
												<button
													onClick={() => handleSetDefault(lang.code)}
													className="text-sm text-blue-600 hover:text-blue-800"
												>
													Set as default
												</button>
											)}
										</td>
										<td className="px-4 py-3">
											{!lang.is_default && (
												<button
													onClick={() => setConfirmDelete(lang)}
													className="text-sm text-red-600 hover:text-red-800"
												>
													Deactivate
												</button>
											)}
										</td>
									</tr>
								))
							)}
						</tbody>
					</table>
				</div>
			</div>

			<div className="mt-8 rounded-lg border bg-white p-6 shadow-sm">
				<h2 className="mb-4 text-lg font-bold text-gray-900">How to Enable the Language Switcher</h2>
				<p className="mb-4 text-sm text-gray-600">
					Once you have configured and activated multiple languages, you can display the language switcher on your site using one of the following methods:
				</p>
				<div className="space-y-4 text-sm text-gray-700">
					<div>
						<h3 className="font-semibold text-gray-900">1. Navigation Menu (Recommended)</h3>
						<p className="text-gray-600">
							Go to <span className="font-medium text-gray-800">Appearance &gt; Menus</span>, select the checkbox for your target languages in the "PolyGlot Languages" section on the left, and click "Add to Menu".
						</p>
					</div>
					<div>
						<h3 className="font-semibold text-gray-900">2. Shortcode</h3>
						<p className="text-gray-600">
							Add the shortcode <code className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-red-600">[polyglot_switcher]</code> to any page, post, or text widget.
						</p>
						<p className="mt-1 text-xs text-gray-500">
							Supported attributes: <code className="font-mono">format</code> ("list" or "dropdown"), <code className="font-mono">show_flags</code> ("true" or "false"), <code className="font-mono">show_names</code> ("true" or "false"), and <code className="font-mono">exclude</code> (comma-separated codes).
						</p>
					</div>
					<div>
						<h3 className="font-semibold text-gray-900">3. Gutenberg Block</h3>
						<p className="text-gray-600">
							Insert the "PolyGlot Language Switcher" block in the block editor (found under the Widgets category) and customize settings in the sidebar.
						</p>
					</div>
					<div>
						<h3 className="font-semibold text-gray-900">4. Classic Widget</h3>
						<p className="text-gray-600">
							Go to <span className="font-medium text-gray-800">Appearance &gt; Widgets</span> and drag the "PolyGlot Language Switcher" widget into any widget area.
						</p>
					</div>
					<div>
						<h3 className="font-semibold text-gray-900">5. PHP Template Code</h3>
						<p className="mb-2 text-gray-600">
							To render the switcher programmatically in your theme files (e.g. <code className="font-mono">header.php</code>), use this code:
						</p>
						<pre className="overflow-x-auto rounded-md bg-gray-50 p-3 font-mono text-xs text-gray-800 border">
{`if ( class_exists( '\\NovaTools\\Polyglot\\Core\\Plugin' ) ) {
    $switcher = \\NovaTools\\Polyglot\\Core\\Plugin::getInstance()->get( 'language_switcher' );
    echo $switcher->render( array( 'format' => 'list' ) );
}`}
						</pre>
					</div>
				</div>
			</div>

			{showAddDialog && (
				<div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
					<div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
						<h2 className="mb-4 text-lg font-semibold text-gray-900">Add Language</h2>
						{availableToAdd.length === 0 ? (
							<p className="mb-4 text-gray-500">All available languages are already active.</p>
						) : (
							<>
								<input
									type="text"
									value={searchQuery}
									onChange={(e) => {
										setSearchQuery(e.target.value);
										setSelectedCode("");
									}}
									placeholder="Search languages..."
									className="mb-2 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
									autoFocus
								/>
								<select
									value={selectedCode}
									onChange={(e) => setSelectedCode(e.target.value)}
									size={8}
									className="mb-4 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
								>
									{filteredToAdd.length === 0 ? (
										<option disabled>No languages found</option>
									) : (
										filteredToAdd.map((lang) => (
											<option key={lang.code} value={lang.code}>
												{lang.english_name} ({lang.native_name})
											</option>
										))
									)}
								</select>
								<p className="mb-4 text-xs text-gray-400">
									{filteredToAdd.length} of {availableToAdd.length} languages
								</p>
							</>
						)}
						<div className="flex justify-end gap-2">
							<button
								onClick={() => {
									setShowAddDialog(false);
									setSearchQuery("");
									setSelectedCode("");
								}}
								className="rounded-md border px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
							>
								Cancel
							</button>
							{availableToAdd.length > 0 && (
								<button
									onClick={handleAddLanguage}
									disabled={!selectedCode}
									className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
								>
									Activate
								</button>
							)}
						</div>
					</div>
				</div>
			)}

			{confirmDelete && (
				<div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
					<div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
						<h2 className="mb-4 text-lg font-semibold text-gray-900">Deactivate Language</h2>
						<p className="mb-4 text-gray-600">
							Are you sure you want to deactivate {confirmDelete.english_name}?
						</p>
						<div className="flex justify-end gap-2">
							<button
								onClick={() => setConfirmDelete(null)}
								className="rounded-md border px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
							>
								Cancel
							</button>
							<button
								onClick={() => handleDeactivate(confirmDelete.code)}
								className="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
							>
								Deactivate
							</button>
						</div>
					</div>
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
