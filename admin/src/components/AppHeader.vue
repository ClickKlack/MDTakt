<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { refreshSightingCounts, sightingCounts } from '../services/sightings'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

interface MenuEintrag {
  to: string
  label: string
}

interface MenuGruppe {
  key: string
  label: string
  eintraege: MenuEintrag[]
}

/**
 * Gruppiert nach Arbeitsschritt (entschieden 26.09.2026): Fahrplan lesen, Umläufe pflegen,
 * Sichtungen prüfen, Stammdaten verwalten. Zwölf Punkte nebeneinander passten nicht mehr in eine Zeile.
 * Sichtungen stehen als einziger Punkt direkt oben — dort wartet Arbeit, und der Zähler soll sichtbar sein.
 */
const gruppen: MenuGruppe[] = [
  {
    key: 'fahrplan',
    label: 'Fahrplan',
    eintraege: [
      { to: '/lines', label: 'Linien' },
      { to: '/fahrplan', label: 'Fahrplan' },
      { to: '/versions', label: 'Versionen' },
      { to: '/coverage', label: 'Abdeckung' },
      { to: '/periods', label: 'Perioden' },
    ],
  },
  {
    key: 'umlaeufe',
    label: 'Umläufe',
    eintraege: [
      { to: '/kurse', label: 'Kurse' },
      { to: '/anschluesse', label: 'Anschlüsse' },
    ],
  },
  {
    key: 'admin',
    label: 'Administration',
    eintraege: [
      { to: '/haltestellen', label: 'Haltestellen' },
      { to: '/betriebshoefe', label: 'Betriebshöfe' },
      { to: '/calendar', label: 'Kalender' },
      { to: '/imports', label: 'Import-Auditing' },
    ],
  },
]

/** Welches Menü offen ist — höchstens eines; `user` ist das Benutzer-Menü. */
const offen = ref<string | null>(null)
const kopf = ref<HTMLElement | null>(null)

function istAktiv(to: string): boolean {
  return route.path === to || route.path.startsWith(`${to}/`)
}

/** Die Gruppe der aktuellen Seite wird hervorgehoben, auch wenn ihr Menü zu ist. */
const aktiveGruppe = computed(() => gruppen.find((g) => g.eintraege.some((e) => istAktiv(e.to)))?.key ?? null)

function schalte(key: string): void {
  offen.value = offen.value === key ? null : key
}

function klickAussen(e: MouseEvent): void {
  if (kopf.value && !kopf.value.contains(e.target as Node)) {
    offen.value = null
  }
}

function taste(e: KeyboardEvent): void {
  if (e.key === 'Escape') {
    offen.value = null
  }
}

// Nach einem Seitenwechsel ist das Menü erledigt.
watch(() => route.fullPath, () => {
  offen.value = null
})

onMounted(async () => {
  document.addEventListener('click', klickAussen)
  document.addEventListener('keydown', taste)
  void refreshSightingCounts()

  // Nach Reload liegt evtl. nur der Token vor — Admin-Daten nachladen.
  if (!auth.admin) {
    try {
      await auth.fetchMe()
    } catch {
      // 401 wird vom Axios-Interceptor zum Login geleitet.
    }
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('click', klickAussen)
  document.removeEventListener('keydown', taste)
})

/** Initiale für das Benutzer-Symbol; ohne geladene Daten ein neutrales Symbol. */
const initiale = computed(() => auth.admin?.email?.charAt(0).toUpperCase() ?? '')

async function logout(): Promise<void> {
  offen.value = null
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <header ref="kopf" class="relative z-40 border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-3">
      <div class="flex items-center gap-6">
        <RouterLink to="/fahrplan" class="whitespace-nowrap text-lg font-semibold text-slate-900">MD-Takt</RouterLink>

        <nav class="flex items-center gap-1 text-sm">
          <div v-for="g in gruppen" :key="g.key" class="relative">
            <button
              type="button"
              class="inline-flex items-center gap-1 whitespace-nowrap rounded-md px-3 py-1.5 transition"
              :class="
                offen === g.key || aktiveGruppe === g.key
                  ? 'bg-slate-100 font-medium text-slate-900'
                  : 'text-slate-600 hover:bg-slate-100'
              "
              :aria-expanded="offen === g.key"
              aria-haspopup="menu"
              @click="schalte(g.key)"
            >
              {{ g.label }}
              <svg class="h-3.5 w-3.5 text-slate-400 transition" :class="{ 'rotate-180': offen === g.key }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
              </svg>
            </button>
            <div
              v-if="offen === g.key"
              role="menu"
              class="absolute left-0 top-full mt-1 min-w-44 rounded-md border border-slate-200 bg-white py-1 shadow-lg"
            >
              <RouterLink
                v-for="e in g.eintraege"
                :key="e.to"
                :to="e.to"
                role="menuitem"
                class="block px-4 py-2 text-slate-700 hover:bg-slate-50"
                :class="{ 'bg-slate-100 font-medium text-slate-900': istAktiv(e.to) }"
              >
                {{ e.label }}
              </RouterLink>
            </div>
          </div>

          <RouterLink
            to="/sichtungen"
            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100"
            active-class="bg-slate-100 font-medium text-slate-900"
          >
            Sichtungen
            <span
              v-if="sightingCounts && sightingCounts.open > 0"
              class="rounded-full bg-red-600 px-1.5 text-xs font-semibold leading-5 text-white"
              :title="`${sightingCounts.open} offen · ${sightingCounts.waiting} warten auf den Fahrplan`"
            >
              {{ sightingCounts.open }}
            </span>
          </RouterLink>
        </nav>
      </div>

      <!-- Benutzer: Symbol statt Adresse; der Name steht im Tooltip, Abmelden im Menü dahinter. -->
      <div class="relative">
        <button
          type="button"
          class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-800 text-sm font-semibold text-white hover:bg-slate-700"
          :title="auth.admin?.email ?? 'Angemeldet'"
          :aria-expanded="offen === 'user'"
          aria-haspopup="menu"
          @click="schalte('user')"
        >
          <template v-if="initiale">{{ initiale }}</template>
          <svg v-else class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M10 10a4 4 0 100-8 4 4 0 000 8zm-7 8a7 7 0 0114 0H3z" />
          </svg>
        </button>
        <div
          v-if="offen === 'user'"
          role="menu"
          class="absolute right-0 top-full mt-1 min-w-56 rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg"
        >
          <div class="border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
            Angemeldet als<br />
            <span class="text-sm text-slate-800">{{ auth.admin?.email ?? '—' }}</span>
          </div>
          <button type="button" role="menuitem" class="block w-full px-4 py-2 text-left text-slate-700 hover:bg-slate-50" @click="logout">
            Abmelden
          </button>
        </div>
      </div>
    </div>
  </header>
</template>
