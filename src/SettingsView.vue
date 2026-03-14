<template>
	<div :class="$style.settings">
		<h2 v-if="showTitle">{{ t('reel', 'Reel Settings') }}</h2>

		<div v-if="loading" :class="$style.centered">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else>
			<section :class="$style.section">
				<h3>{{ t('reel', 'Video Output') }}</h3>
				<p :class="$style.description">
					{{ t('reel', 'Choose the orientation of the generated highlight video. This also determines whether live photo clips are used by default — clips matching the output orientation are preferred.') }}
				</p>
				<div :class="$style.field">
					<label>{{ t('reel', 'Output orientation') }}</label>
					<div :class="$style.orientRow">
						<button
							:class="[$style.orientBtn, form.orientation === 'landscape_16_9' && $style.orientActive]"
							@click="form.orientation = 'landscape_16_9'">
							<span :class="$style.orientPreview" style="aspect-ratio:16/9" />
							{{ t('reel', 'Landscape 16:9') }}
						</button>
						<button
							:class="[$style.orientBtn, form.orientation === 'portrait_9_16' && $style.orientActive]"
							@click="form.orientation = 'portrait_9_16'">
							<span :class="$style.orientPreview" style="aspect-ratio:9/16" />
							{{ t('reel', 'Portrait 9:16') }}
						</button>
						<button
							:class="[$style.orientBtn, form.orientation === 'square_1_1' && $style.orientActive]"
							@click="form.orientation = 'square_1_1'">
							<span :class="$style.orientPreview" style="aspect-ratio:1/1" />
							{{ t('reel', 'Square 1:1') }}
						</button>
					</div>
				</div>

				<div :class="$style.field">
					<label>{{ t('reel', 'Motion engine') }}</label>
					<div :class="$style.engineRow">
						<button
							:class="[$style.engineBtn, form.motionStyle === 'classic' && $style.engineActive]"
							@click="form.motionStyle = 'classic'">
							{{ t('reel', 'Classic adaptive') }}
						</button>
						<button
							:class="[$style.engineBtn, form.motionStyle === 'apple_subtle' && $style.engineActive]"
							@click="form.motionStyle = 'apple_subtle'">
							{{ t('reel', 'Apple-style subtle') }}
						</button>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'Apple-style subtle keeps movement very gentle and groups 2-4 photos with the same motion before switching, especially after video/live clips.') }}
					</p>
				</div>

				<div :class="$style.field">
					<label>{{ t('reel', 'Motion style') }}</label>
					<div :class="$style.presetRow">
						<button
							v-for="preset in PRESETS"
							:key="preset.id"
							:class="[$style.presetBtn, activePreset === preset.id && $style.presetActive]"
							@click="applyPreset(preset.id)">
							<span :class="$style.presetName">{{ preset.label }}</span>
							<span :class="$style.presetDesc">{{ preset.desc }}</span>
						</button>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'Presets tune the motion sliders below. You can fine-tune any slider after applying a preset.') }}
					</p>
				</div>

				<div :class="$style.field">
					<label for="pan-threshold">{{ t('reel', 'Pan mismatch threshold') }}</label>
					<div :class="$style.inputRow">
						<input
							id="pan-threshold"
							v-model.number="form.panMismatchThreshold"
							type="range"
							min="1.05"
							max="1.80"
							step="0.01" />
						<span :class="$style.value">{{ form.panMismatchThreshold.toFixed(2) }}</span>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'How different the photo aspect must be from output aspect before pan mode is used. Higher means fewer pans (4:3 can stay zoom). Default: 1.40.') }}
					</p>
				</div>

				<div :class="$style.field">
					<label for="pan-sweep">{{ t('reel', 'Pan sweep edge margin') }}</label>
					<div :class="$style.inputRow">
						<input
							id="pan-sweep"
							v-model.number="form.panSweepMarginPercent"
							type="range"
							min="0"
							max="50"
							step="1" />
						<span :class="$style.value">{{ form.panSweepMarginPercent }}%</span>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'Margins for normal pan sweeps. Lower uses more of the frame, higher stays closer to center. At 50% there is no pan motion. Default: 8%.') }}
					</p>
				</div>

				<div :class="$style.field">
					<label for="pan-panorama-sweep">{{ t('reel', 'Panorama sweep edge margin') }}</label>
					<div :class="$style.inputRow">
						<input
							id="pan-panorama-sweep"
							v-model.number="form.panPanoramaSweepMarginPercent"
							type="range"
							min="0"
							max="50"
							step="1" />
						<span :class="$style.value">{{ form.panPanoramaSweepMarginPercent }}%</span>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'Margins for panorama pans. Lower values sweep nearly the full width/height. At 50% there is no pan motion. Default: 2%.') }}
					</p>
				</div>

				<div :class="$style.field">
					<label for="face-zoom-target">{{ t('reel', 'Face zoom target') }}</label>
					<div :class="$style.inputRow">
						<input
							id="face-zoom-target"
							v-model.number="form.faceZoomTargetFillPercent"
							type="range"
							min="55"
							max="95"
							step="1" />
						<span :class="$style.value">{{ form.faceZoomTargetFillPercent }}%</span>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'How large a detected face group should appear in frame at peak zoom. Higher means tighter face zoom. Default: 75%.') }}
					</p>
				</div>

				<div :class="$style.field">
					<label for="non-face-zoom">{{ t('reel', 'Non-face zoom amount') }}</label>
					<div :class="$style.inputRow">
						<input
							id="non-face-zoom"
							v-model.number="form.nonFaceZoomEnd"
							type="range"
							min="1.05"
							max="1.80"
							step="0.01" />
						<span :class="$style.value">{{ form.nonFaceZoomEnd.toFixed(2) }}x</span>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'How far to zoom in when no faces are detected. Default: 1.42x.') }}
					</p>
				</div>
			</section>

			<section :class="$style.section">
				<h3>{{ t('reel', 'Duplicate Detection') }}</h3>
				<p :class="$style.description">
					{{ t('reel', 'When generating a highlight reel, Reel automatically skips near-duplicate photos taken in quick succession. Adjust these settings to tune how aggressively duplicates are filtered.') }}
				</p>

				<div :class="$style.field">
					<label for="burst-gap">
						{{ t('reel', 'Burst gap (seconds)') }}
					</label>
					<div :class="$style.inputRow">
						<input
							id="burst-gap"
							v-model.number="form.burstGap"
							type="range"
							min="1"
							max="15"
							step="1" />
						<span :class="$style.value">{{ form.burstGap }}s</span>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'Photos taken within this many seconds of each other are candidates for deduplication. Default: 5s.') }}
					</p>
				</div>

				<div :class="$style.field">
					<label for="similarity">
						{{ t('reel', 'Visual similarity threshold') }}
					</label>
					<div :class="$style.inputRow">
						<input
							id="similarity"
							v-model.number="form.similarity"
							type="range"
							min="1"
							max="30"
							step="1" />
						<span :class="$style.value">{{ form.similarity }}</span>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'How visually similar two photos must be to count as duplicates (blurhash distance). Lower = stricter, higher = more aggressive. Default: 16.') }}
					</p>
				</div>

			</section>

			<div :class="$style.actions">
					<NcButton
						type="primary"
						:disabled="saving"
						@click="save">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{ t('reel', 'Save settings') }}
					</NcButton>
					<span v-if="saved" :class="$style.savedMsg">{{ t('reel', '✓ Saved') }}</span>
				</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

withDefaults(defineProps<{
	showTitle?: boolean
}>(), {
	showTitle: true,
})

const loading = ref(true)
const saving  = ref(false)
const saved   = ref(false)

interface Preset {
	id: string
	label: string
	desc: string
	motionStyle: 'classic' | 'apple_subtle'
	panMismatchThreshold: number
	panSweepMarginPercent: number
	panPanoramaSweepMarginPercent: number
	faceZoomTargetFillPercent: number
	nonFaceZoomEnd: number
}

const PRESETS: Preset[] = [
	{
		id: 'balanced',
		label: t('reel', 'Balanced'),
		desc: t('reel', 'Default — moderate zoom and selective pan'),
		motionStyle: 'classic',
		panMismatchThreshold: 1.40,
		panSweepMarginPercent: 8,
		panPanoramaSweepMarginPercent: 2,
		faceZoomTargetFillPercent: 75,
		nonFaceZoomEnd: 1.42,
	},
	{
		id: 'more_pan',
		label: t('reel', 'More Pan'),
		desc: t('reel', 'Wider sweeps on more images'),
		motionStyle: 'classic',
		panMismatchThreshold: 1.18,
		panSweepMarginPercent: 4,
		panPanoramaSweepMarginPercent: 1,
		faceZoomTargetFillPercent: 75,
		nonFaceZoomEnd: 1.35,
	},
	{
		id: 'cinematic',
		label: t('reel', 'Cinematic'),
		desc: t('reel', 'Dramatic zoom, full panorama sweeps'),
		motionStyle: 'classic',
		panMismatchThreshold: 1.25,
		panSweepMarginPercent: 2,
		panPanoramaSweepMarginPercent: 0,
		faceZoomTargetFillPercent: 88,
		nonFaceZoomEnd: 1.68,
	},
	{
		id: 'subtle',
		label: t('reel', 'Apple Subtle'),
		desc: t('reel', 'Grouped subtle moves + transitions + triptych segments'),
		motionStyle: 'apple_subtle',
		panMismatchThreshold: 1.65,
		panSweepMarginPercent: 35,
		panPanoramaSweepMarginPercent: 35,
		faceZoomTargetFillPercent: 66,
		nonFaceZoomEnd: 1.16,
	},
]

const activePreset = computed(() => {
	const f = form.value
	for (const p of PRESETS) {
		if (
			f.motionStyle === p.motionStyle
			&&
			Math.abs(f.panMismatchThreshold - p.panMismatchThreshold) < 0.005
			&& f.panSweepMarginPercent === p.panSweepMarginPercent
			&& f.panPanoramaSweepMarginPercent === p.panPanoramaSweepMarginPercent
			&& f.faceZoomTargetFillPercent === p.faceZoomTargetFillPercent
			&& Math.abs(f.nonFaceZoomEnd - p.nonFaceZoomEnd) < 0.005
		) {
			return p.id
		}
	}
	return null
})

function applyPreset(id: string) {
	const p = PRESETS.find(pr => pr.id === id)
	if (!p) return
	form.value.motionStyle = p.motionStyle
	form.value.panMismatchThreshold = p.panMismatchThreshold
	form.value.panSweepMarginPercent = p.panSweepMarginPercent
	form.value.panPanoramaSweepMarginPercent = p.panPanoramaSweepMarginPercent
	form.value.faceZoomTargetFillPercent = p.faceZoomTargetFillPercent
	form.value.nonFaceZoomEnd = p.nonFaceZoomEnd
}

const form = ref({
	burstGap:    5,
	similarity:  16,
	orientation: 'landscape_16_9' as string,
	motionStyle: 'classic' as 'classic' | 'apple_subtle',
	panMismatchThreshold: 1.4,
	panSweepMarginPercent: 8,
	panPanoramaSweepMarginPercent: 2,
	faceZoomTargetFillPercent: 75,
	nonFaceZoomEnd: 1.42,
})

onMounted(async () => {
	try {
		const url      = generateOcsUrl('/apps/reel/api/v1/settings')
		const response = await axios.get(url, { params: { format: 'json' } })
		const data     = response.data?.ocs?.data ?? response.data
		form.value.burstGap    = data.burst_gap_seconds
		form.value.similarity  = data.similarity_threshold
		form.value.orientation = data.output_orientation ?? 'landscape_16_9'
		form.value.motionStyle = data.motion_style ?? 'classic'
		form.value.panMismatchThreshold = data.pan_mismatch_threshold ?? 1.4
		form.value.panSweepMarginPercent = Math.round((data.pan_sweep_margin ?? 0.08) * 100)
		form.value.panPanoramaSweepMarginPercent = Math.round((data.pan_panorama_sweep_margin ?? 0.02) * 100)
		form.value.faceZoomTargetFillPercent = Math.round((data.face_zoom_target_fill ?? 0.75) * 100)
		form.value.nonFaceZoomEnd = data.non_face_zoom_end ?? 1.42
	} catch (e) {
		showError(t('reel', 'Failed to load settings'))
	} finally {
		loading.value = false
	}
})

async function save() {
	saving.value = true
	saved.value  = false
	try {
		const url = generateOcsUrl('/apps/reel/api/v1/settings')
		await axios.put(url, {
			burst_gap_seconds:    form.value.burstGap,
			similarity_threshold: form.value.similarity,
			output_orientation:   form.value.orientation,
			motion_style: form.value.motionStyle,
			pan_mismatch_threshold: form.value.panMismatchThreshold,
			pan_sweep_margin: form.value.panSweepMarginPercent / 100,
			pan_panorama_sweep_margin: form.value.panPanoramaSweepMarginPercent / 100,
			face_zoom_target_fill: form.value.faceZoomTargetFillPercent / 100,
			non_face_zoom_end: form.value.nonFaceZoomEnd,
		}, { params: { format: 'json' } })
		saved.value = true
		setTimeout(() => { saved.value = false }, 3000)
	} catch (e) {
		showError(t('reel', 'Failed to save settings'))
	} finally {
		saving.value = false
	}
}
</script>

<style module>
.settings {
	padding: 24px 32px;
	max-width: 600px;
}

.settings h2 {
	font-size: 1.5em;
	font-weight: 600;
	margin-bottom: 24px;
}

.section h3 {
	font-size: 1.1em;
	font-weight: 600;
	margin-bottom: 8px;
}

.description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 24px;
	line-height: 1.5;
}

.field {
	margin-bottom: 24px;
}

.field label {
	display: block;
	font-weight: 500;
	margin-bottom: 6px;
}

.inputRow {
	display: flex;
	align-items: center;
	gap: 12px;
}

.inputRow input[type="range"] {
	flex: 1;
	accent-color: var(--color-primary);
}

.value {
	min-width: 36px;
	font-weight: 600;
	color: var(--color-primary);
}

.hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.actions {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-top: 8px;
}

.savedMsg {
	color: var(--color-success);
	font-weight: 500;
}

.centered {
	display: flex;
	justify-content: center;
	padding: 48px;
}

.orientRow {
	display: flex;
	gap: 12px;
	margin-top: 8px;
}

.orientBtn {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 12px 20px;
	border-radius: var(--border-radius-large);
	border: 2px solid var(--color-border);
	background: var(--color-background-hover);
	cursor: pointer;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	transition: border-color 0.15s, color 0.15s;
}

.orientBtn:hover {
	border-color: var(--color-primary);
	color: var(--color-main-text);
}

.orientActive {
	border-color: var(--color-primary) !important;
	color: var(--color-primary) !important;
	background: var(--color-primary-light);
}

.orientPreview {
	display: block;
	width: 48px;
	border: 2px solid currentColor;
	border-radius: 3px;
}

.engineRow {
	display: flex;
	gap: 10px;
	margin-top: 8px;
	flex-wrap: wrap;
}

.engineBtn {
	padding: 10px 14px;
	border-radius: var(--border-radius-large);
	border: 2px solid var(--color-border);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-weight: 600;
	cursor: pointer;
	transition: border-color 0.15s, background 0.15s;
}

.engineBtn:hover {
	border-color: var(--color-primary);
}

.engineActive {
	border-color: var(--color-primary) !important;
	background: var(--color-primary-light);
	color: var(--color-primary);
}

.presetRow {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin-top: 8px;
}

.presetBtn {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 3px;
	padding: 10px 16px;
	border-radius: var(--border-radius-large);
	border: 2px solid var(--color-border);
	background: var(--color-background-hover);
	cursor: pointer;
	transition: border-color 0.15s, background 0.15s;
	min-width: 140px;
	flex: 1;
	text-align: left;
}

.presetBtn:hover {
	border-color: var(--color-primary);
}

.presetActive {
	border-color: var(--color-primary) !important;
	background: var(--color-primary-light);
}

.presetName {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-main-text);
}

.presetActive .presetName {
	color: var(--color-primary);
}

.presetDesc {
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	line-height: 1.3;
}
</style>
