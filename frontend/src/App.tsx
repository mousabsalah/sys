import { Suspense, lazy, useState } from 'react'
import { useAuthedApi } from './api'
import { Shell } from './views/Shell'
import type { AuthUser, ViewName } from './types'

import AuthView from './views/Auth'
const DashboardView = lazy(() => import('./views/Dashboard'))
const TimetablesView = lazy(() => import('./views/Timetables'))
const CreateTimetableView = lazy(() => import('./views/CreateTimetable'))
const ProposeView = lazy(() => import('./views/Propose'))
const ExamsView = lazy(() => import('./views/Exams'))
const CreateExamView = lazy(() => import('./views/CreateExam'))
const BookingsView = lazy(() => import('./views/Bookings'))
const HallsAvailableView = lazy(() => import('./views/HallsAvailable'))
const CreateBookingView = lazy(() => import('./views/CreateBooking'))
const CouncilLecturersView = lazy(() => import('./views/CouncilLecturers'))
const AdminApprovalsView = lazy(() => import('./views/AdminApprovals'))
const LecturerView = lazy(() => import('./views/LecturerView'))
const SubstituteRequestsView = lazy(() => import('./views/SubstituteRequestsView'))
const GuardTodayView = lazy(() => import('./views/GuardToday'))
const ReportsView = lazy(() => import('./views/Reports'))
const AdminUsersView = lazy(() => import('./views/AdminUsers'))
const AdminFacultiesView = lazy(() => import('./views/AdminFaculties'))
const AdminDepartmentsView = lazy(() => import('./views/AdminDepartments'))
const AdminHallsView = lazy(() => import('./views/AdminHalls'))
const AdminSemestersView = lazy(() => import('./views/AdminSemesters'))
const AdminArchiveView = lazy(() => import('./views/AdminArchive'))
const AdminPlansView = lazy(() => import('./views/AdminPlans'))

const fallback = (
  <div className="d-flex align-items-center justify-content-center py-5">
    <div className="spinner-border" role="status" aria-label="جارٍ التحميل" style={{ color: '#B26225' }} />
  </div>
)

// صفحات مخفية عن مدير النظام — حتى لو وصل إليها بأي طريق تعود إلى اللوحة
const ADMIN_HIDDEN: ReadonlySet<ViewName> = new Set(['timetables', 'exams', 'halls-available', 'create-booking', 'create-timetable', 'create-exam', 'propose'])

export default function App() {
  const [auth, setAuth] = useState<{ user: AuthUser; token: string } | null>(null)
  const [view, setView] = useState<ViewName>('login')
  const [bookingHallId, setBookingHallId] = useState<number | null>(null)
  const call = useAuthedApi(auth?.token ?? null)

  if (!auth) {
    return (
      <Suspense fallback={fallback}>
        <AuthView onAuthed={(user, token) => { sessionStorage.setItem('aust_token', token); setAuth({ user, token }); setView(user.role === 'guard' ? 'guard-today' : 'dashboard') }} />
      </Suspense>
    )
  }

  const activeView = auth.user.role === 'admin' && ADMIN_HIDDEN.has(view)
    ? 'dashboard'
    : auth.user.role === 'guard' && view !== 'guard-today'
      ? 'guard-today' // الحارس: شاشة «قاعات اليوم» فقط
      : view

  return (
    <Shell user={auth.user} active={activeView} onNav={setView} onLogout={() => { sessionStorage.removeItem('aust_token'); setAuth(null); setView('login') }}>
      <Suspense fallback={fallback}>
        {activeView === 'dashboard' && <DashboardView call={call} role={auth.user.role} />}
        {activeView === 'timetables' && <TimetablesView call={call} onNew={() => setView('create-timetable')} />}
        {activeView === 'create-timetable' && <CreateTimetableView call={call} onDone={() => setView('timetables')} />}
        {activeView === 'propose' && <ProposeView call={call} onAdopted={() => setView('timetables')} />}
        {activeView === 'exams' && <ExamsView call={call} onNew={() => setView('create-exam')} />}
        {activeView === 'create-exam' && <CreateExamView call={call} onDone={() => setView('exams')} />}
        {activeView === 'bookings' && <BookingsView call={call} />}
        {activeView === 'halls-available' && <HallsAvailableView call={call} onBook={(id) => { setBookingHallId(id); setView('create-booking') }} canBook={auth.user.role !== 'guard'} />}
        {activeView === 'create-booking' && <CreateBookingView call={call} onDone={() => setView('bookings')} hallId={bookingHallId} />}
        {activeView === 'council-lecturers' && <CouncilLecturersView call={call} />}
        {activeView === 'admin-approvals' && <AdminApprovalsView call={call} />}
        {activeView === 'lecturer' && <LecturerView call={call} />}
        {activeView === 'substitute-requests' && <SubstituteRequestsView call={call} />}
        {activeView === 'guard-today' && <GuardTodayView call={call} />}
        {activeView === 'reports' && <ReportsView call={call} />}
        {activeView === 'admin-users' && <AdminUsersView call={call} />}
        {activeView === 'admin-plans' && <AdminPlansView call={call} />}
        {activeView === 'admin-faculties' && <AdminFacultiesView call={call} />}
        {activeView === 'admin-departments' && <AdminDepartmentsView call={call} />}
        {activeView === 'admin-halls' && <AdminHallsView call={call} />}
        {activeView === 'admin-semesters' && <AdminSemestersView call={call} />}
        {activeView === 'admin-archive' && <AdminArchiveView call={call} />}
      </Suspense>
    </Shell>
  )
}
