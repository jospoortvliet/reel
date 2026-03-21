# Memories Integration Proposal

## Summary

Reel could integrate with Memories by creating a Memories album for selected Reel events. This is a good integration target because albums are a natural, user-visible container for event media, and they provide value without requiring Reel to embed UI inside Memories.

For now, the recommended approach is narrow and one-way:

- Do not add deeper UI integration yet.
- Do not auto-create albums for every event.
- Start with export-style integration rather than full live sync.

## Why Albums Make Sense

- Albums are already a first-class concept in Memories.
- They provide a reusable collection users can browse, share, and curate.
- They expose Reel-detected events back into the photo library in a way users already understand.
- This avoids complicated frontend coupling between Reel and Memories.

## Technical Finding

Memories does have an internal album subsystem, but it appears to be owned by the Memories app itself rather than exposed as a simple public database contract.

Relevant observations:

- Memories is installed in the development instance.
- The app contains internal album query/backend code such as `AlbumsQuery` and `AlbumsBackend`.
- Public browsing routes exist for albums.
- No obvious public create-album API route was found during the initial inspection.

That means Reel can probably integrate with Memories albums, but the cleanest implementation path is not yet guaranteed.

## Recommended First Version

Start with a limited export model.

### Scope

- Add a Reel action such as `Create Memories album` for a single event.
- Create a Memories album from the event title.
- Add the event media to that album.
- Store the mapping between the Reel event and the Memories album.
- Do not continuously resync after creation.

### Why This First

- It is useful immediately.
- It avoids lifecycle complexity.
- It avoids surprising users by creating large numbers of albums automatically.
- It limits cross-app coupling while still validating the product idea.

## Recommended Product Stance

Treat the first version as an export, not as a live mirror.

That means:

- Reel creates the album.
- Memories owns the album afterwards from the user perspective.
- User changes inside Memories are not overwritten automatically.
- Reel does not try to delete or rebuild albums when event detection changes.

## Questions To Answer Before Building

### 1. What is the source of truth?

Options:

- Reel is authoritative and keeps albums in sync.
- Memories albums are only a one-time export.
- Reel owns only some metadata, while users own album curation.

Recommendation:

- Use one-time export semantics first.

### 2. Should album creation be automatic or manual?

Options:

- Manual per event
- Manual batch export
- Automatic only for selected event types
- Automatic for every detected event

Recommendation:

- Start with manual per-event export.

### 3. Which Reel event types should be eligible?

Good candidates:

- Trips
- Person-year events
- Pet-year events
- Year review events

Less obvious candidates:

- Every timeline event
- Seasonal events
- Traditions

Recommendation:

- Begin with trips, people, pets, and year-review events.

### 4. What happens when the user edits the album in Memories?

Examples:

- Renames the album
- Changes the cover
- Removes files
- Adds extra files
- Shares the album

Recommendation:

- Preserve user edits by default.
- Do not overwrite them automatically in the first version.

### 5. How should Reel track the mapping?

Likely needed:

- `reel_event_id`
- `memories_album_id`
- `created_at`
- optional sync mode or sync status

Recommendation:

- Add an explicit mapping table inside Reel rather than guessing by album title.

### 6. What happens when detection changes the event?

Examples:

- The event gets split differently on a later run.
- New photos are added to the event.
- The event disappears.

Recommendation:

- In the first version, do nothing automatically.
- Consider later adding a manual `Refresh album from event` action.

### 7. How much coupling to Memories is acceptable?

Possible implementation styles:

1. Public Memories API or service
2. Calling Memories internal PHP classes
3. Writing directly to Memories album tables

Recommendation:

- Prefer a supported Memories API or service if available.
- Use internal PHP classes only if the dependency is acceptable and can be guarded.
- Avoid writing directly to Memories tables unless there is no better option.

## Implementation Options

### Option A: Supported API or service

Best option.

Pros:

- Cleaner boundary
- Less fragile across Memories updates
- Easier to maintain

Cons:

- Depends on whether Memories already exposes the required functionality

### Option B: Soft dependency on Memories internal classes

Acceptable fallback.

Pros:

- Probably practical
- Reuses app logic rather than duplicating DB writes

Cons:

- Still couples Reel to another app's internal implementation
- May break on upstream refactors

### Option C: Direct SQL writes into Memories album tables

Fastest, but least desirable.

Pros:

- Straightforward if the schema is understood

Cons:

- Brittle
- Risks bypassing business logic, permissions, or side effects
- Higher maintenance cost over time

Recommendation:

- Do not start with direct SQL writes unless no better option exists.

## Proposed Delivery Plan

### Phase 1: Investigation

- Inspect Memories album creation code more deeply.
- Determine whether a stable internal service or controller can be called.
- Confirm what album metadata is required.

### Phase 2: Minimal export

- Add Reel-side mapping table.
- Add backend service to export one event to one Memories album.
- Add one user action in Reel to trigger export.
- Store the resulting album ID.

### Phase 3: Manual refresh

- Add `Refresh album from event`.
- Keep it explicit and one-way.

### Phase 4: Optional auto-export

- Add a user setting for selected event kinds.
- Only after the export model is proven useful.

### Phase 5: Optional live sync

- Only if users clearly want it.
- Requires decisions on ownership, overwrite rules, and deletion policy.

## Final Recommendation

Albums are the right first integration point with Memories, but the first version should be small and conservative.

Recommended path:

1. Investigate the cleanest Memories album creation hook.
2. Build manual one-way export from Reel event to Memories album.
3. Track explicit Reel-to-Memories album mappings.
4. Do not add automatic sync yet.

This gives users value quickly without locking Reel into fragile or overly complex cross-app behavior.