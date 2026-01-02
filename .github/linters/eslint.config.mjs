import js from "@eslint/js";
import globals from "globals";

export default [
    js.configs.recommended,
    {
        files: ["**/*.js"],
        languageOptions: {
            ecmaVersion: 12,
            sourceType: "module",
            globals: {
                ...globals.browser,
                ...globals.es2021
            }
        },
        rules: {
        }
    }
];
