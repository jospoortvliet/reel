<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import SettingsView from './SettingsView.vue'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcButton from '@nextcloud/vue/components/NcButton'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

const router = useRouter()
const route  = useRoute()

// -------------------------------------------------------------------------
// Types
// -------------------------------------------------------------------------

interface Job {
	id: number
	event_id: number
	status: 'pending' | 'running' | 'done' | 'failed'
	progress: number
	error: string | null
	created_at: number
	updated_at: number
}

interface Event {
	id: number
	title: string
	location: string | null
	date_start: number
	date_end: number
	theme: string | null
	motion_style: string | null
	video_file_id: number | null
	cover_file_id?: number | null
	cover_thumbnail_url: string | null
	media_count: number
	job: Job | null
}

interface MediaItem {
	id: number
	file_id: number
	included: boolean
	sort_order: number
	thumbnail_url: string
	viewer_path: string
	w: number
	h: number
	isvideo: boolean
	liveid: string | null
	use_live_video: boolean
	video_duration?: number
	video_start?: number
	video_length?: number | null
	is_clip_video?: boolean
	can_edit_clip_timing?: boolean
	effective_video_start?: number
	effective_video_length?: number
}

interface EventDetail extends Event {
	video_path: string | null
	media: MediaItem[]
}

// -------------------------------------------------------------------------
// State
// -------------------------------------------------------------------------

const events        = ref<Event[]>([])
const selectedEvent = ref<EventDetail | null>(null)
const loading       = ref(false)
const showSettingsModal = ref(false)
const showClipEditor = ref(false)
const clipEditorItem = ref<MediaItem | null>(null)
const clipStart = ref(0)
const clipLength = ref(2)
let   pollTimer     = null as ReturnType<typeof setInterval> | null

const themeOptions = [
	{ value: 'default', label: t('reel', 'Default') },
	{ value: 'summer', label: t('reel', 'Summer') },
	{ value: 'minimal', label: t('reel', 'Minimal') },
]

const motionStyleOptions = [
	{ value: 'classic', label: t('reel', 'Classic') },
	{ value: 'apple_subtle', label: t('reel', 'Apple') },
]

// -------------------------------------------------------------------------
// Data fetching
// -------------------------------------------------------------------------

async function loadEvents() {
	loading.value = true
	try {
		const url      = generateOcsUrl('/apps/reel/api/v1/events')
		const response = await axios.get(url, { params: { format: 'json' } })
		const data     = response.data?.ocs?.data ?? response.data
		events.value   = Array.isArray(data) ? data : []
	} catch (e) {
		showError(t('reel', 'Failed to load events'))
	} finally {
		loading.value = false
	}
}

async function selectEvent(event: Event) {
	await router.push({ name: 'event', params: { id: event.id } })
}

function backToList() {
	router.push({ name: 'list' })
}

async function loadEventById(id: number) {
	loading.value = true
	stopPolling()
	try {
		const url           = generateOcsUrl(`/apps/reel/api/v1/events/${id}`)
		const response      = await axios.get(url, { params: { format: 'json' } })
		const data          = response.data?.ocs?.data ?? response.data
		selectedEvent.value = data
		if (isJobActive(selectedEvent.value?.job)) {
			startPolling()
		}
	} catch (e) {
		showError(t('reel', 'Failed to load event'))
	} finally {
		loading.value = false
	}
}

// -------------------------------------------------------------------------
// Rendering
// -------------------------------------------------------------------------

async function renderEvent() {
	if (!selectedEvent.value) return
	try {
		const url      = generateOcsUrl(`/apps/reel/api/v1/events/${selectedEvent.value.id}/render`)
		const response = await axios.post(url, {}, { params: { format: 'json' } })
		const job      = response.data?.ocs?.data?.job ?? response.data?.job
		if (job) {
			selectedEvent.value.job = job
			startPolling()
		}
	} catch (e: any) {
		const msg = e?.response?.data?.ocs?.data?.error ?? t('reel', 'Failed to start render')
		showError(msg)
	}
}

async function updateEventTheme(theme: string) {
	if (!selectedEvent.value) return
	try {
		const eventId = selectedEvent.value.id
		const url = generateOcsUrl(`/apps/reel/api/v1/events/${eventId}`)
		await axios.put(url, { theme }, { params: { format: 'json' } })
		selectedEvent.value.theme = theme
		const row = events.value.find(e => e.id === eventId)
		if (row) {
			row.theme = theme
		}
		showSuccess(t('reel', 'Theme updated'))
	} catch (e) {
		showError(t('reel', 'Failed to update theme'))
	}
}

async function updateEventMotionStyle(style: string) {
	if (!selectedEvent.value) return
	try {
		const eventId = selectedEvent.value.id
		const url = generateOcsUrl(`/apps/reel/api/v1/events/${eventId}`)
		await axios.put(url, { motion_style: style }, { params: { format: 'json' } })
		selectedEvent.value.motion_style = style
		const row = events.value.find(e => e.id === eventId)
		if (row) {
			row.motion_style = style
		}
		showSuccess(t('reel', 'Style updated'))
	} catch (e) {
		showError(t('reel', 'Failed to update style'))
	}
}

function openMediaInViewer(item: MediaItem) {
	if (window.OCA?.Viewer) {
		window.OCA.Viewer.open({ path: item.viewer_path })
	}
}

async function toggleLiveVideo(item: MediaItem) {
	try {
		const url = generateOcsUrl(`/apps/reel/api/v1/events/${selectedEvent.value.id}/media/${item.file_id}`)
		await axios.put(url, { use_live_video: !item.use_live_video }, { params: { format: 'json' } })
		item.use_live_video = !item.use_live_video
	} catch (e) {
		console.error('Failed to toggle live video', e)
	}
}

async function toggleMedia(item: MediaItem) {
	if (!selectedEvent.value) return
	const newValue = !item.included
	try {
		const url = generateOcsUrl(`/apps/reel/api/v1/events/${selectedEvent.value.id}/media/${item.file_id}`)
		await axios.put(url, { included: newValue }, { params: { format: 'json' } })
		item.included = newValue
	} catch (e) {
		showError(t('reel', 'Failed to update item'))
	}
}

function openClipEditor(item: MediaItem) {
	if (!item.can_edit_clip_timing) {
		return
	}

	const sourceDuration = Math.max(0, Number(item.video_duration ?? 0))
	const fallbackLength = sourceDuration > 0 ? Math.min(8, sourceDuration) : 8
	const currentLength = Number(item.video_length ?? item.effective_video_length ?? fallbackLength)
	const safeLength = Math.max(0.6, Math.min(currentLength, sourceDuration > 0 ? sourceDuration : currentLength))
	const maxStart = Math.max(0, sourceDuration - safeLength)
	const currentStart = Number(item.video_start ?? item.effective_video_start ?? 0)

	clipEditorItem.value = item
	clipLength.value = safeLength
	clipStart.value = Math.max(0, Math.min(currentStart, maxStart))
	showClipEditor.value = true
}

function closeClipEditor() {
	showClipEditor.value = false
	clipEditorItem.value = null
}

function onClipLengthInput() {
	const item = clipEditorItem.value
	if (!item) return
	const sourceDuration = Math.max(0, Number(item.video_duration ?? 0))
	if (sourceDuration <= 0) return

	clipLength.value = Math.max(0.6, Math.min(clipLength.value, sourceDuration))
	const maxStart = Math.max(0, sourceDuration - clipLength.value)
	clipStart.value = Math.min(clipStart.value, maxStart)
}

async function saveClipWindow() {
	const item = clipEditorItem.value
	if (!item || !selectedEvent.value || !item.can_edit_clip_timing) return

	try {
		const sourceDuration = Math.max(0, Number(item.video_duration ?? 0))
		const safeLength = sourceDuration > 0
			? Math.max(0.6, Math.min(clipLength.value, sourceDuration))
			: Math.max(0.6, clipLength.value)
		const maxStart = Math.max(0, sourceDuration - safeLength)
		const safeStart = Math.max(0, Math.min(clipStart.value, maxStart))

		const url = generateOcsUrl(`/apps/reel/api/v1/events/${selectedEvent.value.id}/media/${item.file_id}`)
		await axios.put(url, {
			video_start: Number(safeStart.toFixed(3)),
			video_length: Number(safeLength.toFixed(3)),
		}, { params: { format: 'json' } })

		item.video_start = safeStart
		item.video_length = safeLength
		item.effective_video_start = safeStart
		item.effective_video_length = safeLength

		showSuccess(t('reel', 'Clip timing updated'))
		closeClipEditor()
	} catch (e) {
		showError(t('reel', 'Failed to update clip timing'))
	}
}

async function resetClipWindow() {
	const item = clipEditorItem.value
	if (!item || !selectedEvent.value || !item.can_edit_clip_timing) return

	try {
		const sourceDuration = Math.max(0, Number(item.video_duration ?? 0))
		const defaultLength = sourceDuration > 0 ? Math.min(8, sourceDuration) : 8
		const defaultStart = sourceDuration > (defaultLength + 0.05)
			? Math.max(0, (sourceDuration - defaultLength) / 2)
			: 0

		const url = generateOcsUrl(`/apps/reel/api/v1/events/${selectedEvent.value.id}/media/${item.file_id}`)
		await axios.put(url, {
			video_start: Number(defaultStart.toFixed(3)),
			video_length: Number(defaultLength.toFixed(3)),
		}, { params: { format: 'json' } })

		item.video_start = defaultStart
		item.video_length = defaultLength
		item.effective_video_start = defaultStart
		item.effective_video_length = defaultLength

		clipStart.value = defaultStart
		clipLength.value = defaultLength
		showSuccess(t('reel', 'Clip timing reset'))
	} catch (e) {
		showError(t('reel', 'Failed to reset clip timing'))
	}
}

function formatSeconds(seconds: number | undefined): string {
	const s = Number(seconds ?? 0)
	return `${s.toFixed(2)}s`
}

function openInViewer() {
	const path = selectedEvent.value?.video_path
	if (!path) return
	// OCA.Viewer is loaded via LoadViewer event dispatched in PageController
	if (window.OCA?.Viewer?.open) {
		window.OCA.Viewer.open({ path })
	} else {
		console.error('Reel: OCA.Viewer not available')
	}
}

// -------------------------------------------------------------------------
// Polling
// -------------------------------------------------------------------------

function isJobActive(job: Job | null | undefined): boolean {
	return !!job && (job.status === 'pending' || job.status === 'running')
}

function startPolling() {
	if (pollTimer) return
	pollTimer = setInterval(pollStatus, 3000)
}

function stopPolling() {
	if (pollTimer) {
		clearInterval(pollTimer)
		pollTimer = null
	}
}

async function pollStatus() {
	if (!selectedEvent.value) return stopPolling()

	try {
		const url      = generateOcsUrl(`/apps/reel/api/v1/events/${selectedEvent.value.id}/status`)
		const response = await axios.get(url, { params: { format: 'json' } })
		const data     = response.data?.ocs?.data ?? response.data

		selectedEvent.value.job           = data.job
		selectedEvent.value.video_file_id = data.video_file_id
		selectedEvent.value.video_path    = data.video_path

		if (data.job?.status === 'done') {
			stopPolling()
			showSuccess(t('reel', 'Video ready! Check your Reels folder.'))
			await loadEvents() // refresh badge on list
		} else if (data.job?.status === 'failed') {
			stopPolling()
			showError(t('reel', 'Render failed: ') + (data.job.error ?? 'unknown error'))
		}
	} catch (e) {
		// silently ignore poll errors — network blip
	}
}

onUnmounted(stopPolling)

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function formatDateRange(start: number, end: number): string {
	const fmt = (ts: number) => new Date(ts * 1000).toLocaleDateString(undefined, {
		year: 'numeric', month: 'long', day: 'numeric',
	})
	const s = new Date(start * 1000)
	const e = new Date(end * 1000)
	return s.toDateString() === e.toDateString() ? fmt(start) : fmt(start) + ' – ' + fmt(end)
}

const includedCount = computed(() =>
	selectedEvent.value?.media.filter(m => m.included).length ?? 0
)

const jobStatus = computed(() => selectedEvent.value?.job?.status ?? null)
const jobProgress = computed(() => selectedEvent.value?.job?.progress ?? 0)
const isRendering = computed(() => isJobActive(selectedEvent.value?.job))

const PROGRESS_MESSAGES: [number, string][] = [
	[0,  '⏳ Waiting for cron to wake up…'],
	[1,  '🎬 Dusting off the film reel…'],
	[5,  '🖼️ Judging your photo composition…'],
	[10, '✂️ Cutting out the blurry ones (probably)…'],
	[18, '🌍 Consulting the vibe of each location…'],
	[25, '🎞️ Applying cinematic Ken Burns magic…'],
	[33, '🍿 Asking pixels to hold still…'],
	[40, '🧃 Pressing visual juice…'],
	[48, '🪐 Generating galaxy-brain transitions…'],
	[55, '🐌 Convincing FFmpeg to go faster…'],
	[63, '🎨 Colour-correcting your questionable lighting…'],
	[70, '🌀 Rewinding time (just a little)…'],
	[78, '🤌 Adding chef\'s kiss to every frame…'],
	[85, '🚀 Approaching ludicrous speed…'],
	[90, '🎼 Stitching it all together…'],
	[95, '🪄 Applying finishing touches…'],
	[99, '📦 Wrapping up, nearly there…'],
]

const progressMessage = computed(() => {
	const p = jobProgress.value
	if (jobStatus.value === 'pending') return '⏳ Waiting for cron to wake up…'
	let msg = PROGRESS_MESSAGES[0][1]
	for (const [threshold, text] of PROGRESS_MESSAGES) {
		if (p >= threshold) msg = text
	}
	return msg
})

const thumbnailUrl = (fileId: number, x: number, y: number) => generateUrl(`/core/preview?fileId=${fileId}&x=${x}&y=${y}&forceIcon=0`)

// -------------------------------------------------------------------------
// Init
// -------------------------------------------------------------------------

onMounted(async () => {
	await loadEvents()
	// Handle direct navigation to /events/:id (deep link / page refresh)
	const id = route.params.id
	if (id) {
		await loadEventById(Number(id))
	}
})

// React to route changes (back/forward, programmatic navigation)
watch(() => route.params.id, async (id) => {
	if (id) {
		await loadEventById(Number(id))
	} else {
		stopPolling()
		selectedEvent.value = null
	}
})
</script>

<template>
	<NcContent app-name="reel">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					name="All Events"
					:active="!route.params.id"
					@click="backToList" />
				<NcAppNavigationItem
					name="Settings"
					@click="showSettingsModal = true" />
			</template>
		</NcAppNavigation>

		<NcAppContent>

			<!-- Loading state -->
			<div v-if="loading" :class="$style.centered">
				<NcLoadingIcon :size="64" />
			</div>

			<!-- Event list -->
			<div v-else-if="!selectedEvent && !route.params.id" :class="$style.eventList">
				<div :class="$style.header">
					<h2>{{ t('reel', 'Your Reels') }}</h2>
					<p>{{ t('reel', '{n} events detected from your photo library', { n: events.length }) }}</p>
				</div>

				<NcEmptyContent
					v-if="events.length === 0"
					:name="t('reel', 'No events yet')"
					:description="t('reel', 'Run occ reel:detect-events to detect events from your photos')" />

				<div v-else :class="$style.grid">
					<div
						v-for="event in events"
						:key="event.id"
						:class="$style.card"
						@click="selectEvent(event)">
						<!-- Cover thumbnail -->
						<div :class="$style.cardCover">
							<img
								v-if="event.cover_file_id"
								:src="thumbnailUrl(event.cover_file_id, 400, 300)"
								:class="$style.coverImg" />
							<div v-else :class="$style.coverPlaceholder">
								<span :class="[$style.ncIcon, 'icon-video']" aria-hidden="true" />
							</div>
						</div>
						<div v-if="event.video_file_id" :class="$style.videoBadge">
							<span :class="[$style.ncIcon, 'icon-play']" aria-hidden="true" /> {{ t('reel', 'Video ready') }}
						</div>
						<div v-else-if="event.job && isJobActive(event.job)" :class="[$style.videoBadge, $style.renderingBadge]">
							<span :class="[$style.ncIcon, 'icon-history']" aria-hidden="true" /> {{ t('reel', 'Rendering…') }}
						</div>
						<div :class="$style.cardBody">
							<h3 :class="$style.cardTitle">{{ event.title }}</h3>
							<p :class="$style.cardMeta">{{ formatDateRange(event.date_start, event.date_end) }}</p>
							<p :class="$style.cardMeta">{{ event.media_count }} {{ t('reel', 'items') }}</p>
						</div>
					</div>
				</div>
			</div>

			<!-- Event detail -->
			<div v-else :class="$style.detail">
				<div :class="$style.backRow">
					<NcButton @click="backToList">← {{ t('reel', 'All Events') }}</NcButton>
				</div>

				<div :class="$style.detailHeader">
					<div>
						<h2>{{ selectedEvent.title }}</h2>
						<p>{{ formatDateRange(selectedEvent.date_start, selectedEvent.date_end) }}</p>
						<p>{{ includedCount }} {{ t('reel', 'of') }} {{ selectedEvent.media.length }} {{ t('reel', 'items included') }}</p>
						<div :class="$style.themeRow">
							<label :class="$style.themeLabel" for="theme-select">{{ t('reel', 'Music theme') }}</label>
							<select
								id="theme-select"
								:class="$style.themeSelect"
								:value="selectedEvent.theme ?? 'default'"
								@change="updateEventTheme(($event.target as HTMLSelectElement).value || 'default')">
								<option
									v-for="opt in themeOptions"
									:key="opt.value"
									:value="opt.value">
									{{ opt.label }}
								</option>
							</select>
						</div>
						<div :class="$style.themeRow">
							<label :class="$style.themeLabel" for="style-select">{{ t('reel', 'Motion style') }}</label>
							<select
								id="style-select"
								:class="$style.themeSelect"
								:value="selectedEvent.motion_style ?? 'classic'"
								@change="updateEventMotionStyle(($event.target as HTMLSelectElement).value || 'classic')">
								<option
									v-for="opt in motionStyleOptions"
									:key="opt.value"
									:value="opt.value">
									{{ opt.label }}
								</option>
							</select>
						</div>
					</div>
					<div :class="$style.actions">
						<NcButton
							type="primary"
							:disabled="isRendering || includedCount < 2"
							@click="renderEvent">
							<template #icon>
								<NcLoadingIcon v-if="isRendering" :size="20" />
							</template>
							{{ isRendering
								? t('reel', 'Rendering…')
								: selectedEvent.video_file_id
									? t('reel', 'Regenerate video')
									: t('reel', 'Generate video') }}
						</NcButton>
					</div>
				</div>

				<!-- Progress bar -->
				<div v-if="isRendering" :class="$style.progressWrap">
					<div :class="$style.progressLabel">
						{{ progressMessage }}
						<span v-if="jobStatus === 'running'" :class="$style.progressPct">{{ jobProgress }}%</span>
					</div>
					<div :class="$style.progressBar">
						<div
							:class="$style.progressFill"
							:style="{ width: (jobStatus === 'pending' ? 5 : jobProgress) + '%' }" />
					</div>
				</div>

				<!-- Video ready — open in Viewer -->
				<div v-if="selectedEvent.video_file_id && !isRendering" :class="$style.videoReady">
					<span><span :class="[$style.ncIcon, 'icon-checkmark']" aria-hidden="true" /> {{ t('reel', 'Video ready') }}</span>
					<NcButton type="primary" @click="openInViewer">
						{{ t('reel', 'Play video') }}
					</NcButton>
				</div>

				<!-- Failed notice -->
				<div v-if="jobStatus === 'failed'" :class="$style.videoFailed">
					<span :class="[$style.ncIcon, 'icon-close']" aria-hidden="true" /> {{ t('reel', 'Render failed:') }} {{ selectedEvent.job?.error }}
				</div>

				<!-- Media grid -->
				<div :class="$style.mediaGrid">
					<div
						v-for="item in selectedEvent.media"
						:key="item.id"
						:class="[$style.mediaItem, !item.included && $style.excluded]">
						<!-- Click image to preview in Viewer -->
						<img
							:src="thumbnailUrl(item.file_id, 320, 240)"
							:class="$style.thumbnail"
							@click="openMediaInViewer(item)" />
						<!-- Orientation frame — fades in on hover -->
						<div
							:class="[
								$style.orientFrame,
								item.w && item.h && item.w > item.h ? $style.frameLandscape
									: item.w && item.h && item.h > item.w ? $style.framePortrait
									: $style.frameSquare,
							]" />
						<!-- Media type icon — always visible, centered -->
						<span
							v-if="item.isvideo"
							:class="[$style.frameIcon, $style.ncIcon, 'icon-video']"
							aria-hidden="true" />
						<span
							v-else-if="item.liveid"
							:class="[$style.frameIcon, $style.ncIcon, 'icon-play']"
							aria-hidden="true" />
						<!-- Toggle include/exclude button top-right -->
						<button
							:class="[$style.toggleBtn, item.included ? $style.toggleIncluded : $style.toggleExcluded]"
							:title="item.included ? t('reel', 'Click to exclude') : t('reel', 'Click to include')"
							@click.stop="toggleMedia(item)">
							{{ item.included ? '✓' : '✕' }}
						</button>
						<!-- Live photo: toggle photo vs .mov — bottom-left -->
						<button
							v-if="item.liveid"
							:class="[$style.liveToggleBtn, item.use_live_video ? $style.liveToggleOn : $style.liveToggleOff]"
							:title="item.use_live_video ? t('reel', 'Using video clip — click to use photo') : t('reel', 'Using photo — click to use video clip')"
							@click.stop="toggleLiveVideo(item)">
							<span :class="[$style.ncIcon, item.use_live_video ? 'icon-play' : 'icon-picture']" aria-hidden="true" />
						</button>
						<button
							v-if="item.can_edit_clip_timing"
							:class="$style.clipToggleBtn"
							:title="t('reel', 'Edit clip timing')"
							@click.stop="openClipEditor(item)">
							<span :class="[$style.ncIcon, 'icon-category-monitoring']" aria-hidden="true" />
						</button>
					</div>
				</div>
			</div>

		</NcAppContent>

		<div
			v-if="showSettingsModal"
			:class="$style.modalBackdrop"
			@click.self="showSettingsModal = false">
			<div
				:class="$style.modalCard"
				role="dialog"
				:aria-label="t('reel', 'Reel Settings')"
				aria-modal="true">
				<div :class="$style.modalHeader">
					<h2>{{ t('reel', 'Reel Settings') }}</h2>
					<NcButton @click="showSettingsModal = false">{{ t('reel', 'Close') }}</NcButton>
				</div>
				<SettingsView :show-title="false" />
			</div>
		</div>

		<div
			v-if="showClipEditor && clipEditorItem"
			:class="$style.modalBackdrop"
			@click.self="closeClipEditor">
			<div
				:class="$style.clipModalCard"
				role="dialog"
				:aria-label="t('reel', 'Clip timing')"
				aria-modal="true">
				<div :class="$style.modalHeader">
					<h2>{{ t('reel', 'Clip timing') }}</h2>
					<NcButton @click="closeClipEditor">{{ t('reel', 'Close') }}</NcButton>
				</div>
				<div :class="$style.clipEditorBody">
					<p>
						{{ t('reel', 'Source duration:') }}
						<strong>{{ formatSeconds(clipEditorItem.video_duration) }}</strong>
					</p>

					<label :class="$style.sliderLabel">
						{{ t('reel', 'Start') }}
						<span>{{ formatSeconds(clipStart) }}</span>
					</label>
					<input
						type="range"
						min="0"
						:max="Math.max(0, (clipEditorItem.video_duration ?? 0) - clipLength)"
						step="0.05"
						v-model.number="clipStart"
						:class="$style.slider" />

					<label :class="$style.sliderLabel">
						{{ t('reel', 'Length') }}
						<span>{{ formatSeconds(clipLength) }}</span>
					</label>
					<input
						type="range"
						min="0.6"
						:max="Math.max(0.6, clipEditorItem.video_duration ?? 8)"
						step="0.05"
						v-model.number="clipLength"
						@input="onClipLengthInput"
						:class="$style.slider" />

					<div :class="$style.clipButtons">
						<NcButton @click="resetClipWindow">{{ t('reel', 'Reset') }}</NcButton>
						<NcButton type="primary" @click="saveClipWindow">{{ t('reel', 'Save') }}</NcButton>
					</div>
				</div>
			</div>
		</div>
	</NcContent>
</template>

<style module>
.modalBackdrop {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.56);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1200;
	padding: 20px;
}

.modalCard {
	width: min(940px, 100%);
	max-height: calc(100vh - 40px);
	overflow: auto;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 22px 44px rgba(0, 0, 0, 0.32);
}

.clipModalCard {
	width: min(560px, 100%);
	max-height: calc(100vh - 40px);
	overflow: auto;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 22px 44px rgba(0, 0, 0, 0.32);
}

.clipEditorBody {
	padding: 20px 28px 24px;
	display: grid;
	gap: 10px;
}

.sliderLabel {
	display: flex;
	justify-content: space-between;
	font-size: 0.9rem;
	font-weight: 600;
}

.slider {
	width: 100%;
}

.clipButtons {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}

.modalHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 20px 28px 0;
}

.modalHeader h2 {
	margin: 0;
	font-size: 1.3rem;
	font-weight: 600;
}

.centered {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 100%;
}

.backRow { margin-bottom: 16px; }

/* ---- Event list ---- */
.eventList { padding: 24px; }

.header { margin-bottom: 24px; }
.header h2 { font-size: 1.5rem; font-weight: 600; margin-bottom: 4px; }

.grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 16px;
}

.card {
	position: relative;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	cursor: pointer;
	transition: border-color 0.2s, box-shadow 0.2s;
	overflow: hidden;
}

.card:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 2px 12px var(--color-box-shadow);
}

.cardBody { padding: 16px; }
.cardTitle { font-size: 1rem; font-weight: 600; margin-bottom: 4px; }
.cardMeta { font-size: 0.875rem; color: var(--color-text-maxcontrast); }

.videoBadge {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 0.75rem;
	padding: 4px 10px;
}

.cardCover {
	height: 160px;
	overflow: hidden;
	background: var(--color-background-dark);
	display: flex;
	align-items: center;
	justify-content: center;
}

.coverImg {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}

.coverPlaceholder {
	font-size: 2.5rem;
	opacity: 0.4;
}

.ncIcon {
	display: inline-block;
	vertical-align: text-bottom;
}

.renderingBadge {
	background: var(--color-warning);
	color: #000;
}

/* ---- Event detail ---- */
.detail { padding: 24px; }

.detailHeader {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin: 16px 0 24px;
	gap: 16px;
}

.detailHeader h2 { font-size: 1.5rem; font-weight: 600; margin-bottom: 4px; }
.detailHeader p { color: var(--color-text-maxcontrast); font-size: 0.875rem; }
.actions { flex-shrink: 0; }

.themeRow {
	margin-top: 10px;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.themeLabel {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.themeSelect {
	min-width: 160px;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border-dark);
	background: var(--color-main-background);
}

/* ---- Progress bar ---- */
.progressWrap {
	margin-bottom: 20px;
}

.progressLabel {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 6px;
	display: flex;
	justify-content: space-between;
}

.progressPct {
	font-variant-numeric: tabular-nums;
	min-width: 3em;
	text-align: right;
}

.progressBar {
	height: 6px;
	background: var(--color-background-dark);
	border-radius: 3px;
	overflow: hidden;
}

.progressFill {
	height: 100%;
	background: var(--color-primary-element);
	border-radius: 3px;
	transition: width 0.5s ease;
	min-width: 5%;
}

/* ---- Status notices ---- */
.videoReady {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 12px 16px;
	margin-bottom: 24px;
	font-size: 0.9rem;
	border-left: 3px solid var(--color-success);
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.videoFailed {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 12px 16px;
	margin-bottom: 24px;
	font-size: 0.9rem;
	border-left: 3px solid var(--color-error);
}

/* ---- Media grid ---- */
.mediaGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: 8px;
}

.mediaItem {
	position: relative;
	aspect-ratio: 4/3;
	border-radius: var(--border-radius);
	overflow: hidden;
	box-sizing: border-box;
}

/* Orientation frame — fades in on hover */
.orientFrame {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	opacity: 0;
	transition: opacity 0.2s;
	pointer-events: none;
}

.mediaItem:hover .orientFrame {
	opacity: 1;
}

.orientFrame::before {
	content: '';
	display: block;
	border: 2.5px solid rgba(255, 255, 255, 0.85);
	border-radius: 6px;
	box-shadow: 0 0 0 1px rgba(0,0,0,0.2);
	pointer-events: none;
}

.frameLandscape::before { width: 72%; aspect-ratio: 4/3; }
.framePortrait::before  { width: 42%; aspect-ratio: 3/4; }
.frameSquare::before    { width: 55%; aspect-ratio: 1/1; }

/* Icon — always visible, centered, white */
.frameIcon {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	pointer-events: none;
	color: white;
	filter: drop-shadow(0 1px 3px rgba(0,0,0,0.5));
}

.mediaItem:hover .thumbnail { opacity: 0.85; }
.excluded .thumbnail { opacity: 0.35; }
.excluded:hover .thumbnail { opacity: 0.55; }

.thumbnail {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
	cursor: zoom-in;
	transition: opacity 0.2s;
}

/* Include/exclude toggle button — top-right corner */
.toggleBtn {
	position: absolute;
	top: 6px;
	right: 6px;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	border: 2px solid white;
	font-size: 14px;
	font-weight: 700;
	line-height: 1;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 1px 4px rgba(0,0,0,0.4);
	transition: transform 0.15s;
}

.toggleBtn:hover { transform: scale(1.15); }

.toggleIncluded {
	background: var(--color-success);
	color: white;
}

.toggleExcluded {
	background: rgba(200, 0, 0, 0.85);
	color: white;
}

/* Live photo toggle — bottom-left */
.liveToggleBtn {
	position: absolute;
	bottom: 6px;
	left: 6px;
	width: 26px;
	height: 26px;
	border-radius: 50%;
	border: 2px solid white;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 1px 4px rgba(0,0,0,0.4);
	transition: transform 0.15s;
}

.liveToggleBtn:hover { transform: scale(1.15); }

.liveToggleOn {
	background: var(--color-primary);
	color: white;
}

.liveToggleOff {
	background: rgba(0,0,0,0.45);
	color: white;
}

.clipToggleBtn {
	position: absolute;
	bottom: 6px;
	right: 6px;
	width: 26px;
	height: 26px;
	border-radius: 50%;
	border: 2px solid white;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 1px 4px rgba(0,0,0,0.4);
	transition: transform 0.15s;
	background: rgba(0,0,0,0.45);
	color: white;
}

.clipToggleBtn:hover {
	transform: scale(1.15);
}
</style>
