# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
