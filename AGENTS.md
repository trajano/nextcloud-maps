# AGENTS.md

This file gives coding agents the minimum project context needed to work safely in `P:\nextcloud-maps`.

## Scope

- Applies to the entire repository.
- Keep changes focused on the requested task.
- Prefer behavioral stability over cleanup-only refactors.
- Do not rename or reorganize major directories unless the task explicitly requires it.

## Project overview

- This repository is a fork of `nextcloud/maps`.
- It is a Nextcloud app with a PHP backend and a Vue 2 frontend.
- Backend code lives under `lib/` and is exposed through `appinfo/routes.php`.
- Frontend source lives under `src/` and is bundled with webpack into the app assets.
- Templates and rendered entry points live under `templates/`.
- Static styles and images live under `css/` and `img/`.
- Localization files live under `l10n/`.
- PHPUnit coverage is split between `tests/Unit/` and `tests/Integration/`.

## Directory map

- `appinfo/`: app metadata, dependency injection wiring, and routes.
- `lib/Controller/`: HTTP controllers for private and public endpoints.
- `lib/Service/`: business logic used by controllers and background jobs.
- `lib/DB/`: database entities and mappers.
- `lib/BackgroundJob/`: asynchronous scan and update jobs.
- `lib\Command/`: `occ` commands for rescans and maintenance.
- `lib/Listener/` and `lib/Hooks/`: Nextcloud event integration.
- `src/components/`: Vue components, including map, sidebar, and routing UI.
- `src/store/`: Vuex store modules.
- `src/files-actions/`: Nextcloud Files integration actions.
- `templates/`: PHP templates that mount frontend entry points.
- `tests/test_files/`: GPX, image, and fixture data for tests.
- `screenshots/`: documentation assets only; do not update unless the task is documentation-focused.

## Working conventions

- Read `README.md` and nearby code before making assumptions about behavior.
- Follow existing naming and file placement patterns instead of introducing new structure.
- Keep controller changes thin; move reusable logic into services when behavior grows.
- Match the surrounding language style in each area of the codebase.
- Avoid broad formatting churn in legacy files.
- Update documentation only when the behavior, workflow, or contributor expectations changed.

## Build and test commands

- Install and build everything with `make`.
- Install PHP dependencies with `composer install --prefer-dist`.
- Install frontend dependencies with `npm ci`.
- Build frontend assets with `npm run build`.
- Use the development bundle with `npm run dev`.
- Run frontend lint checks with `npm run lint`.
- Run PHP unit tests with `composer run test:unit`.
- Run PHP integration tests with `composer run test:integration`.
- Run PHP static analysis with `composer run psalm`.
- Run PHP style fixes with `composer run cs:fix`.

## Change guidance

- For backend API changes, check controllers, services, routes, and tests together.
- For frontend changes, trace the entry point from `templates/` or `src/*.js` into the relevant Vue components and store modules.
- When adding user-facing text, update the English localization source expected by the surrounding code.
- Do not edit generated or vendored content unless the task specifically targets it.
- Keep `README.md`, `CONTRIBUTING.md`, and this file aligned when contributor workflow changes.

## Testing expectations

- Prefer adding or updating tests when changing behavior.
- Use `tests/Unit/` for isolated PHP logic and controller behavior.
- Use `tests/Integration/` when the change depends on Nextcloud integration details.
- Run `composer run cs:fix` when PHP changes need formatting before review or push.
- If a task only changes documentation, explain what was verified instead of inventing code tests.
- Before finishing, run the narrowest relevant checks that cover the change and report anything you could not run.

## Git and review hygiene

- Keep commits small and task-focused.
- Do not revert user changes outside the task scope.
- If the worktree contains unrelated changes, work around them rather than resetting them.
- Reference exact files and commands in summaries so follow-up work is easy.
