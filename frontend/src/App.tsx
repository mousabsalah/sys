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
const ReportsView = lazy(() => import('./views/Reports'))
const MasterDataView = lazy(() => import('./views/Admin'))
const AdminUsersView = lazy(() => import('./views/AdminUsers'))

const fallback = (
  <div className="d-flex align-items-center justify-content-center py-5">
    <div className="spinner-border" role="status" aria-label="جارٍ التحميل" style={{ color: '#B26225' }} />
  </div>
)

export default function App() {
  const [auth, setAuth] = useState<{ user: AuthUser; token: string } | null>(null)
  const [view, setView] = useState<ViewName>('login')
  const [bookingHallId, setBookingHallId] = useState<number | null>(null)
  const call = useAuthedApi(auth?.token ?? null)

  if (!auth) {
    return (
      <Suspense fallback={fallback}>
        <AuthView onAuthed={(user, token) => { setAuth({ user, token }); setView('dashboard') }} />
      </Suspense>
    )
  }

  return (
    <Shell user={auth.user} active={view} onNav={setView} onLogout={() => { setAuth(null); setView('login') }}>
      <Suspense fallback={fallback}>
        {view === 'dashboard' && <DashboardView call={call} />}
        {view === 'timetables' && <TimetablesView call={call} onNew={() => setView('create-timetable')} />}
        {view === 'create-timetable' && <CreateTimetableView call={call} onDone={() => setView('timetables')} />}
        {view === 'propose' && <ProposeView call={call} onAdopted={() => setView('timetables')} />}
        {view === 'exams' && <ExamsView call={call} onNew={() => setView('create-exam')} />}
        {view === 'create-exam' && <CreateExamView call={call} onDone={() => setView('exams')} />}
        {view === 'bookings' && <BookingsView call={call} />}
        {view === 'halls-available' && <HallsAvailableView call={call} onBook={(id) => { setBookingHallId(id); setView('create-booking') }} canBook={auth.user.role !== 'guard'} />}
        {view === 'create-booking' && <CreateBookingView call={call} onDone={() => setView('bookings')} hallId={bookingHallId} />}
        {view === 'reports' && <ReportsView call={call} />}
        {view === 'admin' && <MasterDataView call={call} />}
        {view === 'admin-users' && <AdminUsersView call={call} />}
      </Suspense>
    </Shell>
  )
}
