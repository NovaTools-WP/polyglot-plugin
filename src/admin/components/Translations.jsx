import React, { useState, useEffect } from "react";
import { api } from "@/admin/lib/api";
import {
  LoadingSpinner,
  ErrorState,
  Toast,
} from "@/admin/components/shared";

const STATUS_COLORS = {
  translated: "bg-green-100 text-green-800",
  in_progress: "bg-yellow-100 text-yellow-800",
  needs_update: "bg-orange-100 text-orange-800",
  not_translated: "bg-gray-100 text-gray-800",
  not_registered:
    "bg-gray-50 text-gray-400 border border-dashed border-gray-300",
};

export default function Translations() {
  const [items, setItems] = useState([]);
  const [languages, setLanguages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [toast, setToast] = useState(null);
  const [postTypeFilter, setPostTypeFilter] = useState("");
  const [searchQuery, setSearchQuery] = useState("");
  const [editorItem, setEditorItem] = useState(null);
  const [editorLang, setEditorLang] = useState(null);
  const [translationFields, setTranslationFields] = useState({});

  const fetchData = async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (postTypeFilter) params.set("post_type", postTypeFilter);
      if (searchQuery) params.set("search", searchQuery);
      const qs = params.toString() ? `?${params.toString()}` : "";

      const [contentData, languagesData] = await Promise.all([
        api.get(`/content${qs}`),
        api.get("/languages?active_only=true"),
      ]);
      setItems(contentData.items || []);
      setLanguages(languagesData);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, [postTypeFilter, searchQuery]);

  const postTypes = [...new Set(items.map((i) => i.post_type))].filter(Boolean);

  const handleOpenEditor = (item, langCode) => {
    setEditorItem(item);
    setEditorLang(langCode);
    const translation = item.translations?.[langCode];
    setTranslationFields({
      title: translation?.translated_title || item.title || "",
      content: translation?.translated_content || "",
      excerpt: translation?.translated_excerpt || item.excerpt || "",
    });
  };

  const handleSaveTranslation = async () => {
    if (!editorItem || !editorLang) return;

    try {
      await api.post("/translations", {
        element_type: editorItem.element_type,
        element_id: editorItem.element_id,
        language_code: editorLang,
        status: "translated",
        title: translationFields.title,
        content: translationFields.content,
        excerpt: translationFields.excerpt,
      });
      setToast({ message: "Translation saved", type: "success" });
      setEditorItem(null);
      setEditorLang(null);
      fetchData();
    } catch (err) {
      setToast({ message: err.message, type: "error" });
    }
  };

  const getStatusBadge = (item, langCode) => {
    const translation = item.translations?.[langCode];
    if (!translation) {
      return (
        <span
          className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS.not_registered}`}
        >
          not registered
        </span>
      );
    }
    const status = translation.status || "not_translated";
    return (
      <span
        className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[status]}`}
      >
        {status.replace("_", " ")}
      </span>
    );
  };

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorState message={error} onRetry={fetchData} />;

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">Translations</h1>

      <div className="mb-4 flex gap-4">
        <select
          value={postTypeFilter}
          onChange={(e) => setPostTypeFilter(e.target.value)}
          className="rounded-md border border-gray-300 px-3 py-2"
        >
          <option value="">All post types</option>
          {postTypes.map((type) => (
            <option key={type} value={type}>
              {type}
            </option>
          ))}
        </select>
        <input
          type="text"
          placeholder="Search content..."
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
                <th className="px-4 py-3">Title</th>
                <th className="px-4 py-3">Type</th>
                {languages.map((lang) => (
                  <th key={lang.code} className="px-4 py-3">
                    {lang.code}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {items.map((item, idx) => (
                <tr key={idx} className="border-b last:border-b-0">
                  <td className="px-4 py-3 font-medium text-gray-900">
                    {item.title || `#${item.element_id}`}
                  </td>
                  <td className="px-4 py-3 text-gray-600">{item.post_type}</td>
                  {languages.map((lang) => (
                    <td key={lang.code} className="px-4 py-3">
                      <button
                        onClick={() => handleOpenEditor(item, lang.code)}
                        className="hover:underline"
                      >
                        {getStatusBadge(item, lang.code)}
                      </button>
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {editorItem && (
        <div className="fixed inset-0 z-[999999] flex items-center justify-center bg-black bg-opacity-50">
          <div className="w-[90vw] max-w-2xl max-h-[85vh] overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
            <h2 className="mb-4 text-lg font-semibold text-gray-900">
              Translate: {editorItem.title || `#${editorItem.element_id}`}
              <span className="ml-2 text-sm font-normal text-gray-500">
                → {editorLang}
              </span>
            </h2>
            <div className="space-y-4">
              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">
                  Title
                </label>
                <input
                  type="text"
                  value={translationFields.title}
                  onChange={(e) =>
                    setTranslationFields({
                      ...translationFields,
                      title: e.target.value,
                    })
                  }
                  className="w-full rounded-md border border-gray-300 px-3 py-2"
                />
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">
                  Content
                </label>
                <textarea
                  value={translationFields.content}
                  onChange={(e) =>
                    setTranslationFields({
                      ...translationFields,
                      content: e.target.value,
                    })
                  }
                  rows={6}
                  className="w-full rounded-md border border-gray-300 px-3 py-2"
                />
              </div>
              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">
                  Excerpt
                </label>
                <textarea
                  value={translationFields.excerpt}
                  onChange={(e) =>
                    setTranslationFields({
                      ...translationFields,
                      excerpt: e.target.value,
                    })
                  }
                  rows={3}
                  className="w-full rounded-md border border-gray-300 px-3 py-2"
                />
              </div>
            </div>
            <div className="mt-6 flex justify-end gap-2">
              <button
                onClick={() => {
                  setEditorItem(null);
                  setEditorLang(null);
                }}
                className="rounded-md border px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handleSaveTranslation}
                className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                Save Translation
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
