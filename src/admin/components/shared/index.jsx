export function LoadingSpinner({ className = "" }) {
  return (
    <div className={`flex items-center justify-center p-8 ${className}`}>
      <div className="h-8 w-8 animate-spin rounded-full border-4 border-gray-200 border-t-blue-600" />
    </div>
  );
}

const NAV_ITEMS = [
  { path: "polyglot", label: "Dashboard" },
  { path: "polyglot/languages", label: "Languages" },
  { path: "polyglot/translations", label: "Translations" },
  { path: "polyglot/string-translation", label: "String Translation" },
  { path: "polyglot/scan", label: "Scan" },
  { path: "polyglot/settings", label: "Settings" },
  { path: "polyglot/import-wpml", label: "Import WPML" },
];

export function PolyglotNav() {
  const currentHash = window.location.hash.replace("#/", "").split("?")[0];

  return (
    <nav className="mb-6 border-b border-gray-200">
      <div className="flex gap-1">
        {NAV_ITEMS.map((item) => {
          const isActive =
            item.path === "polyglot"
              ? currentHash === "polyglot"
              : currentHash === item.path ||
                currentHash.startsWith(item.path + "/");
          return (
            <a
              key={item.path}
              href={`#/${item.path}`}
              className={`border-b-2 px-4 py-2.5 text-sm font-medium transition-colors ${
                isActive
                  ? "border-blue-500 text-blue-600"
                  : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700"
              }`}
            >
              {item.label}
            </a>
          );
        })}
      </div>
    </nav>
  );
}

export function ErrorState({ message, onRetry, className = "" }) {
  return (
    <div
      className={`flex flex-col items-center justify-center p-8 text-center ${className}`}
    >
      <div className="mb-4 text-red-500">
        <svg
          className="mx-auto h-12 w-12"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"
          />
        </svg>
      </div>
      <p className="mb-4 text-gray-700">{message}</p>
      {onRetry && (
        <button
          onClick={onRetry}
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
        >
          Try Again
        </button>
      )}
    </div>
  );
}

export function ProgressBar({ value = 0, max = 100, className = "" }) {
  const percentage =
    max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;

  return (
    <div
      className={`h-2 w-full overflow-hidden rounded-full bg-gray-200 ${className}`}
    >
      <div
        className="h-full rounded-full bg-blue-600 transition-all duration-300"
        style={{ width: `${percentage}%` }}
      />
    </div>
  );
}

export function StatCard({ title, value, subtitle, icon, className = "" }) {
  return (
    <div className={`rounded-lg border bg-white p-4 shadow-sm ${className}`}>
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm font-medium text-gray-500">{title}</p>
          <p className="mt-1 text-2xl font-semibold text-gray-900">{value}</p>
          {subtitle && <p className="mt-1 text-sm text-gray-500">{subtitle}</p>}
        </div>
        {icon && <div className="text-gray-400">{icon}</div>}
      </div>
    </div>
  );
}

export function Toast({ message, type = "success", onClose }) {
  const bgColor = type === "success" ? "bg-green-500" : "bg-red-500";

  return (
    <div className="fixed bottom-4 right-4 z-50 animate-fade-in-up">
      <div
        className={`${bgColor} flex items-center rounded-lg px-4 py-3 text-white shadow-lg`}
      >
        <span>{message}</span>
        <button
          onClick={onClose}
          className="ml-4 text-white hover:text-gray-200"
        >
          ×
        </button>
      </div>
    </div>
  );
}
