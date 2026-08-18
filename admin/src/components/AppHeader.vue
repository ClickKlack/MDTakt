<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

onMounted(async () => {
  // Nach Reload liegt evtl. nur der Token vor — Admin-Daten nachladen.
  if (!auth.admin) {
    try {
      await auth.fetchMe()
    } catch {
      // 401 wird vom Axios-Interceptor zum Login geleitet.
    }
  }
})

async function logout(): Promise<void> {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-3">
      <div class="flex items-center gap-6">
        <span class="text-lg font-semibold text-slate-900">MD-Takt — Schaltzentrale</span>
        <nav class="flex gap-1 text-sm">
          <RouterLink
            to="/lines"
            class="rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100"
            active-class="bg-slate-100 font-medium text-slate-900"
          >
            Linien
          </RouterLink>
          <RouterLink
            to="/versions"
            class="rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100"
            active-class="bg-slate-100 font-medium text-slate-900"
          >
            Versionen
          </RouterLink>
          <RouterLink
            to="/calendar"
            class="rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100"
            active-class="bg-slate-100 font-medium text-slate-900"
          >
            Kalender
          </RouterLink>
          <RouterLink
            to="/imports"
            class="rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100"
            active-class="bg-slate-100 font-medium text-slate-900"
          >
            Import-Auditing
          </RouterLink>
        </nav>
      </div>
      <div class="flex items-center gap-4 text-sm">
        <span class="text-slate-500">{{ auth.admin?.email }}</span>
        <button
          class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50"
          @click="logout"
        >
          Abmelden
        </button>
      </div>
    </div>
  </header>
</template>
