import { Suspense, lazy, useEffect, useState } from 'react'
import { useAuthedApi } from '../api'
import type { Role } from '../types'
import type { AdminStats } from './AdminStats'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

const AdminStatsView = lazy(() => import('./AdminStats'))

type CoreData = {
  kpis: Record<string, number>
  latest_bookings: { id: number; hall_id: number; date: string; start_time: string; end_time: string; activity_name?: string }[]
}

const C = { navy: '#0A1D3B', red: '#782B19' }

const statsFallback = (
  <div className="d-flex align-items-center justify-content-center py-4">
    <div className="spinner-border spinner-border-sm" role="status" aria-label="جارٍ تجهيز الرسوم" style={{ color: '#B26225' }} />
  </div>
)

export default function DashboardView({ call, role }: { call: ReturnType<typeof useAuthedApi>; role?: Role }) {
  const [core, setCore] = useState<CoreData | null>(null)
  const [stats, setStats] = useState<AdminStats | null>(null)
  const [statsErr, setStatsErr] = useState<string | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)
  const isAdmin = role === 'admin'

  useEffect(() => {
    call<CoreData>('/api/dashboard').then(setCore).catch((e) => setErr((e as Error).message))
    if (isAdmin) {
      call<AdminStats>('/api/admin/dashboard-stats').then(setStats).catch((e) => setStatsErr((e as Error).message))
    }
  }, [call, isAdmin, reloadKey])

  if (err) return <ErrorRetry message={err} onRetry={() => { setErr(null); setReloadKey((k) => k + 1) }} />
  if (!core) return <SkeletonRows rows={4} height={80} />

  const classicCards: { key: keyof CoreData['kpis']; label: string }[] = [
    { key: 'halls_registered', label: 'القاعات المسجلة' },
    { key: 'lectures_this_term', label: 'محاضرات الفصل' },
    { key: 'bookings_today', label: 'حجوزات اليوم' },
    { key: 'exams_this_week', label: 'امتحانات الأسبوع' },
    { key: 'batches_count', label: 'عدد الدفعات' },
    { key: 'courses_count', label: 'عدد المقررات' },
  ]

  return (
    <>
      <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <h1 className="h3 fw-bold mb-0" style={{ color: C.navy }}>
          {isAdmin ? 'لوحة تحكم مدير النظام' : 'لوحة التحكم'}
        </h1>
        {isAdmin && stats && <span className="badge" style={{ background: C.navy, color: '#fff' }}>{stats.kpis.groups_count} شعبة · {stats.kpis.batches_count} دفعة · {stats.kpis.courses_count} مقرر</span>}
      </div>

      {/* ── المؤشرات + الخريطة والرسوم (المدير فقط)؛ بقية الأدوار تحصل على شريط كلاسيكي ── */}
      {isAdmin ? (
        <>
          {statsErr && (
            <div className="alert alert-danger d-flex align-items-center justify-content-between gap-2" role="alert">
              <span>تعذر تحميل لوحة المدير: {statsErr}</span>
              <button className="aust-btn-outline btn-sm" onClick={() => { setStatsErr(null); call<AdminStats>('/api/admin/dashboard-stats').then(setStats).catch((e) => setStatsErr((e as Error).message)) }}>إعادة المحاولة</button>
            </div>
          )}
          <Suspense fallback={statsFallback}>
            {stats && <AdminStatsView stats={stats} />}
          </Suspense>
        </>
      ) : (
        <div className="row g-3 mb-4">
          {classicCards.map((c) => (
            <div className="col-6 col-md-4 col-xl-2" key={c.key}>
              <div className="aust-panel p-3 text-center h-100">
                <div className="fs-2 fw-bold" style={{ color: C.navy }}>{core.kpis[c.key] ?? 0}</div>
                <div className="small text-muted">{c.label}</div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* ── آخر الحجوزات ── */}
      <h2 className="h5 fw-bold" style={{ color: C.red }}>آخر الحجوزات</h2>
      <table className="table table-sm aust-panel mt-2">
        <thead className="table-light"><tr><th>القاعة</th><th>التاريخ</th><th>من</th><th>إلى</th><th>النشاط</th></tr></thead>
        <tbody>
          {core.latest_bookings.length === 0 && <tr><td colSpan={5} className="text-muted">لا توجد حجوزات حديثة</td></tr>}
          {core.latest_bookings.map((b) => (
            <tr key={b.id}><td>#{b.hall_id}</td><td>{b.date}</td><td>{b.start_time}</td><td>{b.end_time}</td><td>{b.activity_name ?? '—'}</td></tr>
          ))}
        </tbody>
      </table>
    </>
  )
}
