# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Video opening: title and date overlay displayed for 2.5 s at the start of each reel, positioned in the bottom third of the frame (works for both landscape and portrait).
- Video closing: 3-second fade-to-black outro applied to all reels; last still image is extended to a minimum of 4 s so the fade has room; timeline is freeze-frame extended when needed, with at least 1 s of unobstructed viewing before the fade begins.

### Fixed

- Startup/runtime: removed invalid `registerCommand()` calls from app bootstrap registration; commands continue to be declared via `appinfo/info.xml`.
- DX: resolved current Intelephense diagnostics by aligning `NcButton` prop usage in settings UI and by adding lightweight local type stubs for optional Imagick symbols used by analyzer tooling.
- Tests/bootstrap: switched optional Doctrine/OC symbol guards to string-based checks to avoid unresolved-type static-analysis noise.

## [1.1.8] - 2026-03-21

### Changed

- Music selection switched from legacy themes to genre-based tracks (`acoustic_folk`, `indie_pop`, `cinematic_orchestral`) with randomized per-render track choice.
- Settings/UI cleanup: removed legacy motion-style engine controls and related backend settings paths to keep a single, consistent motion pipeline.
- Rendering debug mode now preserves temp render directories on successful runs as well, making FFmpeg/intermediate inspection easier.

### Fixed

- Fixed live-photo clip resolution by preferring `memories.liveid` matching and adding robust filename fallback (`.mov`/`.mp4`, mixed case).
- Fixed UI live-photo affordances: when no actual live-video sibling exists, the API now suppresses live toggle/icon behavior.
- Fixed multiple audio-chain issues in transition renders: corrected filter pad reuse with `asplit`, stabilized timeline handling, and aligned clip audio trimming with transition overlap.
- Fixed missing natural audio on later clips and end-of-video silence regressions in multi-clip renders.
- Improved audio transitions with gentle clip-to-clip fades and rebalanced natural-vs-music mix toward a more even blend.
- Fixed regenerate UX: starting a new render now immediately switches to in-progress state and clears stale “video ready” state until the new render completes.

## [1.1.7] - 2026-03-15

### Changed

- Removed alternate rendering mode to focus on one way of animating videos

### Performance

- `DuplicateFilterService::markExcluded()` now issues a single batch `UPDATE … WHERE file_id IN (…)` instead of one UPDATE per duplicate.
- `DuplicateFilterService::detectBursts()` now reads the burst-gap and similarity-threshold config values once before entering the loop instead of on every iteration.
- `EventDetectionService::syncEventMedia()` now wraps all INSERTs/UPDATEs in a single DB transaction and batches row deletions via `DELETE … WHERE id IN (…)` instead of one query per row.
- `EventDetectionService::insertEventWithMedia()` now wraps all media INSERTs for a new event in a single DB transaction.
- `EventDetectionService::persistClustersIncremental()` now uses a hash-set (`isset`) for already-matched event IDs instead of `in_array`, reducing O(n²) to O(n).
- New migration `Version1002` adds composite DB indexes for the main hot paths: `reel_jobs(event_id, user_id, created_at)`, `reel_event_media(event_id, user_id)`, `reel_event_media(event_id, included, sort_order)`, and `reel_events(user_id, date_start)`.
- Same migration also creates the `reel_jobs` table declaratively (with a `hasTable` guard) so fresh installs no longer rely on out-of-band table creation.

## [1.1.6] - 2026-03-15

### Fixed

- Security: `updateEventVideoFileId()` in `VideoRenderingService` was missing a `user_id` WHERE clause, allowing a race-condition path to update another user's event record. Added the ownership check.
- Security: `ApiController` and `SettingsController` methods now return HTTP 401 when `$userId` is null (defensive guard; framework normally prevents unauthenticated calls reaching these endpoints).
- Robustness: `cleanupTempDir()` called `foreach` on the return value of `glob()`, which returns `false` on error instead of an empty array, causing a TypeError. Added `?: []` fallback.
- Robustness: `buildOutputFilename()` now caps the slug at 80 characters to prevent filenames that exceed the OS 255-byte limit for very long event titles.
- Robustness: The temporary render directory now uses `uniqid('', true)` instead of `time()` as its suffix, eliminating the collision risk when two renders start within the same second.
- Robustness: FFmpeg stderr output is now truncated to 4 000 characters before being written to the `error` column, preventing DB write failures caused by oversized values.
- Performance: `listEvents()` previously fired one job-status query per event (N+1). Added `RenderJobService::getLatestForEvents()` to batch-load all job statuses in a single query.
- Correctness: `updateEvent()` now returns the current event unchanged (without touching `updated_at`) when no fields are supplied, instead of firing a no-op UPDATE.
- Docs: README corrected minimum Nextcloud version from 34 to 30.

## [1.1.5] - 2026-03-14

### Fixed

- PostgreSQL/SQLite: `listEvents()` used a correlated subquery with backtick identifier quoting (MySQL-only syntax) and `LIMIT 1` inside the subquery. Replaced with a portable batch-fetch of cover file IDs in PHP after the main query.
- SQLite: `findLiveVideoFileId()` used `CONCAT()` and `LEFT()` which are not available in SQLite. Replaced with two simple queries using PHP-side string manipulation for the filename swap.

## [1.1.4] - 2026-03-14

### Fixed

- PostgreSQL: `UNIX_TIMESTAMP(mem.datetaken)` is MySQL-specific. Changed to select `mem.epoch` directly (Memories stores it as a plain integer Unix timestamp).
- PostgreSQL: `JSON_UNQUOTE(JSON_EXTRACT(...))` used to extract blurhash from `oc_files_metadata` is MySQL-specific. Now selects the raw `json` column and parses it in PHP.
- PostgreSQL: `MemoriesRepository::loadPlaceNames()` sent all file IDs as a single `IN (...)` bind array, hitting the 65535-parameter limit for users with large libraries. Now chunked into batches of 1000 (same fix as 1.1.3 for EventDetectionService).

## [1.1.3] - 2026-03-14

### Fixed

- PostgreSQL: `PARAM_BOOL` passed `"t"`/`"f"` to the `included` column (SMALLINT), causing `invalid input syntax for type smallint` on event detection. Changed to `PARAM_INT` with explicit `1`.
- PostgreSQL: event detection failed for users with large libraries (`number of parameters must be between 0 and 65535`) because place-name lookup used a single `IN (...)` with one bind parameter per file. Now chunked into batches of 1000.

## [1.1.2] - 2026-03-14

### Fixed

- Installation failure on Nextcloud 30–33: migration used `BOOLEAN NOT NULL` which triggers a schema validator error on those versions. Changed to `SMALLINT` (functionally identical).

### Changed

- Lowered `min-version` from 34 to 30. The app has no API dependency on NC 33 or 34; it works on NC 30 and newer, including NC 32 (AIO) and NC 33.

## [1.1.1] - 2026-03-14

### Added

- Automated GitHub release packaging workflow for installable artifacts (`reel-<version>.zip` and `reel-<version>.tar.gz`).
- Automated PHPUnit workflow for pull requests and pushes to `main`.
- Optional Nextcloud App Store publish step in release workflow (enabled when signing secrets are configured).

### Changed

- Release automation now triggers on both tag pushes and GitHub release publication.
- Added guardrails to ignore local certificate/private key files in Git.
- This release includes install-ready archives you can actually download and install.

## [1.1.0] - 2026-03-14

### Added

- Unit tests for renderer orientation/window command building behavior.
- Unit tests for API job payload formatting.
- Unit tests for event clustering gap behavior and minimum event size.

### Changed

- App metadata now includes website, repository, documentation, discussions, and issue tracker links for App Store readiness.
- Event detection now requires at least 6 media items per cluster before creating a reel event.
- Event detection now ignores fast place-label flicker and only splits on location changes after a meaningful pause.
- Event detection no longer treats weak or tied place majorities as strong enough to force a location split.
- Apple-style photo rendering now keeps the full image on screen with a soft blurred background instead of hard center-cropping on aspect mismatches.
- Motion style selection is now per-event.

## [1.0.0] - 2026-03-14

### Added

- Initial public release of Reel for Nextcloud 34.
- Automatic event detection from Memories metadata (time and location clustering).
- Duplicate/burst filtering with configurable thresholds.
- Reel rendering pipeline (Ken Burns photos, transitions, soundtrack, H.265 output).
- Live Photo support with still/video pairing and per-item overrides.
- User-facing controls for include/exclude, output orientation, and clip timing.
- Theme-based music selection and orientation-aware rendering (16:9, 9:16, 1:1).
- OCC commands for detection, rendering, and duplicate-debug inspection.
