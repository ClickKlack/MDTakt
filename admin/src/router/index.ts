import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import LoginView from '../views/LoginView.vue'
import ImportsView from '../views/ImportsView.vue'
import LinesView from '../views/LinesView.vue'
import LineColorsView from '../views/LineColorsView.vue'
import CalendarConfigView from '../views/CalendarConfigView.vue'
import ScheduleVersionsView from '../views/ScheduleVersionsView.vue'
import SchedulePeriodsView from '../views/SchedulePeriodsView.vue'
import CoverageView from '../views/CoverageView.vue'
import TimetableView from '../views/TimetableView.vue'
import ScheduleDiffView from '../views/ScheduleDiffView.vue'
import StopLinksView from '../views/StopLinksView.vue'
import StopGroupsView from '../views/StopGroupsView.vue'
import CoursesView from '../views/CoursesView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
    { path: '/imports', name: 'imports', component: ImportsView },
    { path: '/lines', name: 'lines', component: LinesView },
    { path: '/lines/colors', name: 'line-colors', component: LineColorsView },
    { path: '/calendar', name: 'calendar', component: CalendarConfigView },
    { path: '/versions', name: 'versions', component: ScheduleVersionsView },
    // Erste Route mit Query-Auswahl: Ein Fahrplan soll verlinkbar sein.
    { path: '/fahrplan', name: 'timetable', component: TimetableView },
    { path: '/versions/diff', name: 'versions-diff', component: ScheduleDiffView },
    // Umlauf-Pflege: Haltestelle, Periode, Fahrplantyp und Versionsstand in der Query,
    // damit ein Pflegestand verlinkbar bleibt.
    { path: '/anschluesse', name: 'stop-links', component: StopLinksView },
    // Pflege der Haltestellen-Klammer: welche Halte sind im Betrieb derselbe Ort.
    { path: '/haltestellen', name: 'stop-groups', component: StopGroupsView },
    // Umlaeufe je Linie; Auswahl in der Query, damit eine Kursliste verlinkbar bleibt.
    { path: '/kurse', name: 'courses', component: CoursesView },
    { path: '/periods', name: 'periods', component: SchedulePeriodsView },
    { path: '/coverage', name: 'coverage', component: CoverageView },
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
