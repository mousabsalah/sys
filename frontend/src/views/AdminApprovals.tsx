import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type PendingLecture = {
  id: number
  day_of_week: string
  start_time: string
  end_time: string
  academic_term: string
  group_id: number
  group_track: string | null
  batch_name: string | null
  course_code: string | null
  course_name: string | null
  hall_name: string | null
  faculty_name: string | null
}

type PendingExam = {
  id: number
  exam_date: string
  start_time: string
  end_time: string
  exam_type: string | null
  academic_term: string
  group_id: number
  group_track: string | null
  batch_name: string | null
  course_code: string | null
  course_name: string | null
  hall_name: string | null
}

type ApprovalsResponse = {
  pending_lectures: PendingLecture[]
  pending_exams: PendingExam[]
  pending_lectures_count: number
  pending_exams_count: number
}

export default function AdminApprovalsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [data, setData] = useState<ApprovalsResponse | null>(null)
  const [loaded, setLoaded] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  async function load() {
    try {
      setData(await call<ApprovalsResponse>('/api/admin/approvals'))
      setErr(null)
    } catch (e) { setErr((e as Error).message) } finally { setLoaded(true) }
  }
  useEffect(() => { load() }, [call])

  async function act(kind: 'timetables' | 'exams', id: number, approve: boolean) {
    setBusy(`${kind}:${id}`)
    setErr(null); setMsg(null)
    try {
      await call(`/api/admin/approvals/${kind}/${id}/${approve ? 'approve' : 'unapprove'}`, 'POST')
      setMsg(approve ? 'تم الاعتماد ✓ — ظهر الآن للجميع' : 'تم الإرجاع للانتظار')
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(null) }
  }

  if (err && !data) return <ErrorRetry message={err} onRetry={load} />
  if (!loaded) return <SkeletonRows rows={4} height={52} />

  const total = (data?.pending_lectures_count ?? 0) + (data?.pending_exams_count ?? 0)

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>اعتماد الجداول والامتحانات</h1>
      <p className="small text-muted mb-3">ما ينشئه مجلس المدرسين يبدأ «بانتظار الاعتماد» — لا يراه غير المجلس والمدير. وافق هنا ليظهر للبقية.</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={load} />}

      <div className="d-flex align-items-center gap-2 mb-3">
        <span className="badge" style={{ background: total === 0 ? '#1f7a3d' : '#B26225', color: '#fff', fontSize: '0.9rem' }}>{total === 0 ? 'لا يوجد معلّق' : `${total} بانتظار الاعتماد`}</span>
        <button className="aust-btn-outline btn-sm ms-auto" onClick={load}>تحديث</button>
      </div>

      <h2 className="h5 fw-bold mt-4 mb-2" style={{ color: '#782B19' }}>محاضرات معلّقة ({data?.pending_lectures_count ?? 0})</h2>
      <div className="table-responsive mb-4">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>اليوم</th><th>الدفعة/الشعبة</th><th>المقرر</th><th>من</th><th>إلى</th><th>القاعة</th><th>المدرّس</th><th></th></tr></thead>
          <tbody>
            {data?.pending_lectures.map((l) => (
              <tr key={l.id}>
                <td>{l.day_of_week}</td>
                <td>{l.batch_name ?? `#${l.group_id}`}{l.group_track ? <span className="text-muted small"> — {l.group_track}</span> : null}</td>
                <td><span dir="ltr" className="badge text-bg-light border">{l.course_code}</span> {l.course_name}</td>
                <td dir="ltr">{l.start_time.slice(0, 5)}</td>
                <td dir="ltr">{l.end_time.slice(0, 5)}</td>
                <td>{l.hall_name ?? '—'}</td>
                <td>{l.faculty_name ?? '—'}</td>
                <td className="d-flex gap-1">
                  <button className="aust-btn btn-sm" disabled={busy === `timetables:${l.id}`} onClick={() => act('timetables', l.id, true)}>اعتماد</button>
                </td>
              </tr>
            ))}
            {data?.pending_lectures.length === 0 && <tr><td colSpan={8} className="text-muted">لا توجد محاضرات معلّقة</td></tr>}
          </tbody>
        </table>
      </div>

      <h2 className="h5 fw-bold mt-4 mb-2" style={{ color: '#782B19' }}>امتحانات معلّقة ({data?.pending_exams_count ?? 0})</h2>
      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>التاريخ</th><th>الدفعة/الشعبة</th><th>المقرر</th><th>النوع</th><th>من</th><th>إلى</th><th>القاعة</th><th></th></tr></thead>
          <tbody>
            {data?.pending_exams.map((e) => (
              <tr key={e.id}>
                <td dir="ltr">{e.exam_date}</td>
                <td>{e.batch_name ?? `#${e.group_id}`}{e.group_track ? <span className="text-muted small"> — {e.group_track}</span> : null}</td>
                <td><span dir="ltr" className="badge text-bg-light border">{e.course_code}</span> {e.course_name}</td>
                <td>{e.exam_type ?? '—'}</td>
                <td dir="ltr">{e.start_time.slice(0, 5)}</td>
                <td dir="ltr">{e.end_time.slice(0, 5)}</td>
                <td>{e.hall_name ?? '—'}</td>
                <td className="d-flex gap-1">
                  <button className="aust-btn btn-sm" disabled={busy === `exams:${e.id}`} onClick={() => act('exams', e.id, true)}>اعتماد</button>
                </td>
              </tr>
            ))}
            {data?.pending_exams.length === 0 && <tr><td colSpan={8} className="text-muted">لا توجد امتحانات معلّقة</td></tr>}
          </tbody>
        </table>
      </div>

      <p className="small text-muted mt-3">بعد الاعتماد: تظهر للجميع · تُحتسب في التقارير · تظهر في «الجداول»/«الامتحانات» للدفعة والحرس.</p>
    </>
  )
}
