import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import LoginView from '../views/LoginView.vue'
import ImportsView from '../views/ImportsView.vue'
import LinesView from '../views/LinesView.vue'
import LineColorsView from '../views/LineColorsView.vue'
import CalendarConfigView from '../views/CalendarConfigView.vue'
import ScheduleVersionsView from '../views/ScheduleVersionsView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
    { path: '/imports', name: 'imports', component: ImportsView },
    { path: '/lines', name: 'lines', component: LinesView },
    { path: '/lines/colors', name: 'line-colors', component: LineColorsView },
    { path: '/calendar', name: 'calendar', component: CalendarConfigView },
    { path: '/versions', name: 'versions', component: ScheduleVersionsView },
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
