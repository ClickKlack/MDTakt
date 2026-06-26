import axios from 'axios'

/** Schlüssel des Sanctum-Tokens im localStorage (Single-Admin). */
export const TOKEN_KEY = 'mdtakt_admin_token'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000',
  headers: { Accept: 'application/json' },
})

// Request: Bearer-Token anhängen, falls vorhanden.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Response: bei 401 Token verwerfen und zum Login leiten — außer beim Login selbst
// (dort wird der Fehler im Formular behandelt).
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const url: string = error.config?.url ?? ''
    const isLogin = url.includes('/admin/login')
    if (error.response?.status === 401 && !isLogin) {
      localStorage.removeItem(TOKEN_KEY)
      if (window.location.pathname !== '/login') {
        window.location.assign('/login')
      }
    }
    return Promise.reject(error)
  },
)

export default api
