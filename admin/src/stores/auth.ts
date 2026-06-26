import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api, { TOKEN_KEY } from '../services/api'

export interface Admin {
  id: number
  name: string
  email: string
}

/**
 * Auth-Store (Single-Admin). Hält den Sanctum-Token (persistiert im localStorage)
 * und die Admin-Daten. Quelle der Wahrheit für den Router-Guard.
 */
export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const admin = ref<Admin | null>(null)

  const isAuthenticated = computed(() => token.value !== null)

  function setToken(value: string | null): void {
    token.value = value
    if (value) {
      localStorage.setItem(TOKEN_KEY, value)
    } else {
      localStorage.removeItem(TOKEN_KEY)
    }
  }

  async function login(email: string, password: string): Promise<void> {
    const { data } = await api.post('/api/v1/admin/login', { email, password })
    setToken(data.token)
    admin.value = data.data
  }

  async function fetchMe(): Promise<void> {
    const { data } = await api.get('/api/v1/admin/me')
    admin.value = data.data
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/api/v1/admin/logout')
    } catch {
      // Token evtl. schon serverseitig ungültig — lokal trotzdem aufräumen.
    }
    setToken(null)
    admin.value = null
  }

  return { token, admin, isAuthenticated, login, fetchMe, logout, setToken }
})
