import React, { useState, useEffect } from "react";
import { api } from "@/admin/lib/api";
import {
  LoadingSpinner,
  ErrorState,
  Toast,
  PolyglotNav,
} from "@/admin/components/shared";

export default function ImportWpml() {
  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [toast, setToast] = useState(null);

  // State for step 1
  const [detectedData, setDetectedData] = useState(null);

  // State for step 2
  const [selections, setSelections] = useState({
    languages: true,
    translations: true,
    strings: true,
    settings: true,
    woocommerce: true,
  });

  // State for step 3
  const [previewData, setPreviewData] = useState([]);
  const [previewLoading, setPreviewLoading] = useState(false);

  // State for step 4
  const [importProgress, setImportProgress] = useState(0);
  const [importStatus, setImportStatus] = useState("");
  const [importLogs, setImportLogs] = useState([]);
  const [isImporting, setIsImporting] = useState(false);

  // State for step 5
  const [verificationData, setVerificationData] = useState(null);

  useEffect(() => {
    if (step === 1) {
      detectTables();
    }
  }, [step]);

  const detectTables = async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await api.get("/import-wpml/detect");
      setDetectedData(data);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const generatePreview = async () => {
    setPreviewLoading(true);
    setError(null);
    try {
      const data = await api.post("/import-wpml/dry-run", selections);
      setPreviewData(data);
      setStep(3);
    } catch (err) {
      setError(err.message);
    } finally {
      setPreviewLoading(false);
    }
  };

  const startImport = async () => {
    setStep(4);
    setIsImporting(true);
    setImportLogs([]);
    setImportStatus("Preparing import...");
    setImportProgress(0);

    const phases = [];
    if (selections.languages)
      phases.push({ step: "languages", label: "Importing languages..." });
    if (selections.translations)
      phases.push({
        step: "translations",
        label: "Importing content translations...",
      });
    if (selections.strings)
      phases.push({
        step: "strings",
        label: "Importing string translations...",
      });
    if (selections.settings)
      phases.push({ step: "settings", label: "Importing settings..." });
    if (selections.woocommerce)
      phases.push({
        step: "woocommerce",
        label: "Importing WooCommerce data...",
      });

    const totalPhases = phases.length;

    if (totalPhases === 0) {
      setImportStatus("Nothing to import.");
      setIsImporting(false);
      return;
    }

    for (let i = 0; i < phases.length; i++) {
      const phase = phases[i];
      setImportStatus(phase.label);
      setImportProgress(Math.round((i / totalPhases) * 100));

      setImportLogs((prev) => [
        ...prev,
        { type: "info", text: `→ ${phase.label}` },
      ]);

      try {
        const response = await api.post("/import-wpml/execute", {
          step: phase.step,
        });
        setImportLogs((prev) => [
          ...prev,
          {
            type: "success",
            text: `  ✓ ${response.message || "Done"} (${response.count || 0} rows)`,
          },
        ]);
      } catch (err) {
        setImportLogs((prev) => [
          ...prev,
          { type: "error", text: `  ✗ ${err.message || "Error"}` },
        ]);
      }
    }

    setImportStatus("Import complete!");
    setImportProgress(100);
    setImportLogs((prev) => [
      ...prev,
      { type: "success", text: "All phases completed successfully." },
    ]);
    setIsImporting(false);
  };

  const fetchVerification = async () => {
    setLoading(true);
    try {
      const data = await api.get("/import-wpml/verify");
      setVerificationData(data);
      setStep(5);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  // Helper to calculate total rows for preview
  const totalPreviewRows = previewData.reduce(
    (sum, item) => sum + (parseInt(item.rows, 10) || 0),
    0,
  );
  const hasTables =
    detectedData &&
    detectedData.tables &&
    Object.keys(detectedData.tables).length > 0;

  return (
    <div className="p-6">
      {toast && (
        <Toast
          message={toast.message}
          type={toast.type}
          onClose={() => setToast(null)}
        />
      )}
      <PolyglotNav />

      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-900">Import from WPML</h1>
      </div>

      <div className="mb-8 flex overflow-hidden rounded-md border border-gray-300">
        {["Detect WPML", "Select Data", "Preview", "Import", "Complete"].map(
          (label, index) => {
            const num = index + 1;
            const isActive = num === step;
            const isDone = num < step;

            let bgClass = "bg-gray-100 text-gray-800";
            if (isActive) bgClass = "bg-blue-600 text-white font-bold";
            else if (isDone) bgClass = "bg-green-600 text-white";

            return (
              <div
                key={num}
                className={`flex-1 p-3 text-center text-sm ${bgClass}`}
              >
                <span className="mb-1 block text-base">{num}</span>
                {label}
              </div>
            );
          },
        )}
      </div>

      {loading && step !== 4 && <LoadingSpinner />}

      {error && <ErrorState message={error} className="mb-6" />}

      {/* STEP 1: Detect */}
      {step === 1 && !loading && detectedData && (
        <div>
          {!hasTables ? (
            <div className="mb-6 rounded-md border border-red-200 bg-red-50 p-4 text-red-700">
              No WPML tables found in the database. Make sure WPML is (or was)
              installed before importing.
            </div>
          ) : (
            <>
              <div className="mb-6 rounded-md border border-green-200 bg-green-50 p-4 text-green-800">
                WPML data detected! The following tables were found:
              </div>

              <div className="mb-6 overflow-hidden rounded-lg border border-gray-200 bg-white">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                        Table
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                        Rows
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {Object.entries(detectedData.tables).map(
                      ([table, count]) => (
                        <tr key={table}>
                          <td className="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-900">
                            {table}
                          </td>
                          <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                            {count.toLocaleString()}
                          </td>
                        </tr>
                      ),
                    )}
                  </tbody>
                </table>
              </div>

              {detectedData.wpml_active && (
                <div className="mb-6 rounded-md border border-yellow-200 bg-yellow-50 p-4 text-yellow-800">
                  WPML is currently active. You can keep it running during
                  import — WPML tables are read-only during the process.
                </div>
              )}

              <button
                onClick={() => setStep(2)}
                className="rounded-md bg-blue-600 px-4 py-2 text-white hover:bg-blue-700"
              >
                Continue to Data Selection
              </button>
            </>
          )}
        </div>
      )}

      {/* STEP 2: Select Data */}
      {step === 2 && (
        <div>
          <p className="mb-6 text-gray-600">
            Choose which data to import from WPML. Unchecked items will be
            skipped.
          </p>

          <div className="mb-6 space-y-4">
            <div className="flex items-start gap-3">
              <input
                type="checkbox"
                id="import_languages"
                className="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600"
                checked={selections.languages}
                disabled={!detectedData?.tables?.icl_languages}
                onChange={(e) =>
                  setSelections({ ...selections, languages: e.target.checked })
                }
              />
              <div>
                <label
                  htmlFor="import_languages"
                  className="font-medium text-gray-900"
                >
                  Languages
                </label>
                <p className="text-sm text-gray-500">
                  Import language definitions, flags, and locale mappings from
                  icl_languages / icl_languages_translations.
                </p>
              </div>
            </div>

            <div className="flex items-start gap-3">
              <input
                type="checkbox"
                id="import_translations"
                className="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600"
                checked={selections.translations}
                disabled={!detectedData?.tables?.icl_translations}
                onChange={(e) =>
                  setSelections({
                    ...selections,
                    translations: e.target.checked,
                  })
                }
              />
              <div>
                <label
                  htmlFor="import_translations"
                  className="font-medium text-gray-900"
                >
                  Content Translations
                </label>
                <p className="text-sm text-gray-500">
                  Import post, page, taxonomy term, and custom post type
                  translation relationships from icl_translations.
                </p>
              </div>
            </div>

            <div className="flex items-start gap-3">
              <input
                type="checkbox"
                id="import_strings"
                className="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600"
                checked={selections.strings}
                disabled={
                  !detectedData?.tables?.icl_strings ||
                  !detectedData?.tables?.icl_string_translations
                }
                onChange={(e) =>
                  setSelections({ ...selections, strings: e.target.checked })
                }
              />
              <div>
                <label
                  htmlFor="import_strings"
                  className="font-medium text-gray-900"
                >
                  String Translations
                </label>
                <p className="text-sm text-gray-500">
                  Import registered strings and their translations from
                  icl_strings / icl_string_translations.
                </p>
              </div>
            </div>

            <div className="flex items-start gap-3">
              <input
                type="checkbox"
                id="import_settings"
                className="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600"
                checked={selections.settings}
                onChange={(e) =>
                  setSelections({ ...selections, settings: e.target.checked })
                }
              />
              <div>
                <label
                  htmlFor="import_settings"
                  className="font-medium text-gray-900"
                >
                  WPML Settings
                </label>
                <p className="text-sm text-gray-500">
                  Map WPML settings (default language, URL format, etc.) to
                  Polyglot equivalents.
                </p>
              </div>
            </div>

            {detectedData?.has_woocommerce &&
              detectedData?.tables?.icl_translations && (
                <div className="flex items-start gap-3">
                  <input
                    type="checkbox"
                    id="import_woocommerce"
                    className="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600"
                    checked={selections.woocommerce}
                    onChange={(e) =>
                      setSelections({
                        ...selections,
                        woocommerce: e.target.checked,
                      })
                    }
                  />
                  <div>
                    <label
                      htmlFor="import_woocommerce"
                      className="font-medium text-gray-900"
                    >
                      WooCommerce Data
                    </label>
                    <p className="text-sm text-gray-500">
                      Import WooCommerce product translations, multi-currency
                      settings, and exchange rates.
                    </p>
                  </div>
                </div>
              )}
          </div>

          <div className="flex gap-3">
            <button
              onClick={() => setStep(1)}
              className="rounded-md border border-gray-300 bg-white px-4 py-2 text-gray-700 hover:bg-gray-50"
            >
              &larr; Back
            </button>
            <button
              onClick={generatePreview}
              disabled={previewLoading}
              className="rounded-md bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {previewLoading ? "Loading Preview..." : "Preview Import"}
            </button>
          </div>
        </div>
      )}

      {/* STEP 3: Preview */}
      {step === 3 && (
        <div>
          <h2 className="mb-2 text-xl font-semibold">
            Import Preview (Dry Run)
          </h2>
          <p className="mb-6 text-gray-600">
            This is a preview. No data has been modified yet.
          </p>

          <div className="mb-6 overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                    Data Type
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                    WPML Source
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                    Polyglot Target
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                    Estimated Rows
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {previewData.length > 0 ? (
                  previewData.map((item, index) => (
                    <tr key={index}>
                      <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                        {item.label}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-500">
                        {item.source}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-500">
                        {item.target}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                        {item.rows.toLocaleString()}
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td
                      colSpan={4}
                      className="px-6 py-4 text-center text-sm text-gray-500"
                    >
                      Nothing selected for import.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <p className="mb-6 text-gray-800">
            <strong>Total rows to import:</strong>{" "}
            {totalPreviewRows.toLocaleString()}
          </p>

          {totalPreviewRows > 0 && (
            <div className="mb-6 rounded-md border border-blue-200 bg-blue-50 p-4 text-blue-800">
              WPML tables are read-only during import. Your existing WPML data
              will not be modified.
            </div>
          )}

          <div className="flex gap-3">
            <button
              onClick={() => setStep(2)}
              className="rounded-md border border-gray-300 bg-white px-4 py-2 text-gray-700 hover:bg-gray-50"
            >
              &larr; Back
            </button>
            {totalPreviewRows > 0 && (
              <button
                onClick={startImport}
                className="rounded-md bg-blue-600 px-6 py-2 font-medium text-white hover:bg-blue-700"
              >
                Start Import
              </button>
            )}
          </div>
        </div>
      )}

      {/* STEP 4: Import */}
      {step === 4 && (
        <div>
          <h2 className="mb-6 text-xl font-semibold">Importing...</h2>

          <div className="mb-6">
            <div className="mb-2 h-6 w-full overflow-hidden rounded-md bg-gray-200">
              <div
                className="h-full bg-blue-600 transition-all duration-300"
                style={{ width: `${importProgress}%` }}
              />
            </div>
            <p className="text-gray-700">{importStatus}</p>
          </div>

          <div className="mb-6 max-h-64 overflow-y-auto rounded-md border border-gray-300 bg-gray-50 p-4 font-mono text-sm">
            {importLogs.map((log, index) => {
              let colorClass = "text-gray-800";
              if (log.type === "success") colorClass = "text-green-600";
              else if (log.type === "error") colorClass = "text-red-600";

              return (
                <div key={index} className={`mb-1 ${colorClass}`}>
                  {log.text}
                </div>
              );
            })}
          </div>

          {!isImporting && (
            <button
              onClick={fetchVerification}
              className="rounded-md bg-blue-600 px-6 py-2 text-white hover:bg-blue-700"
            >
              View Results
            </button>
          )}
        </div>
      )}

      {/* STEP 5: Complete */}
      {step === 5 && verificationData && (
        <div>
          <h2 className="mb-6 text-xl font-semibold">Import Complete</h2>

          {Object.keys(verificationData).length > 0 && (
            <div className="mb-6 overflow-hidden rounded-lg border border-gray-200 bg-white">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                      Table
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                      Rows
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {Object.entries(verificationData).map(([table, count]) => (
                    <tr key={table}>
                      <td className="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-900">
                        {table}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                        {count.toLocaleString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <div className="mb-6 rounded-md border border-green-200 bg-green-50 p-4 text-green-800">
            Import completed! You can now start using Polyglot for your
            multilingual content.
          </div>

          <div className="mb-6">
            <h3 className="mb-3 text-lg font-medium">Next Steps</h3>
            <ol className="list-inside list-decimal space-y-2 text-gray-700">
              <li>Verify your languages are correct on the Languages page.</li>
              <li>Check the Dashboard for translation status.</li>
              <li>Review URL settings in Settings.</li>
              <li>Once verified, you can safely deactivate WPML plugins.</li>
            </ol>
          </div>
        </div>
      )}
    </div>
  );
}
