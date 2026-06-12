import { v4wp } from "@kucrut/vite-for-wp";
import react from "@vitejs/plugin-react";
import path from "path";

export default {
	plugins: [
		v4wp({
			input: {
				addon: "src/admin/addon-entry.jsx",
			},
			outDir: "assets/admin/dist",
		}),
		react({
			jsxRuntime: "classic",
		}),
	],
	resolve: {
		alias: [
			{
				find: "react/jsx-runtime",
				replacement: path.resolve(
					__dirname,
					"./src/admin/externals/react-jsx-runtime.js",
				),
			},
			{
				find: "react-dom/client",
				replacement: path.resolve(
					__dirname,
					"./src/admin/externals/react-dom-client.js",
				),
			},
			{
				find: "react-dom",
				replacement: path.resolve(
					__dirname,
					"./src/admin/externals/react-dom.js",
				),
			},
			{
				find: "react",
				replacement: path.resolve(
					__dirname,
					"./src/admin/externals/react.js",
				),
			},
			{
				find: "@",
				replacement: path.resolve(__dirname, "./src"),
			},
		],
	},
};
