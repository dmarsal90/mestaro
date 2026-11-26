import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useSession } from '../stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    permission?: string
    nav?: string // label in the main navigation; only routes the user may open are listed
  }
}

export const routes: RouteRecordRaw[] = [
  { path: '/accept-invitation', name: 'accept-invitation', component: () => import('../pages/AcceptInvitationPage.vue'), meta: { public: true } },
  { path: '/login', name: 'login', component: () => import('../pages/LoginPage.vue'), meta: { public: true } },
  {
    path: '/',
    component: () => import('../layouts/AppLayout.vue'),
    children: [
      { path: '', name: 'home', component: () => import('../pages/HomePage.vue'), meta: { nav: 'Inicio' } },
      { path: 'sala', name: 'service', component: () => import('../pages/ServicePage.vue'), meta: { nav: 'Sala', permission: 'orders.view' } },
      { path: 'carta', name: 'menu', component: () => import('../pages/MenuPage.vue'), meta: { nav: 'Carta', permission: 'menu.manage' } },
      { path: 'salon', name: 'floor', component: () => import('../pages/FloorPage.vue'), meta: { nav: 'Salón', permission: 'floor.manage' } },
      { path: 'equipo', name: 'staff', component: () => import('../pages/StaffPage.vue'), meta: { nav: 'Equipo', permission: 'users.manage' } },
      { path: 'ajustes', name: 'settings', component: () => import('../pages/SettingsPage.vue'), meta: { nav: 'Ajustes', permission: 'restaurant.manage' } },
      { path: 'forbidden', name: 'forbidden', component: () => import('../pages/ForbiddenPage.vue') },
      { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('../pages/NotFoundPage.vue') },
    ],
  },
]

/** Only same-site paths: a `redirect` query must never send the user to another origin after login. */
export const safeRedirect = (value: unknown) => (typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.startsWith('/\\') ? value : '/')

export function createAppRouter(history = createWebHistory()) {
  const router = createRouter({ history, routes })

  router.beforeEach(async (to) => {
    const session = useSession()
    if (!session.ready) await session.restore()
    if (to.meta.public) return session.isAuthenticated && to.name === 'login' ? '/' : true
    if (!session.isAuthenticated) return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
    if (to.meta.permission && !session.can(to.meta.permission)) return { name: 'forbidden' }
    return true
  })
  return router
}
