import { createRouter, createWebHistory } from 'vue-router'
import LoginView from './views/LoginView.vue'
import RegisterView from './views/RegisterView.vue'
import DashboardView from './views/DashboardView.vue'
import LeadsView from './views/LeadsView.vue'
import LeadDetailView from './views/LeadDetailView.vue'
import TasksView from './views/TasksView.vue'
import ComingSoonView from './views/ComingSoonView.vue'
import PreferredLocationsView from './views/PreferredLocationsView.vue'
import TeamView from './views/TeamView.vue'
import AcceptInvitationView from './views/AcceptInvitationView.vue'
import ForgotPasswordView from './views/ForgotPasswordView.vue'
import ResetPasswordView from './views/ResetPasswordView.vue'
import VerifyEmailView from './views/VerifyEmailView.vue'
import InventoryView from './views/InventoryView.vue'
import DealsView from './views/DealsView.vue'
import ReportsView from './views/ReportsView.vue'
import CommissionsView from './views/CommissionsView.vue'
import CommissionOrderView from './views/CommissionOrderView.vue'
import DealClosingDocumentView from './views/DealClosingDocumentView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginView, meta: { guest: true } },
    { path: '/register', component: RegisterView, meta: { guest: true } },
    { path: '/accept-invite', component: AcceptInvitationView, meta: { guest: true } },
    { path: '/forgot-password', component: ForgotPasswordView, meta: { guest: true, allowAuthenticated: true } },
    { path: '/reset-password', component: ResetPasswordView, meta: { guest: true, allowAuthenticated: true } },
    { path: '/verify-email', component: VerifyEmailView, meta: { guest: true, allowAuthenticated: true } },
    { path: '/', component: DashboardView },
    { path: '/leads', component: LeadsView },
    { path: '/leads/:id', component: LeadDetailView },
    { path: '/tasks', component: TasksView },
    { path: '/inventory', component: InventoryView },
    { path: '/deals', component: DealsView },
    { path: '/deals/:id/closing-document', component: DealClosingDocumentView },
    { path: '/commissions', component: CommissionsView },
    { path: '/commissions/:id/order', component: CommissionOrderView },
    { path: '/reports', component: ReportsView },
    { path: '/settings/locations', component: PreferredLocationsView },
    { path: '/team', component: TeamView },
    { path: '/:section', component: ComingSoonView },
  ],
})

export default router
