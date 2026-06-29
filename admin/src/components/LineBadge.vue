<script setup lang="ts">
import { ref } from 'vue'
import type { Line } from '../services/lines'
import { lineColor, lineLabel, lineShape, lineTextColor } from '../utils/lineStyle'

const props = defineProps<{ line: Line; size?: 'sm' | 'md' | 'lg' }>()

const dim: Record<string, string> = {
  sm: 'h-7 w-7 text-[11px]',
  md: 'h-9 w-9 text-sm',
  lg: 'h-11 w-11 text-base',
}

// Offizielles MVB-Nachtsignet je Nachtlinie (z. B. /lines/N1.png).
const signetSrc = `/lines/${props.line.route_short_name}.png`
const signetFailed = ref(false)

// Fallback-Fledermaus, falls kein Signet vorhanden (z. B. neue Nachtlinie).
const BAT_PATH =
  'M50 17 L55 9 L57 21 C72 13 88 18 97 30 C89 31 86 33 84 39 C78 34 72 36 69 44 ' +
  'C61 38 54 42 50 55 C46 42 39 38 31 44 C28 36 22 34 16 39 C14 33 11 31 3 30 ' +
  'C12 18 28 13 43 21 L45 9 L50 17 Z'

const isBat = lineShape(props.line) === 'bat'
</script>

<template>
  <span class="relative inline-flex shrink-0 items-center justify-center" :class="dim[props.size ?? 'md']">
    <!-- Nachtverkehr: offizielles MVB-Signet, Linienbezeichnung als Overlay darüber -->
    <template v-if="isBat && !signetFailed">
      <img
        :src="signetSrc"
        :alt="`Linie ${line.route_short_name}`"
        class="absolute inset-0 h-full w-full object-contain"
        @error="signetFailed = true"
      />
      <span
        class="relative font-bold leading-none text-white"
        style="text-shadow: -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000"
      >
        {{ lineLabel(line) }}
      </span>
    </template>

    <!-- Fallback Nacht: Fledermaus-Silhouette in Linienfarbe + Label -->
    <template v-else-if="isBat">
      <svg class="absolute inset-0 h-full w-full" viewBox="0 0 100 60" :style="{ color: lineColor(line) }">
        <path :d="BAT_PATH" fill="currentColor" />
      </svg>
      <span class="relative font-bold leading-none" :style="{ color: lineTextColor(line) }">{{ lineLabel(line) }}</span>
    </template>

    <!-- Tram (Kreis) / Bus (Viereck): Form in Linienfarbe + Label -->
    <template v-else>
      <span
        class="absolute inset-0"
        :class="{
          'rounded-full': lineShape(line) === 'circle',
          'rounded-md': lineShape(line) === 'square',
        }"
        :style="{ backgroundColor: lineColor(line) }"
      />
      <span class="relative font-bold leading-none" :style="{ color: lineTextColor(line) }">{{ lineLabel(line) }}</span>
    </template>
  </span>
</template>
