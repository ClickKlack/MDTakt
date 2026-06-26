import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import LoginView from '../views/LoginView.vue'
import ImportsView from '../views/ImportsView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
    { path: '/imports', name: 'imports', component: ImportsView },
    { path: '/', redirect: '/imports' },
    { path: '/:pathMatch(.*)*', redirect: '/imports' },
  ],
})

// Auth-Guard: geschützte Routen erfordern einen Token; eingeloggt nicht zum Login.
router.beforeEach((to) => {
  const auth = useAuthStore()

  if (!to.meta.public && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: 'imports' }
  }
})
