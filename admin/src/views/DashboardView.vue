<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

onMounted(async () => {
  // Admin-Daten laden, falls nach Reload nur der Token vorliegt.
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
  <div class="min-h-full bg-slate-100">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-3">
        <h1 class="text-lg font-semibold text-slate-900">MD-Takt — Schaltzentrale</h1>
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

    <main class="mx-auto max-w-5xl px-6 py-8">
      <p class="text-slate-600">
        Angemeldet als <strong>{{ auth.admin?.name }}</strong>.
      </p>
      <div class="mt-6 rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-slate-400">
        Import-Auditing und Matching-Workflow folgen (ROADMAP I-12c / I-12b).
      </div>
    </main>
  </div>
</template>
