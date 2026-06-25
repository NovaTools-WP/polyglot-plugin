import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

/**
 * Vitest configuration for the Polyglot admin SPA shared components.
 *
 * Separate from vite.admin.config.js (the production admin build) so the test
 * environment can pull in jsdom and the jest-dom matchers without affecting
 * the shipped bundle.
 */
export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./tests/js/setup.js"],
    include: ["src/**/*.test.{js,jsx}"],
  },
});
