import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

export default function DashboardView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [data, setData] = useState<{kpis: Record<string, number>, latest_bookings: {id:number, hall_id:number, date:string, start_time:string, end_time:string, activity_name?:string}[]} | null>(null)
  const [err, setErr] = useState<string | null>(null)
  useEffect(() => {
    call<{kpis: Record<string, number>, latest_bookings: {id:number, hall_id:number, date:string, start_time:string, end_time:string, activity_name?:string}[]}>('/api/dashboard')
      .then(setData)
      .catch((e) => setErr((e as Error).message))
  }, [call])

  if (err) return <div className="alert alert-danger">{err}</div>
  if (!data) return <div>جارٍ التحميل…</div>

  const cards: { key: keyof typeof data.kpis; label: string }[] = [
    { key: 'halls_registered', label: 'القاعات المسجلة' },
    { key: 'lectures_this_term', label: 'محاضرات الفصل' },
    { key: 'bookings_today', label: 'حجوزات اليوم' },
    { key: 'exams_this_week', label: 'امتحانات الأسبوع' },
    { key: 'batches_count', label: 'عدد الدفعات' },
    { key: 'courses_count', label: 'عدد المقررات' },
  ]

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>لوحة التحكم</h1>
      <div className="row g-3 mb-4">
        {cards.map((c) => (
          <div className="col-6 col-md-4 col-xl-2" key={c.key}>
            <div className="aust-panel p-3 text-center">
              <div className="fs-2 fw-bold" style={{color: '#0A1D3B'}}>{data.kpis[c.key] ?? 0}</div>
              <div className="small text-muted">{c.label}</div>
            </div>
          </div>
        ))}
      </div>
      <h2 className="h5 fw-bold" style={{color: '#782B19'}}>آخر الحجوزات</h2>
      <table className="table table-sm aust-panel mt-2">
        <thead className="table-light"><tr><th>القاعة</th><th>التاريخ</th><th>من</th><th>إلى</th><th>النشاط</th></tr></thead>
        <tbody>
          {data.latest_bookings.length === 0 && <tr><td colSpan={5} className="text-muted">لا توجد حجوزات حديثة</td></tr>}
          {data.latest_bookings.map((b) => (
            <tr key={b.id}><td>#{b.hall_id}</td><td>{b.date}</td><td>{b.start_time}</td><td>{b.end_time}</td><td>{b.activity_name ?? '—'}</td></tr>
          ))}
        </tbody>
      </table>
    </>
  )
}