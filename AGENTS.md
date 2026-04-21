# Project Guidelines

## Scope
These instructions apply to the entire Reel app.

## Architecture
- Reel is a Nextcloud app. Backend code lives in `lib/` under the `OCA\Reel\` namespace. Frontend code lives in `src/` and is built with Vite.
- Keep business logic in services under `lib/Service/`. Keep controllers under `lib/Controller/` thin and focused on request/response handling.
- Backgroundjobs go in `/lib/BakgroundJob/`. DetectEventsJob runs nightly to add new events to the database while RenderJob runs every cron invocation to check for rendering jobs and kick them off.
- Detection of events (from the EventDetectionService) works as follows:
    1. Load all indexed media for the user from Memories (`oc_memories`), sorted by time. Enrich each item with tags, face-cluster data, and a place hierarchy (country / region / city).
    2. `clusterIntoEvents` splits media into timeline clusters by time gaps (>6 h triggers a new event) and significant location changes.
    3. Detect derived / special events on top of the timeline clusters: trip events (short ≤4 days, long ≤28 days), per-person year summaries, per-pet year summaries, year-in-review, seasonal, and tradition events.
    4. Timeline clusters that are ≥90 % covered by a trip event are suppressed so the same media is not shown twice.
    5. Before writing back to the database, low-quality and near-duplicate media are removed using the DistinctFilterService, UtilityFilterService and DuplicateFilterService. Then TriptychOptimizerService selects/de-selects items to aim for a reasonable video length, favouring items with faces and excluding receipts or document scans.
    6. Results are written to `reel_events` and `reel_event_media`. Incremental runs update existing rows in place (preserving user edits); full rebuilds drop and recreate them.
- **MusicService** (`lib/Service/MusicService.php`): resolves background music for rendered videos. It provides built-in genre presets (`indie_pop`, `acoustic_folk`, `cinematic_orchestral`) and discovers user-supplied audio files from a configurable folder in Nextcloud (supported extensions: mp3, wav, aac, flac, ogg, m4a, opus). The file list is cached in user config and refreshed when the folder setting changes or during the nightly background job. `getMusicOptions()` returns the full list of choices; `setCustomMusicFolderPath()` validates the folder and refreshes the cache.
- **RenderJobService** (`lib/Service/RenderJobService.php`): creates and tracks render jobs. `enqueue(eventId, userId)` inserts a row into `reel_jobs` with status `pending` and registers a `RenderJob` background job. `getLatestForEvents(eventIds, userId)` batch-loads the most-recent job per event (used by `ApiController` to return progress to the frontend). `getJob()` and `getLatestForEvent()` fetch individual job rows. The service does not do any rendering itself — it only manages job metadata and queuing.
- **Controllers** (`lib/Controller/`):
    - `PageController` — serves the Vue SPA at `/apps/reel/` and `/apps/reel/events/{id}`. Dispatches `LoadViewer` so the Nextcloud Viewer sidebar is available. All routes return the same `index` template (HTML5 history mode).
    - `ApiController` — OCS REST controller for all data operations: listing/updating events, fetching event media, enqueuing render jobs, polling job status, and listing music options. Depends on `EventDetectionService`, `RenderJobService`, and `MusicService`.
    - `SettingsController` — OCS REST controller for per-user preferences: `GET/PUT /api/v1/settings` persisting `similarity_threshold`, `burst_gap_seconds`, `output_orientation`, `auto_create_videos`, and the custom music folder path. Delegates music folder changes to `MusicService`.
- **Notifier** (`lib/Notification/Notifier.php`): Nextcloud notification handler. Handles a single subject `video_ready` — formats a push notification ("Your Reel video is ready: <title>") with a deep link to the event page and the app icon. Fired by `RenderJob` when a render completes and `notify_on_done` is set.
- Reel depends on the Memories app for indexed media metadata. Treat `oc_memories*` tables as read-only inputs and `reel_*` tables as Reel-owned state.
    - **`reel_events`**: one row per detected event. Columns: `id`, `user_id`, `title`, `date_start`/`date_end` (Unix timestamps), `location`, `theme`, `motion_style`, `video_file_id` (Nextcloud file ID of the rendered video, nullable), `event_kind` (e.g. `timeline`, `trip_short`, `trip_long`, `person_year`, `pets_year`, `year_review`, `season`, `tradition`), `event_key` (stable slug for derived events — used for upserts on incremental detection runs), `parent_event_id` (nullable — links trip sub-events back to their trip), `created_at`, `updated_at`.
    - **`reel_event_media`**: one row per file belonging to an event. Columns: `id`, `event_id`, `user_id`, `file_id` (Nextcloud file ID), `included` (0/1 — TriptychOptimizer / user can de-select), `sort_order`, `edit_settings` (JSON blob for per-clip user overrides).
    - **`reel_jobs`**: one row per render job. Columns: `id`, `event_id`, `user_id`, `status` (`pending` / `running` / `done` / `error`), `progress` (0–100), `error` (nullable text), `created_at`, `updated_at`.
- Preserve stable event identity for derived events by using `event_kind` and `event_key` consistently.

## Code Style
- Match existing style and keep changes narrow. Do not refactor unrelated areas.
- Keep PHP files `declare(strict_types=1);` and prefer constructor injection with explicit types.
- Use Nextcloud's query builder for database access. Avoid raw SQL unless there is a clear reason.
- In Vue code, follow the existing Vue 3 + TypeScript patterns in `src/` and use `@nextcloud/vue` components where possible.
- Prefer fixing the root cause over adding defensive branches that hide the issue.

## Generated And Vendor Files
- Do not hand-edit generated assets in `js/`, `css/`, or `build/`. Edit source files in `src/` and rebuild.
- Do not edit dependency directories such as `vendor/`, `vendor-bin/*/vendor/`, or `node_modules/`.
- Do not manually edit `openapi.json`; regenerate it when API annotations change.

## Build And Test
- Frontend: `npm run build`, `npm run lint`, `npm run stylelint`
- Backend: `composer lint`, `composer cs:check`, `composer psalm`, `composer test:unit`
- If a change affects API annotations or routes, run `composer openapi`.
- For runtime verification in a Nextcloud dev instance, use `php occ reel:detect-events` or `php occ reel:render-event <event-id> <user-id> --debug`.

## Repo Conventions
- Event detection logic belongs in `EventDetectionService`; rendering pipeline changes belong in `VideoRenderingService`.
- Timeline events may be de-duplicated or absorbed by derived events; preserve those distinctions when changing detection rules.
- Keep logs actionable and concise. Include IDs and context needed to debug jobs or event matching.
- Prefer preserving user edits and stable IDs during incremental sync paths.
- When touching media handling, account for Live Photos, remote storage, and FFmpeg/Imagick constraints already handled in services.

## Practical Rules For Agents
- Before editing, check whether the change should be made in backend PHP, frontend Vue/TS, or both.
- When fixing warnings or static-analysis issues, remove dead code instead of silencing diagnostics unless the code is intentionally reserved.
- Validate the smallest relevant set of checks for the change instead of running unrelated heavy workflows.
- Do not create extra documentation files unless requested.