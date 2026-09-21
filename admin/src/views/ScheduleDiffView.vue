<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import { fetchLineVersionDiff, type DiffPair, type LineVersionDiff } from '../services/scheduleDiff'
import { formatClock, formatClockDelta, formatDate } from '../utils/timezone'

const route = useRoute()

const diff = ref<LineVersionDiff | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const aufgeklappt = ref<Record<number, boolean>>({})

onMounted(async () => {
  const von = Number(route.query.from)
  const nach = Number(route.query.to)

  if (!von || !nach) {
    error.value = 'Es fehlen die beiden zu vergleichenden Versionen.'
    loading.value = false
    return
  }

  try {
    diff.value = await fetchLineVersionDiff(von, nach)
  } catch (e: unknown) {
    const message = (e as { response?: { data?: { error?: { message?: string } } } })?.response?.data
      ?.error?.message
    error.value = message ?? 'Vergleich konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

/** Liegen die beiden Versionen in verschiedenen Perioden? Seit 21.09.2026 möglich. */
const periodenwechsel = computed<boolean>(() => {
  const d = diff.value
  return d?.from.period != null && d.to.period != null && d.from.period.id !== d.to.period.id
})

const kennzahlen = computed(() => {
  const s = diff.value?.summary
  return s
    ? [
        { label: 'unverändert', wert: s.unchanged, ton: 'text-slate-900' },
        { label: 'geändert', wert: s.changed, ton: s.changed > 0 ? 'text-amber-700' : 'text-slate-900' },
        { label: 'neu', wert: s.added, ton: s.added > 0 ? 'text-emerald-700' : 'text-slate-900' },
        { label: 'entfallen', wert: s.removed, ton: s.removed > 0 ? 'text-rose-700' : 'text-slate-900' },
      ]
    : []
})

const GRUND: Record<DiffPair['reason'], string> = {
  time: 'verschoben',
  route: 'anderer Laufweg',
  time_and_route: 'verschoben und anderer Laufweg',
}

function gueltigkeit(intervals: { valid_from: string; valid_to: string; from_confirmed: boolean; to_confirmed: boolean }[]): string {
  if (intervals.length === 0) {
    return 'ohne Gültigkeit'
  }
  return intervals
    .map(
      (i) =>
        `${i.from_confirmed ? '' : 'ab '}${formatDate(i.valid_from)} – ${i.to_confirmed ? '' : 'mind. '}${formatDate(i.valid_to)}`,
    )
    .join(', ')
}

const ZEILENFARBE: Record<string, string> = {
  equal: 'text-slate-400',
  shifted: 'text-amber-800 bg-amber-50',
  only_from: 'text-rose-800 bg-rose-50',
  only_to: 'text-emerald-800 bg-emerald-50',
}
</script>

<template>
  <div class="min-h-full bg-slate-100">
    <AppHeader />

    <main class="mx-auto max-w-6xl px-6 py-8">
      <h1 class="text-2xl font-semibold text-slate-900">Versionsvergleich</h1>

      <p v-if="error" class="mt-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
      <p v-if="loading" class="mt-4 text-sm text-slate-500">Wird verglichen …</p>

      <template v-else-if="diff">
        <p class="mt-1 text-sm text-slate-600">
          Linie {{ diff.from.line }} · {{ diff.from.day_type_label }} ·
          v{{ diff.from.version_no }} gegen v{{ diff.to.version_no }}
        </p>

        <!-- Ueber die Periodengrenze faengt die Zaehlung wieder bei 1 an - das muss dastehen,
             sonst liest sich "v3 gegen v1" rueckwaerts. -->
        <p v-if="periodenwechsel" class="mt-2 rounded-md bg-slate-200/70 px-4 py-2 text-sm text-slate-700">
          Über die Periodengrenze: <strong>{{ diff.from.period?.label }}</strong> →
          <strong>{{ diff.to.period?.label }}</strong>. In der neuen Periode beginnt die
          Versionszählung jeder Linie wieder bei&nbsp;1 — die kleinere Nummer rechts ist kein
          Rückschritt.
        </p>

        <!-- Die beiden Versionen mit ihrer beobachteten Gültigkeit -->
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <div v-for="(v, i) in [diff.from, diff.to]" :key="v.id" class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="text-xs uppercase text-slate-500">{{ i === 0 ? 'vorher' : 'nachher' }}</div>
            <div class="mt-1 font-semibold text-slate-900">Version {{ v.version_no }}</div>
            <div v-if="v.period" class="text-xs text-slate-500">{{ v.period.label }}</div>
            <div class="mt-1 text-sm text-slate-600">{{ v.trip_count }} Fahrten</div>
            <div class="mt-1 text-xs text-slate-500">{{ gueltigkeit(v.intervals) }}</div>
          </div>
        </div>

        <section class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-4">
          <div v-for="k in kennzahlen" :key="k.label" class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="text-xs text-slate-500">{{ k.label }}</div>
            <div class="mt-1 text-lg font-semibold tabular-nums" :class="k.ton">{{ k.wert }}</div>
          </div>
        </section>

        <!-- Geänderte Fahrten -->
        <section v-if="diff.changed.length > 0" class="mt-8">
          <h2 class="text-sm font-semibold text-slate-900">Geänderte Fahrten</h2>
          <div class="mt-3 space-y-2">
            <div v-for="paar in diff.changed" :key="paar.from_trip.id" class="overflow-hidden rounded-lg border border-slate-200 bg-white">
              <button
                class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-slate-50"
                @click="aufgeklappt[paar.from_trip.id] = !aufgeklappt[paar.from_trip.id]"
              >
                <span class="text-sm text-slate-800">
                  {{ paar.from_trip.start_stop }} → {{ paar.from_trip.end_stop }}
                  <span class="ml-2 tabular-nums text-slate-500">
                    {{ formatClock(paar.from_trip.departure_time) }} →
                    {{ formatClock(paar.to_trip.departure_time) }}
                  </span>
                </span>
                <span class="flex items-center gap-2 text-xs">
                  <span class="rounded bg-amber-100 px-2 py-0.5 font-medium tabular-nums text-amber-900">
                    {{ formatClockDelta(paar.shift_seconds) }}
                  </span>
                  <span class="text-slate-500">{{ GRUND[paar.reason] }}</span>
                  <!-- Der häufige Fall: alle Halte gleich verschoben. Dann lohnt das Aufklappen kaum. -->
                  <span v-if="paar.uniform_shift" class="text-slate-400">durchgehend</span>
                  <span class="text-slate-400">{{ aufgeklappt[paar.from_trip.id] ? '▾' : '▸' }}</span>
                </span>
              </button>

              <table v-if="aufgeklappt[paar.from_trip.id]" class="w-full border-t border-slate-100 text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                  <tr>
                    <th class="px-4 py-2 text-left">Halt</th>
                    <th class="px-4 py-2 text-right">vorher</th>
                    <th class="px-4 py-2 text-right">nachher</th>
                    <th class="px-4 py-2 text-right">Δ</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(zeile, i) in paar.stops ?? []" :key="i" :class="ZEILENFARBE[zeile.status]">
                    <td class="px-4 py-1.5">
                      {{ zeile.stop_name }}
                      <span v-if="zeile.status === 'only_from'" class="ml-1 text-xs">entfällt</span>
                      <span v-if="zeile.status === 'only_to'" class="ml-1 text-xs">neu</span>
                    </td>
                    <td class="px-4 py-1.5 text-right tabular-nums">{{ zeile.from_time ?? '—' }}</td>
                    <td class="px-4 py-1.5 text-right tabular-nums">{{ zeile.to_time ?? '—' }}</td>
                    <td class="px-4 py-1.5 text-right tabular-nums">
                      {{ zeile.delta_seconds === null ? '—' : formatClockDelta(zeile.delta_seconds) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- Neu und entfallen -->
        <section v-for="block in [
          { titel: 'Neue Fahrten', fahrten: diff.added, farbe: 'text-emerald-700' },
          { titel: 'Entfallene Fahrten', fahrten: diff.removed, farbe: 'text-rose-700' },
        ]" :key="block.titel">
          <template v-if="block.fahrten.length > 0">
            <h2 class="mt-8 text-sm font-semibold" :class="block.farbe">
              {{ block.titel }} ({{ block.fahrten.length }})
            </h2>
            <div class="mt-3 overflow-hidden rounded-lg border border-slate-200 bg-white">
              <table class="w-full text-left text-sm">
                <tbody>
                  <tr v-for="f in block.fahrten" :key="f.id" class="border-b border-slate-50 last:border-0">
                    <td class="px-4 py-1.5 tabular-nums text-slate-700">{{ formatClock(f.departure_time) }}</td>
                    <td class="px-4 py-1.5 text-slate-700">{{ f.start_stop }} → {{ f.end_stop }}</td>
                    <td class="px-4 py-1.5 text-right text-slate-400">{{ f.stop_count }} Halte</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </section>

        <p
          v-if="diff.changed.length === 0 && diff.added.length === 0 && diff.removed.length === 0"
          class="mt-8 rounded-lg bg-white px-4 py-6 text-center text-sm text-slate-500"
        >
          Die beiden Versionen tragen dieselben Fahrten. Der Fingerprint unterscheidet sich
          trotzdem — dann liegt der Unterschied in einem Betriebstag, an dem die Linie gar nicht fuhr.
        </p>
      </template>
    </main>
  </div>
</template>
