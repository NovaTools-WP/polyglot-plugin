import React, { useState, useEffect } from "react";
import { api } from "@/admin/lib/api";
import {
  LoadingSpinner,
  ErrorState,
  Toast,
  PolyglotNav,
} from "@/admin/components/shared";

export default function StringTranslation() {
  const [strings, setStrings] = useState([]);
  const [domains, setDomains] = useState([]);
  const [languages, setLanguages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [toast, setToast] = useState(null);
  const [domainFilter, setDomainFilter] = useState("");
  const [searchQuery, setSearchQuery] = useState("");
  const [editingCell, setEditingCell] = useState(null);
  const [editValue, setEditValue] = useState("");
  const [showRegisterForm, setShowRegisterForm] = useState(false);
  const [newString, setNewString] = useState({
    domain: "",
    name: "",
    value: "",
  });

  const fetchStrings = async (domain = "") => {
    setLoading(true);
    setError(null);
    try {
      const params = domain ? `?domain=${encodeURIComponent(domain)}` : "";
      const data = await api.get(`/strings${params}`);
      setStrings(data.items || []);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const fetchMeta = async () => {
    try {
      const [languagesData, domainsData] = await Promise.all([
        api.get("/languages?active_only=true"),
        api.get("/strings/domains"),
      ]);
      setLanguages(languagesData);
      setDomains(domainsData || []);
    } catch (err) {
      setError(err.message);
    }
  };

  useEffect(() => {
    fetchMeta();
    fetchStrings();
  }, []);

  useEffect(() => {
    fetchStrings(domainFilter);
  }, [domainFilter]);

  const filteredStrings = strings.filter((str) => {
    if (searchQuery) {
      const query = searchQuery.toLowerCase();
      return (
        str.value?.toLowerCase().includes(query) ||
        str.name?.toLowerCase().includes(query) ||
        str.domain?.toLowerCase().includes(query)
      );
    }
    return true;
  });

  const handleCellClick = (stringId, langCode, currentValue) => {
    setEditingCell({ stringId, langCode });
    setEditValue(currentValue || "");
  };

  const handleSaveInline = async () => {
    if (!editingCell) return;

    try {
      await api.post(`/strings/${editingCell.stringId}/translate`, {
        language: editingCell.langCode,
        value: editValue,
        status: 1,
      });
      setToast({ message: "Translation saved", type: "success" });
      setEditingCell(null);
      fetchStrings(domainFilter);
    } catch (err) {
      setToast({ message: err.message, type: "error" });
    }
  };

  const handleRegisterString = async () => {
    if (!newString.domain || !newString.value) return;

    try {
      await api.post("/strings", {
        domain: newString.domain,
        name:
          newString.name || newString.value.toLowerCase().replace(/\s+/g, "_"),
        value: newString.value,
      });
      setToast({ message: "String registered", type: "success" });
      setShowRegisterForm(false);
      setNewString({ domain: "", name: "", value: "" });
      fetchStrings(domainFilter);
      fetchMeta();
    } catch (err) {
      setToast({ message: err.message, type: "error" });
    }
  };

  const getTranslationStatus = (string, langCode) => {
    const translation = string.translations?.[langCode];
    return Number(translation?.status) === 1;
  };

  if (loading) return <LoadingSpinner />;
  if (error)
    return (
      <ErrorState
        message={error}
        onRetry={() => {
          fetchMeta();
          fetchStrings(domainFilter);
        }}
      />
    );

  return (
    <div className="p-6">
      <PolyglotNav />
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-900">String Translation</h1>
        <button
          onClick={() => setShowRegisterForm(true)}
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
        >
          Register String
        </button>
      </div>

      <div className="mb-4 flex gap-4">
        <select
          value={domainFilter}
          onChange={(e) => setDomainFilter(e.target.value)}
          className="rounded-md border border-gray-300 px-3 py-2"
        >
          <option value="">All domains</option>
          {domains.map((domain) => (
            <option key={domain} value={domain}>
              {domain}
            </option>
          ))}
        </select>
        <input
          type="text"
          placeholder="Search strings..."
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          className="flex-1 rounded-md border border-gray-300 px-3 py-2"
        />
      </div>

      <div className="rounded-lg border bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b bg-gray-50 text-left text-sm font-medium text-gray-500">
                <th className="px-4 py-3">Domain</th>
                <th className="px-4 py-3">Original</th>
                {languages.map((lang) => (
                  <th key={lang.code} className="px-4 py-3 text-center">
                    {lang.code}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {filteredStrings.map((str) => (
                <tr key={str.id} className="border-b last:border-b-0">
                  <td className="px-4 py-3 text-gray-600">{str.domain}</td>
                  <td className="px-4 py-3 font-medium text-gray-900">
                    {str.value}
                  </td>
                  {languages.map((lang) => {
                    const isTranslated = getTranslationStatus(str, lang.code);
                    const isEditing =
                      editingCell?.stringId === str.id &&
                      editingCell?.langCode === lang.code;

                    return (
                      <td key={lang.code} className="px-4 py-3 text-center">
                        {isEditing ? (
                          <input
                            type="text"
                            value={editValue}
                            onChange={(e) => setEditValue(e.target.value)}
                            onBlur={handleSaveInline}
                            onKeyDown={(e) => {
                              if (e.key === "Enter") handleSaveInline();
                              if (e.key === "Escape") setEditingCell(null);
                            }}
                            className="w-full rounded border border-blue-500 px-2 py-1 text-sm"
                            autoFocus
                          />
                        ) : (
                          <button
                            onClick={() =>
                              handleCellClick(
                                str.id,
                                lang.code,
                                str.translations?.[lang.code]?.value,
                              )
                            }
                            className="inline-flex h-6 w-6 items-center justify-center rounded-full hover:bg-gray-100"
                          >
                            {isTranslated ? (
                              <span className="text-green-600">✓</span>
                            ) : (
                              <span className="text-gray-400">✗</span>
                            )}
                          </button>
                        )}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {showRegisterForm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
          <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 className="mb-4 text-lg font-semibold text-gray-900">
              Register String
            </h2>
            <div className="space-y-4">
              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">
                  Domain
                </label>
                <input
                  type="text"
                  value={newString.domain}
                  onChange={(e) =>
                    setNewString({ ...newString, domain: e.target.value })
                  }
                  placeholder="e.g., theme, plugin-name"
                  className="w-full rounded-md border border-gray-300 px-3 py-2"
                />
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">
                  Name (optional)
                </label>
                <input
                  type="text"
                  value={newString.name}
                  onChange={(e) =>
                    setNewString({ ...newString, name: e.target.value })
                  }
                  placeholder="Auto-generated from value"
                  className="w-full rounded-md border border-gray-300 px-3 py-2"
                />
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">
                  Value
                </label>
                <input
                  type="text"
                  value={newString.value}
                  onChange={(e) =>
                    setNewString({ ...newString, value: e.target.value })
                  }
                  placeholder="The source string"
                  className="w-full rounded-md border border-gray-300 px-3 py-2"
                />
              </div>
            </div>
            <div className="mt-6 flex justify-end gap-2">
              <button
                onClick={() => setShowRegisterForm(false)}
                className="rounded-md border px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handleRegisterString}
                disabled={!newString.domain || !newString.value}
                className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                Register
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
