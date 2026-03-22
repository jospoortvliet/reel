<template>
	<div :class="$style.settings">
		<h2 v-if="showTitle">{{ t('reel', 'Reel Settings') }}</h2>

		<div v-if="loading" :class="$style.centered">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else>
			<section :class="$style.section">
				<h3>{{ t('reel', 'Music') }}</h3>
				<p :class="$style.description">
					{{ t('reel', 'Optional: set a folder in your files that contains your own soundtrack files. Reel scans this folder recursively and adds supported audio files (mp3, wav, aac, flac, ogg, m4a, opus) to the song picker.') }}
				</p>
				<div :class="$style.field">
					<label for="custom-music-folder">{{ t('reel', 'Custom music folder') }}</label>
					<div :class="$style.folderPickerRow">
						<input
							id="custom-music-folder"
							v-model.trim="form.customMusicFolder"
							type="text"
							:class="$style.textInput"
							readonly
							:placeholder="t('reel', 'No folder selected')" />
						<NcButton
							type="button"
							@click="pickCustomMusicFolder">
							{{ t('reel', 'Choose folder') }}
						</NcButton>
						<NcButton
							type="button"
							:disabled="!form.customMusicFolder"
							@click="form.customMusicFolder = ''">
							{{ t('reel', 'Clear') }}
						</NcButton>
					</div>
					<p :class="$style.hint">
						{{ t('reel', 'Pick a folder from Files. Reel scans it recursively for music files.') }}
					</p>
				</div>
			</section>

			<section :class="$style.section">
				<h3>{{ t('reel', 'Video Output') }}</h3>
				<p :class="$style.description">
					{{ t('reel', 'Choose the orientation of the generated highlight video. This also determines whether live photo clips are used by default - clips matching the output orientation are preferred.') }}
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
			</section>

			<section :class="$style.section">
				<h3>{{ t('reel', 'Automation') }}</h3>
				<p :class="$style.description">
					{{ t('reel', 'Optionally generate videos automatically after nightly event detection. Reel queues at most 3 new videos per run.') }}
				</p>
				<div :class="$style.field">
					<label :class="$style.checkboxRow">
						<input
							v-model="form.autoCreateVideos"
							type="checkbox" />
						<span>{{ t('reel', 'Automatically create videos in nightly background job') }}</span>
					</label>
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
					type="button"
					variant="primary"
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
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { getFilePickerBuilder, showError } from '@nextcloud/dialogs'
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

const form = ref({
	burstGap:    5,
	similarity:  16,
	orientation: 'landscape_16_9' as string,
	customMusicFolder: '',
	autoCreateVideos: false,
})

onMounted(async () => {
	try {
		const url      = generateOcsUrl('/apps/reel/api/v1/settings')
		const response = await axios.get(url, { params: { format: 'json' } })
		const data     = response.data?.ocs?.data ?? response.data
		form.value.burstGap    = data.burst_gap_seconds
		form.value.similarity  = data.similarity_threshold
		form.value.orientation = data.output_orientation ?? 'landscape_16_9'
		form.value.customMusicFolder = data.custom_music_folder ?? ''
		form.value.autoCreateVideos = !!data.auto_create_videos
	} catch (e) {
		showError(t('reel', 'Failed to load settings'))
	} finally {
		loading.value = false
	}
})

async function pickCustomMusicFolder() {
	try {
		const startPath = form.value.customMusicFolder || '/'
		const picker = getFilePickerBuilder(t('reel', 'Choose custom music folder'))
			.allowDirectories(true)
			.startAt(startPath)
			.build()

		const selected = await picker.pick()
		if (selected) {
			form.value.customMusicFolder = selected
		}
	} catch (error: any) {
		const msg = String(error?.message ?? '')
		if (msg.toLowerCase().includes('cancel')) {
			return
		}
		showError(t('reel', 'Failed to choose folder'))
	}
}

async function save() {
	saving.value = true
	saved.value  = false
	try {
		const url = generateOcsUrl('/apps/reel/api/v1/settings')
		await axios.put(url, {
			burst_gap_seconds:    form.value.burstGap,
			similarity_threshold: form.value.similarity,
			output_orientation:   form.value.orientation,
			custom_music_folder:  form.value.customMusicFolder,
			auto_create_videos:   form.value.autoCreateVideos,
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

.checkboxRow {
	display: flex !important;
	align-items: center;
	gap: 10px;
	font-weight: 500;
}

.inputRow {
	display: flex;
	align-items: center;
	gap: 12px;
}

.folderPickerRow {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.textInput {
	width: 100%;
	max-width: 320px;
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-element);
	background: var(--color-main-background);
	color: var(--color-main-text);
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
</style>
