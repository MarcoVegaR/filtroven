# Skill Registry

Registry imported from the upstream baseline and adapted for `filtroven`.

## Resolution Rules

- Project-level skills override user-level skills with the same `name`.
- Excluded from this registry: `_shared`, `skill-registry`, and all `sdd-*` skills.
- Convention files are loaded alongside matched skills.

## Available Skills

| Name                        | Scope   | Path                                                              | Trigger / Description                                                                                                                                                     |
| --------------------------- | ------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `fortify-development`       | project | `.agents/skills/fortify-development/SKILL.md`                     | Use for Laravel Fortify headless authentication work: login, registration, password reset, email verification, 2FA, profile updates, guards, and auth flow customization. |
| `inertia-react-development` | project | `.agents/skills/inertia-react-development/SKILL.md`               | Use for Inertia React pages, forms, navigation, deferred props, polling, prefetching, and client-side page behavior.                                                      |
| `pest-testing`              | project | `.agents/skills/pest-testing/SKILL.md`                            | Use whenever writing, fixing, or refactoring Pest tests, including browser and architecture tests.                                                                        |
| `tailwindcss-development`   | project | `.agents/skills/tailwindcss-development/SKILL.md`                 | Use for Tailwind CSS v4 layout, styling, spacing, typography, responsive UI, and dark mode work.                                                                          |
| `wayfinder-development`     | project | `.agents/skills/wayfinder-development/SKILL.md`                   | Use when frontend code references Laravel routes via `@/actions`, `@/routes`, or Wayfinder helpers.                                                                       |
| `go-testing`                | user    | `/home/marco-vega/.config/opencode/skills/go-testing/SKILL.md`    | Use for Go test work, including Bubbletea TUI tests and table-driven coverage.                                                                                            |
| `skill-creator`             | user    | `/home/marco-vega/.config/opencode/skills/skill-creator/SKILL.md` | Use when creating a new agent skill, adding AI instructions, or documenting reusable agent workflows.                                                                     |

## Project Convention Files

| Path        | Purpose                                                                                                                                                                             |
| ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AGENTS.md` | Primary project operating guide: Laravel Boost rules, project architecture defaults, testing and formatting requirements, skill activation map, and Engram-first workflow guidance. |

## Project Context Snapshot

- Backend: Laravel 13 on PHP 8.5 with Fortify, Wayfinder, AI SDK, Boost, Sail, Pint, and Pail.
- Frontend: Inertia v2 + React 19 + TypeScript + Vite 8 + Tailwind CSS v4 + Radix UI primitives.
- Testing: Pest 4 with PHPUnit 12; feature tests use `RefreshDatabase` and SQLite in-memory.
- Quality: Pint for PHP, ESLint 9 for TS/React, Prettier 3 with Tailwind plugin, TypeScript `tsc --noEmit`.
- CI: GitHub Actions runs quality checks and build/Pest on push and pull requests to `main`; documentation is validated without publishing. Deployment is disabled pending Filtroven-specific configuration.

## Loading Protocol For Sub-Agents

1. Read this registry.
2. Load the skills whose triggers match the assigned task.
3. Read `AGENTS.md` for project conventions.
4. Then perform implementation, verification, or analysis.
