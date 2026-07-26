import js from "@eslint/js";
import eslintReact from "@eslint-react/eslint-plugin";
import reactHooks from "eslint-plugin-react-hooks";
import simpleImportSort from "eslint-plugin-simple-import-sort";
import unusedImports from "eslint-plugin-unused-imports";
import globals from "globals";
import tseslint from "typescript-eslint";

export default tseslint.config(
  {
    ignores: [
      "**/node_modules/**",
      "**/vendor/**",
      "**/public/**",
      "**/storage/**",
      "**/bootstrap/cache/**",
      "training_data/**",
      "postcss.config.js",
      "vite.config.ts",
      "tailwind.config.ts",
      "jest.config.cjs",
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ["**/*.{ts,tsx}"],
    languageOptions: {
      ecmaVersion: 2020,
      globals: {
        ...globals.browser,
        ...globals.es2020,
      },
      // NOTE: `parserOptions.project` is deliberately absent.
      //
      // Setting it makes typescript-eslint build a full TypeScript program for every file,
      // which cost 3.28 GB peak RSS against 1.28 GB without it — on a 4 GB CI runner that is
      // what made `Frontend Static Checks` OOM intermittently (issue #1792), taking the whole
      // required gate down with it.
      //
      // Nothing consumed the type information. This config extends
      // `tseslint.configs.recommended`, NOT `recommended-type-checked`, and every configured
      // `@typescript-eslint` rule is "off". Verified by diffing `--format json` across the
      // whole repo with and without it: 1,906 files, 509 messages, byte-identical.
      //
      // Add it back ONLY together with a rule that needs types (anything from
      // `recommended-type-checked`, or an `@eslint-react` type-checked rule). Such a rule
      // fails loudly without a project — "was not found by the project service" — rather than
      // silently passing, so the absence is self-correcting when it stops being right.
    },
    plugins: {
      "@eslint-react": eslintReact,
      "react-hooks": reactHooks,
      "unused-imports": unusedImports,
      "simple-import-sort": simpleImportSort,
    },
    settings: eslintReact.configs["recommended-typescript"].settings,
    rules: {
      ...eslintReact.configs["recommended-typescript"].rules,
      ...reactHooks.configs.recommended.rules,
      "unused-imports/no-unused-imports": "error",
      "unused-imports/no-unused-vars": "off",
      "simple-import-sort/imports": "error",
      "simple-import-sort/exports": "error",
      "@typescript-eslint/no-unused-vars": "off",
      "@typescript-eslint/no-explicit-any": "off",
      "@typescript-eslint/no-require-imports": "off",
      "react-hooks/exhaustive-deps": "warn",
      "react-hooks/set-state-in-effect": "off",
    },
  },
  {
    files: ["**/*.{js,jsx,cjs,mjs}"],
    languageOptions: {
      globals: {
        ...globals.browser,
        ...globals.node,
        ...globals.es2020,
      },
    },
    plugins: {
      "unused-imports": unusedImports,
      "simple-import-sort": simpleImportSort,
    },
    rules: {
      "unused-imports/no-unused-imports": "error",
      "simple-import-sort/imports": "error",
      "simple-import-sort/exports": "error",
    },
  }
,
  {
    files: ["**/*.test.ts"],
    ignores: ["**/*.dom.test.ts"],
    rules: {
      "no-restricted-globals": ["error", "window", "document", "HTMLElement"],
      "no-restricted-imports": ["error", {
        patterns: ["@testing-library/*"]
      }],
    },
  },
  {
    // Tower Throwback directional rules must be map-relative.
    //
    // `floor < 0` (underground), `floor === 99` (cathedral) and friends are only
    // correct for a map that anchors at floor 0 and builds upward. They were
    // converted to the `engine/mapGeometry.ts` helpers; this stops the literals
    // coming back, because the next person to add a rule will reach for them and
    // a passing test suite will not notice.
    //
    // Scoped to the RULE modules. `grid.ts` / `heatmaps.ts` are excluded on
    // purpose: their FLOOR_MIN/FLOOR_MAX use is the grid STORAGE role — one
    // typed array spanning the global range with every map inside it — which is
    // global by construction. See engine/MAP_GEOMETRY_CENSUS.md.
    files: [
      "resources/js/games/tower-throwback/engine/placement.ts",
      "resources/js/games/tower-throwback/engine/schedules.ts",
      "resources/js/games/tower-throwback/engine/stars.ts",
      "resources/js/games/tower-throwback/engine/routing.ts",
      "resources/js/games/tower-throwback/engine/people.ts",
    ],
    rules: {
      "no-restricted-syntax": ["error",
        {
          selector: "BinaryExpression[operator=/^(===|!==|<|>|<=|>=)$/][right.value=0][left.property.name=/[Ff]loor/]",
          message: "Compare floors to the map anchor via engine/mapGeometry.ts (isAnchorFloor / isExcavated / isOnBuildSide), not to literal 0 — see engine/MAP_GEOMETRY_CENSUS.md.",
        },
        {
          selector: "BinaryExpression[operator=/^(===|!==)$/][right.value=99][left.property.name=/[Ff]loor/]",
          message: "Floor 99 is CITY_TOWER's floorRange.max, not a universal constant — use terminalFloor(map).",
        },
        {
          selector: "BinaryExpression[operator=/^(===|!==)$/][left.property.name=/[Ff]loor/] > UnaryExpression[operator='-'][argument.value=10]",
          message: "Floor -10 is CITY_TOWER's floorRange.min, not a universal constant — use excavationExtreme(map).",
        },
      ],
    },
  },
  {
    // Final-visit logic is map-defined. Exact cathedral kind checks here would
    // silently re-specialize New York and strand Niagara's Observation Deck.
    files: [
      "resources/js/games/tower-throwback/engine/placement.ts",
      "resources/js/games/tower-throwback/engine/vip.ts",
      "resources/js/games/tower-throwback/gameProgress.ts",
    ],
    rules: {
      "no-restricted-syntax": ["error", {
        selector: "Literal[value='cathedral']",
        message: "Use MapDefinition.endgameItem or catalog overhang metadata; final structures are map-specific.",
      }],
    },
  });
