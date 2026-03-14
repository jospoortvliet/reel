# Reel

Reel is a Nextcloud app that automatically generates highlight videos from your photos and videos. It scans your media library, detects meaningful events (a trip, a party, a day out) by clustering photos by time and location, then renders a polished H.265 MP4 with Ken Burns effects, transitions, and music. The idea is that Memories handles your photo library — Reel turns those memories into something you'd actually watch.

## Usage

### Requirements

- Nextcloud 30 or later
- The [Memories](https://apps.nextcloud.com/apps/memories) app, installed and indexed
- FFmpeg 7.x installed on the server
- PHP Imagick extension (for HEIC/AVIF support)

Reel reads event and location data directly from Memories' database tables, so your photo library must be indexed by Memories before Reel can detect events.

---

### Getting started

#### 1. Detect events

Reel scans your photo library and groups your photos and videos into events — a trip, a weekend away, a birthday — based on time gaps and location changes. This happens automatically every night, but you can trigger it manually from the command line:

```bash
php occ reel:detect-events
```

To run detection for a specific user only:

```bash
php occ reel:detect-events --user=alice
```

To force a full rebuild (delete and recreate events/media for that user):

```bash
php occ reel:detect-events --user=alice --rebuild
```

Once detection has run, open Reel from the Nextcloud app menu. You'll see a list of detected events, each showing a cover photo, title, date, and the number of photos and videos it contains.

#### 2. Review your event

Click an event to open it. You'll see a grid of all the photos and videos that were included.

**Each thumbnail shows:**
- A white rounded rectangle overlay indicating the photo's orientation (landscape, portrait, or square) — visible on hover
- A **✓** badge (top-right, green) if the item is included in the reel, or a **✕** badge (red) if excluded
- A filled **▶** icon for video clips, or a filled **motion play** icon for Live Photos — outline versions indicate the item is currently excluded

**To preview a photo or video** at full size, click the image itself. It opens in the Nextcloud Viewer.

**To include or exclude an item**, click the ✓/✕ badge in the top-right corner of the thumbnail.

**For Live Photos**, a small icon appears in the bottom-left corner of the thumbnail. Click it to switch between using the still photo or the short video clip in your reel. Reel picks the best option automatically based on your output orientation setting, but you can override it per item.

The header shows how many items are currently included. You need at least two to render a video.

#### 3. Render your reel

Once you're happy with your selection, click **Generate video**. Rendering runs in the background — you'll see a progress bar update as each clip is processed.

Rendering time depends on the number of clips and your server hardware. A 20-photo event typically takes 1–3 minutes.

When rendering is complete, a **Watch Reel** button appears. Click it to open the finished video in the Nextcloud Viewer. The video file is also saved to a `Reels/` folder in your Nextcloud files, where you can download or share it like any other file.

---

### Settings

Open the settings panel via the **⚙** icon in the bottom-left of the navigation sidebar.

#### Output orientation

Choose the format of the generated video:

| Option | Aspect ratio | Best for |
|--------|-------------|----------|
| Landscape 16:9 | Widescreen | TV, desktop, YouTube |
| Portrait 9:16 | Vertical | Instagram Stories, TikTok, phone |
| Square 1:1 | Square | Instagram feed |

This setting also affects which Live Photos are used as video clips — a Live Photo shot in landscape will automatically use its video clip when you've chosen Landscape 16:9 output.

#### Duplicate detection

Reel automatically filters out near-duplicate photos from burst shooting before adding them to an event. Two sliders let you tune this behaviour:

**Burst gap** — photos taken within this many seconds of each other are candidates for deduplication. Increase it if you shoot in long bursts; decrease it if you want more photos kept. Default: 5 seconds.

**Visual similarity threshold** — how visually similar two photos must be (using perceptual hashing) to count as duplicates. Lower values are stricter and keep more photos; higher values are more aggressive and remove more. Default: 16.

When duplicates are found within a burst, Reel keeps the best one based on: face composition (if the Recognize app has processed your library), then sharpness, then the middle frame of the burst.

---

### Command line

For administrators and power users, Reel provides several `occ` commands.

**Detect events for all users:**
```bash
php occ reel:detect-events
```

**Detect events for one user:**
```bash
php occ reel:detect-events --user=alice
```

**Force full rebuild for one user** (use after major detection-logic changes):
```bash
php occ reel:detect-events --user=alice --rebuild
```

**Render a specific event** (get the event ID from the URL in the Reel interface):
```bash
php occ reel:render-event <event-id> <user-id>
```

Add `--debug` for verbose FFmpeg output:
```bash
php occ reel:render-event <event-id> <user-id> --debug
```

**Inspect duplicate detection** for an event without making any changes:
```bash
php occ reel:debug-duplicates <event-id> <user-id>
```

This prints a dry-run report showing which photos were identified as burst duplicates, which one would be kept, and why (face score, sharpness, or position).

## Development setup

You can contribute using the standard Nextcloud Docker setup (including Julius's dev image/workflow). A custom image is optional, not required.

### Quick local checks

From the app root:

```bash
composer install
npm ci
npm run build
vendor/bin/phpunit --configuration tests/phpunit.xml
```

### Running commands in a Nextcloud container

If your Nextcloud container is called `master-nextcloud-1`, run:

```bash
docker exec -u www-data -it master-nextcloud-1 php occ app:enable reel
docker exec -u www-data -it master-nextcloud-1 php occ reel:detect-events --user=<uid>
docker exec -u www-data -it master-nextcloud-1 php occ reel:render-event <event-id> <uid> --debug
```

### FFmpeg and Imagick

For Reel to fully work in a test instance, make sure FFmpeg 7.x and PHP Imagick are installed in the running Nextcloud environment.
If your base image does not include them, install them in your dev setup before testing rendering.


## Release workflow

This repository now includes an automated release workflow at [.github/workflows/release.yml](.github/workflows/release.yml).

### What it does

- Builds frontend assets (`npm ci && npm run build`)
- Installs production PHP dependencies (`composer install --no-dev`)
- Packages installable archives:
	- `reel-<version>.tar.gz`
	- `reel-<version>.zip`
- Generates checksums (`reel-<version>.sha256`)
- Uploads artifacts to the workflow run
- Publishes them to a GitHub Release when triggered by a tag (`v*`)
- Optionally signs/publishes to the Nextcloud App Store when release secrets are configured

### App Store signing and upload

If these repository secrets are present, the same release workflow also publishes the signed app to the Nextcloud App Store:

- `APPSTORE_TOKEN`: API token from apps.nextcloud.com
- `APP_PRIVATE_KEY`: private key for your app certificate

If the secrets are not set, the workflow still builds and publishes GitHub release assets, and simply skips the App Store step.

### How to cut a release

```bash
git checkout main
git pull
# bump versions + changelog
git commit -am "Release x.y.z"
git tag -a vX.Y.Z -m "vX.Y.Z"
git push
git push origin vX.Y.Z
```

Tag push triggers the workflow and publishes the release archives automatically.


## Technical overview

### Core infrastructure
- Full Nextcloud app scaffold: `appinfo/`, migrations, background jobs, OCS API, Vue 3 frontend
- Database tables: `oc_reel_events`, `oc_reel_event_media`, `oc_reel_jobs`
- `Application.php` bootstrap, registers background jobs
- `occ` commands: `reel:detect-events`, `reel:render-event`, `reel:debug-duplicates`

### Event detection
- Clusters photos into events by 6-hour time gaps and location changes
- Uses a rolling 6-hour gap between consecutive items and ignores clusters smaller than 6 media items
- Titles like "Barcelona · March 2026" using most-frequent place name
- Reads from Memories' database tables (`oc_memories`, place data)
- Nightly background job (`DetectEventsJob`) re-runs detection automatically

### Duplicate filtering
- Detects burst photos: same scene shot within N seconds AND visually similar (blurhash Hamming distance)
- Winner selection: face composition score (Recognize) → sharpness (Imagick Laplacian) → middle of burst
- Fully configurable thresholds per user
- `reel:debug-duplicates` command for dry-run inspection without touching the DB
- Live photos: the paired `.mov` is hidden from events — only the still is shown

### Video rendering
- Two-pass FFmpeg pipeline: normalize each clip to H.264 intermediate → concat to H.265 final
- Photos: 2.5s with Ken Burns zoom (100%→108%)
- Videos: capped at 8s
- HEIC/AVIF: converted via Imagick before FFmpeg
- Output: libx265, CRF 23, `-tag:v hvc1` (QuickTime compatible), faststart
- Renders to `Reels/` folder in user's Nextcloud files
- Async via `RenderJob` queued background job with progress tracking

### Live photo support
- Name-swap lookup finds the paired `.mov` (`photo.jpg` → `photo.mov`) via `oc_filecache`
- Auto-rule: use `.mov` when the still's orientation matches the output orientation
- User can override per-item with a toggle button in the UI
- Renderer falls back to the still if `.mov` not found

### Settings
- Burst gap and similarity threshold (duplicate detection tuning)
- Output orientation: Landscape 16:9 / Portrait 9:16 / Square 1:1

### Frontend (Vue 3 + @nextcloud/vue 9)
- Event list with cover thumbnails, media count, job status badges
- Event detail: media grid with include/exclude toggle (✓/✕ button top-right)
- Click thumbnail → opens in Nextcloud Viewer at full resolution
- Orientation frame overlay (white rounded rectangle, fades in on hover) showing portrait/landscape/square
- Media type icons: `play-circle` for video, `motion-play` for live photo; filled = included, outline = excluded
- Live photo toggle button (bottom-left): switch between still and `.mov` per item
- Async render button with progress bar and funny loading messages
- Completed video opens in Nextcloud Viewer
- HTML5 history routing — bookmarkable URLs, back button works
- Settings panel with sliders and orientation picker

---

## Roadmap / todo

- [x] **Test live photo rendering end-to-end** — the name-swap lookup is new, needs a real render run
- [x] **Incremental event detection** — currently clears and rewrites all events on each run, so event IDs change and any user customisations (excluded media, live photo toggles) are lost
- [x] **Face zoom in Ken Burns** — instead of always zooming to centre, zoom toward the detected face position from Recognize
- [x] **Live photo duration** — currently capped at `MAX_CLIP_DURATION` (8s); most live photos are 2–3s, should use actual duration from the `.mov` metadata
- [x] **Video duration UI** — backend now supports segment windows (start + length via `edit_settings`), and users can set clip timing in the event detail view
- [x] **Music** — bundled tracks in `assets/music/` and wired theme-based soundtrack selection in renders
- [x] **Theme picker UI** — theme selection is available in the event detail header and persists per event
- [x] **Portrait/square rendering** — FFmpeg filter chain now adapts output dimensions for 16:9, 9:16, and 1:1
- [x] **App Store prep** — metadata, screenshots, signing, release workflow
- [x] **Custom Dockerfile** — apt installs (FFmpeg, Imagick) don't survive container restart currently
- [x] **PHPUnit tests** — EventDetectionService, DuplicateFilterService, VideoRenderingService, ApiController
- [ ] put limits on the nr of items to limit length (and size) of videos
- [ ] auto-create videos (with some limits!)
- [ ] create/detect special types of events - like 'pets in 2025' or an entire vacation in a separate country, or a city trip.
- [ ] **Masonry/aspect-ratio thumbnails** — needs custom thumbnail generation since Nextcloud's preview API crops to square
- [ ] **Ultra-smooth motion pipeline** — animate on an upscaled working canvas first, then downscale to output at the end (best-quality approach to reduce subpixel jitter)

## Resources

- Repository: https://github.com/jospoortvliet/reel
- Issue tracker: https://github.com/jospoortvliet/reel/issues
- Discussions: https://github.com/jospoortvliet/reel/discussions