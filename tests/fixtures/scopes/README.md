# Scope-resolution fixtures

## `frontend-errors.js.map`

Source map produced by Vite (@flareapp/vite) for the React playground in
`spatie/flare-laravel-test` at commit-time April 2026. The single source file
is `resources/js/frontend-errors.jsx` with handlers named
`thirdLevelExplode`, `secondLevelCompute`, `DeeplyNestedTrigger`,
`AsyncPromiseTrigger`, `ManualReportTrigger`, and `RenderCrashChild` — chosen
to exercise every shape the walk-back needs to recognise (function
declarations, `const NAME = () => { … }`, `const NAME = async () => { … }`,
nested arrows inside a component, and JSX components).

Known generated positions used by the feature tests:

| bundle (line, col) | throws                         | expected innermost scope    |
|--------------------|--------------------------------|-----------------------------|
| `(1, 555)`         | `null.shouldNotExist(payload)` | `thirdLevelExplode`         |
| `(1, 594)`         | call to `thirdLevelExplode`    | `secondLevelCompute`        |
| `(1, 627)`         | call to `secondLevelCompute`   | `onClick` (in `DeeplyNestedTrigger`) |
| `(1, 828)`         | `JSON.parse(...)` after await  | `onClick` (in `AsyncPromiseTrigger`) |
| `(1, 1194)`        | `items.map(...)`               | `RenderCrashChild`          |
