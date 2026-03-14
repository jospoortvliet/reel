# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Change setting for video style to be per-event

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
