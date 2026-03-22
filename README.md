# Reel

Reel is a Nextcloud app that automatically generates highlight videos from your photos and videos. It scans your media library, detects meaningful events (a trip, a party, a day out) by clustering photos by time and location, then renders a polished H.265 MP4 with Ken Burns effects, transitions, and music. It builds on (and requires) the Memories app. The idea is that Memories handles your photo library — Reel turns those memories into something easy, fun and quick to watch!

## Features

* Automatically detects events based on time, location and image tags from Recognize
* Manual or automatic creation of videos for each event
* Videos have a title and various effects
* Automatically detects and excludes duplicate pictures
* Automatically detects live photos (Heic + mov file)
* Videos have music (blended with audio from videos/live photos if included)
* Allows choosing horizontal, vertical or square video generation
* Allows choosing music per event, including from your own music selection
* Allows including/excluding media per event
* Allows choosing clip or photo for live photos
* Allows choosing what part of a video to include

**Note:** Reel gets its media from the Memories index (oc_memories), so available photos and videos are limited to what is indexed by Memories. If you encounter media you don't want to be included, check the Media indexing settings of Memories. You can place a  ".nomedia" or a ".nomemories" file in a folder to stop it from getting indexed, as well as add the folder or file type to a regular expression. Last but not least, you could enable `Index per-user timeline folders` which only indexes each users' selected media folder.

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
- A **✓** badge (top-right) if the item is included in the reel, or a **✕** badge if excluded
- A filled **camera** icon for video clips, or a filled **play** icon for Live Photos — outline versions indicate the item is currently excluded

**To preview a photo or video** at full size, click the image itself. It opens in the Nextcloud Viewer.

**To include or exclude an item**, click the ✓/✕ badge in the top-right corner of the thumbnail. Reel will have attempted to detect duplicate photos/videos as well as boring ones and excluded these.

**For Live Photos**, a small icon appears in the bottom-left corner of the thumbnail. Click it to switch between using the still photo or the short video clip in your reel. Reel picks using the video clip by default. Note that video clips shorter than 1.2 seconds are excluded as these would show and disappear too quickly in a video.

The header shows how many items are currently included. You need at least two to render a video.

**To include other media**, click the **Add media** button on the top-right and select other videos or images you like to include.

**To pick music for the video**, check the **soundtrack** list on top left. Reel has automatically selected a song. In the settings (in the left sidebar) you can add a folder with your own music to choose from.

**To change the name of the video**, click the **pen icon** right from the name to change it.

#### 3. Render your reel

Once you're happy with your selection, click **Generate video**. Rendering runs in the background — you'll see a progress bar update as each clip is processed.

Rendering time depends on the number of clips and your server hardware. A 20-photo event typically takes 1–3 minutes. Note that Rendering only starts when background jobs get executed - this can be limited to only once every 5 minutes, for example, so this can take a while.

When rendering is complete, a **Play video** button appears. Click it to open the finished video in the Nextcloud Viewer. The video file is also saved to a `Reels/` folder in your Nextcloud files, where you can download or share it like any other file.

---

### Settings

Open the settings panel via the **Settings** button in the the navigation sidebar. Settings changes are saved automatically.

### Music selection
Set a folder in your files that contains your own soundtrack files. Reel scans this folder recursively and adds supported audio files (mp3, wav, aac, flac, ogg, m4a, opus) to the song picker.

#### Video output

Choose the orientation of the generated video:

| Option | Aspect ratio | Best for |
|--------|-------------|----------|
| Landscape 16:9 | Widescreen | TV, desktop, YouTube |
| Portrait 9:16 | Vertical | Instagram Stories, TikTok, phone |
| Square 1:1 | Square | Instagram feed |

#### Automation

Optionally generate videos automatically after nightly event detection. Reel queues at most 3 new videos per run.

#### Duplicate detection

Reel automatically filters out near-duplicate photos from burst shooting before adding them to an event. Two sliders let you tune this behaviour:

**Burst gap** — photos taken within this many seconds of each other are candidates for deduplication. Increase it if you shoot in long bursts; decrease it if you want more photos kept. Default: 5 seconds.

**Visual similarity threshold** — how visually similar two photos must be (using perceptual hashing) to count as duplicates. Lower values are stricter and keep more photos; higher values are more aggressive and remove more. Default: 16.

When duplicates are found within a burst, Reel keeps the best one based on: face composition (if the Recognize app has processed your library), then sharpness, then the middle frame of the burst.

Reel will also attempt to keep the length of a video to about 65 media. For this, it will progressively disable more visually similar images, and try to filter out 'boring' images that contain a lot of text or are tagged with things like 'document'.

### 

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
- Titles like "Barcelona · Dining · March 2026" using the most-frequent place name plus the dominant tag when that tag appears on at least 30% of the event's media.
- Also generates a number of special events: trips (longer or shorter holidays that have a consistent location, deduplicated with the time-based events), pets, yearly overviews, season overviews and more.
- Reads from Memories' database tables (`oc_memories`, place data)
- Nightly background job (`DetectEventsJob`) re-runs detection automatically

### Duplicate filtering
- Detects burst photos: same scene shot within N seconds AND visually similar (blurhash Hamming distance)
- Winner selection: face composition score (Recognize) → sharpness (Imagick Laplacian) → middle of burst
- Fully configurable thresholds per user
- `reel:debug-duplicates` command for dry-run inspection without touching the DB
- Also has logic to detect 'boring' images based on tags & detected text
- And has logic to re-enable duplicate or similar images when their orientation would result in a tryptich effect
- Targets a video length of ~65 media in events with more than 15 media items (using a logarithmic scale), using visial and temporal similarity to pick the most interesting media to include
- Live photos: the paired `.mov` is hidden from events — only the still is shown

### Video rendering
- Two-pass FFmpeg pipeline: normalize each clip to H.264 intermediate → concat to H.265 final
- Photos: 2.5s with soft Ken Burns like effects (100%→108%), occasional triptych effect when 3 successive images are of the opposite orientation of the video
- Videos: capped at 8s by default (can be configured by the user)
- HEIC/AVIF: converted via Imagick before FFmpeg
- Output: libx265, CRF 23, `-tag:v hvc1` (QuickTime compatible), faststart
- Renders to `Reels/` folder in user's Nextcloud files
- Async via `RenderJob` queued background job with progress tracking, optimized to limit memory usage to ~2gb of ram (WILL exceed that when creating VERY large videos)

### Live photo support
- Name-swap lookup finds the paired `.mov` (`photo.jpg` → `photo.mov`) via `oc_filecache`
- Excludes as live photo when the `.mov` is less than 1.2 sec
- User can override per-item with a toggle button in the UI
- Renderer falls back to the still if `.mov` not found

### Settings
- Burst gap and similarity threshold (duplicate detection tuning)
- Output orientation: Landscape 16:9 / Portrait 9:16 / Square 1:1
- Music folder selection
- Auto-generate up to 3 videos

### Frontend (Vue 3 + @nextcloud/vue 9)
- Event list with cover thumbnails, media count, job status badges
- Event detail: media grid with include/exclude toggle (✓/✕ button top-right)
- Sub-events in larger (trip) events
- Click thumbnail → opens in Nextcloud Viewer at full resolution
- Orientation frame overlay (white rounded rectangle, fades in on hover) showing portrait/landscape/square
- Media type icons: `play-circle` for video, `motion-play` for live photo; filled = included, outline = excluded
- Live photo toggle button (bottom-left): switch between still and `.mov` per item
- Async render button with progress bar and funny loading messages
- Completed video opens in Nextcloud Viewer
- HTML5 history routing — bookmarkable URLs, back button works
- Music selection with search and custom music folder per video (random music selected by default)
- Settings panel with sliders and orientation picker

---

## Roadmap / todo

- [x] **Test live photo rendering end-to-end** — the name-swap lookup is new, needs a real render run
- [x] **Incremental event detection** — currently clears and rewrites all events on each run, so event IDs change and any user customisations (excluded media, live photo toggles) are lost
- [x] **Face zoom in Ken Burns** — instead of always zooming to centre, zoom toward the detected face position from Recognize
- [x] **Live photo duration** — currently capped at `MAX_CLIP_DURATION` (8s); most live photos are 2–3s, should use actual duration from the `.mov` metadata
- [x] **Video duration UI** — backend now supports segment windows (start + length via `edit_settings`), and users can set clip timing in the event detail view
- [x] **Music** — bundled tracks in `assets/music/` and wired genre-based soundtrack selection in renders
- [x] **Music genre picker UI** — genre selection is available in the event detail header and persists per event
- [x] **Portrait/square rendering** — FFmpeg filter chain now adapts output dimensions for 16:9, 9:16, and 1:1
- [x] **App Store prep** — metadata, screenshots, signing, release workflow
- [x] **Custom Dockerfile** — apt installs (FFmpeg, Imagick) don't survive container restart currently
- [x] **PHPUnit tests** — EventDetectionService, DuplicateFilterService, VideoRenderingService, ApiController
- [x] Adress some rendering bugs
- [x] Improve the video opening and closing
- [x] Switch short live clips to image
- [x] Refactor default settings into the initial detection run
- [x] Support user-provided music folder
- [x] choose music automatically (at random...)
- [x] put limits on the nr of items to limit length (and size) of videos
- [x] auto-create videos (with some limits!)
- [x] inline event rename
- [x] improve progress calculation
- [x] add notifications on video completion
- [x] improve naming of events using tags where possible
- [x] create/detect special types of events - like 'pets in 2025' or an entire vacation in a separate country, or a city trip.
- [ ] double check we follow the logic of the animations consistently and improve animation choices
- [x] find a way to use the triptych effect a bit more often
- [ ] add perhaps one-two more effects
- [ ] change the live photo icon to one more... like a live photo icon!- [ ] support for Android live photos - Google/Samsung embed them in jpeg files, Memories can deetect them.
- [ ] **Masonry/aspect-ratio thumbnails** — needs custom thumbnail generation since Nextcloud's preview API crops to square
- [ ] **Ultra-smooth motion pipeline** — animate on an upscaled working canvas first, then downscale to output at the end (best-quality approach to reduce subpixel jitter)

## Resources

- Repository: https://github.com/jospoortvliet/reel
- Issue tracker: https://github.com/jospoortvliet/reel/issues
- Discussions: https://github.com/jospoortvliet/reel/discussions