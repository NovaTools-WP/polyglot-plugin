import React, { useState, useEffect } from "react";
import { api } from "@/admin/lib/api";
import {
  LoadingSpinner,
  ErrorState,
  ProgressBar,
  StatCard,
} from "@/admin/components/shared";

export default function Dashboard() {
  const [languages, setLanguages] = useState([]);
  const [providerCount, setProviderCount] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchData = async () => {
    setLoading(true);
    setError(null);
    try {
      const [langsData, settingsData] = await Promise.all([
        api.get("/languages?active_only=true"),
        api.get("/settings").catch(() => null),
      ]);
      setLanguages(langsData);

      if (settingsData?.translation_api) {
        const api_keys = settingsData.translation_api;
        const count = [
          api_keys.deepl_key,
          api_keys.google_key,
          api_keys.openai_key,
        ].filter(Boolean).length;
        setProviderCount(count);
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

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorState message={error} onRetry={fetchData} />;

  const activeLanguages = languages.filter((l) => l.is_active);
  const defaultLang = languages.find((l) => l.is_default);

  const avgContentPercentage =
    activeLanguages.length > 0
      ? Math.round(
          activeLanguages.reduce(
            (sum, l) => sum + (l.content_percentage || 0),
            0,
          ) / activeLanguages.length,
        )
      : 0;
  const avgStringsPercentage =
    activeLanguages.length > 0
      ? Math.round(
          activeLanguages.reduce(
            (sum, l) => sum + (l.strings_percentage || 0),
            0,
          ) / activeLanguages.length,
        )
      : 0;

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-gray-900">
        Polyglot Dashboard
      </h1>

      <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="Active Languages"
          value={activeLanguages.length}
          subtitle={defaultLang ? `Default: ${defaultLang.english_name}` : ""}
        />
        <StatCard
          title="Content Translation"
          value={`${avgContentPercentage}%`}
          subtitle="Posts & pages translated"
        />
        <StatCard
          title="String Translation"
          value={`${avgStringsPercentage}%`}
          subtitle="Theme & plugin strings"
        />
        <StatCard
          title="API Providers"
          value={providerCount}
          subtitle={
            providerCount > 0 ? "Configured" : "No providers configured"
          }
        />
      </div>

      <div className="rounded-lg border bg-white shadow-sm">
        <div className="border-b px-4 py-3">
          <h2 className="text-lg font-semibold text-gray-900">
            Language Status
          </h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b bg-gray-50 text-left text-sm font-medium text-gray-500">
                <th className="px-4 py-3">Language</th>
                <th className="px-4 py-3">Content</th>
                <th className="px-4 py-3">Strings</th>
                <th className="px-4 py-3">Needs Update</th>
                <th className="px-4 py-3">Overall</th>
              </tr>
            </thead>
            <tbody>
              {languages.map((lang) => (
                <tr key={lang.code} className="border-b last:border-b-0">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span className="font-medium text-gray-900">
                        {lang.english_name}
                      </span>
                      {lang.is_default && (
                        <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                          Source
                        </span>
                      )}
                    </div>
                    <span className="text-sm text-gray-500">{lang.code}</span>
                  </td>
                  <td className="px-4 py-3">
                    {lang.is_default ? (
                      <span className="text-sm text-gray-500">
                        Source language
                      </span>
                    ) : (
                      <div className="flex items-center gap-2">
                        <ProgressBar value={lang.content_percentage || 0} />
                        <span className="text-sm text-gray-600">
                          {lang.content_percentage || 0}%
                        </span>
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    {lang.is_default ? (
                      <span className="text-sm text-gray-500">
                        Source language
                      </span>
                    ) : (
                      <div className="flex items-center gap-2">
                        <ProgressBar value={lang.strings_percentage || 0} />
                        <span className="text-sm text-gray-600">
                          {lang.strings_percentage || 0}%
                        </span>
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-sm text-gray-600">
                      {lang.needs_update || 0}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <ProgressBar value={lang.overall_percentage || 0} />
                      <span className="text-sm text-gray-600">
                        {lang.overall_percentage || 0}%
                      </span>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
