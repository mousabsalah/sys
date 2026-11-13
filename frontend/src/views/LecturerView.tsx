import { useEffect, useMemo, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type GroupInfo = { id: number; batch: string; track: string; student_count: number; department: string }
type Row = {
  id: number
  group_id: number
  course_id: number
  faculty_id: number | null
  hall_id: number
  day_of_week: string
  start_time: string
  end_time: string
  academic_term: string
  status: 'pending' | 'approved' | 'cancelled'
  course_code: string | null
  course_name: string | null
  hall_name: string | null
  faculty_name: string | null
}

type SubstituteTarget = { id: number; full_name: string; degree: string | null; email: string; phone: string | null }

export default function LecturerView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [groups, setGroups] = useState<GroupInfo[]>([])
  const [groupId, setGroupId] = useState<number | 'all'>('all')
  const [rows, setRows] = useState<Row[]>([])
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [reloadKey, setReloadKey] = useState(0)
  const [myLecturerId, setMyLecturerId] = useState<number | null>(null)

  // نافذة طلب بديل
  const [subTarget, setSubTarget] = useState<Row | null>(null)
  const [targets, setTargets] = useState<SubstituteTarget[]>([])
  const [pick, setPick] = useState('')
  const [busy, setBusy] = useState<string | null>(null)

  useEffect(() => { call<{ lecturer_id?: number | null }>('/api/me').then((r) => setMyLecturerId(r.lecturer_id ?? null)).catch(() => setMyLecturerId(null)) }, [call])

  async function load() {
    setLoading(true)
    try {
      const qs = groupId === 'all' ? '' : `?group_id=${groupId}`
      const data = await call<{ groups: GroupInfo[]; rows: Row[] }>(`/api/lecturer/timetables${qs}`)
      setGroups(data.groups ?? [])
      setRows(data.rows ?? [])
      setErr(null)
    } catch (e) { setErr((e as Error).message) } finally { setLoading(false) }
  }
  useEffect(() => { load() }, [groupId, reloadKey, call])

  const myRows = useMemo(
    () => rows.filter((r) => myLecturerId !== null && r.faculty_id === myLecturerId),
    [rows, myLecturerId],
  )

  async function toggleCancel(row: Row) {
    const cancel = row.status !== 'cancelled'
    setBusy(`cancel:${row.id}`)
    setErr(null); setMsg(null)
    try {
      await call(`/api/lecturer/timetables/${row.id}/${cancel ? 'cancel' : 'uncancel'}`, 'POST')
      setMsg(cancel ? 'المحاضرة ألغيت ✓ (يمكن التراجع لاحقًا)' : 'تمت إعادة تفعيل المحاضرة ✓')
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(null) }
  }

  async function openSubstitute(row: Row) {
    setErr(null); setMsg(null)
    try {
      const data = await call<{ lecturers: SubstituteTarget[] }>('/api/lecturer/lecturers')
      setTargets(data.lecturers ?? [])
      setSubTarget(row)
      setPick('')
    } catch (e) { setErr((e as Error).message) }
  }

  async function submitSubstitute() {
    if (!subTarget || !pick) return
    setBusy('sub')
    try {
      await call(`/api/lecturer/timetables/${subTarget.id}/substitute-request`, 'POST', { substitute_lecturer_id: Number(pick) })
      setMsg('أُرسل طلب البديل — يتفاوض المدرسون مباشرةً ويقبل المستهدف أو يرفض')
      setSubTarget(null)
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setBusy(null) }
  }

  if (err && rows.length === 0 && groups.length === 0) return <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />
  if (loading && groups.length === 0) return <SkeletonRows rows={6} height={42} />

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>جدولي</h1>
      <p className="small text-muted mb-3">جدول الشعب التي يدرّس فيها — صف عن صف، مع إلغاء محاضراتك وطلب بديل من زملائك في نفس الشعب.</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />}

      <div className="aust-panel p-3 mb-3 d-flex align-items-center gap-2 flex-wrap">
        <label className="form-label m-0 align-self-center" htmlFor="lg-grp">الشعبة:</label>
        <select id="lg-grp" className="form-select" style={{ maxWidth: 360 }} value={groupId} onChange={(e) => setGroupId(e.target.value === 'all' ? 'all' : Number(e.target.value))} aria-label="الشعبة">
          <option value="all">كل الشعب التي أدرّس فيها ({groups.length})</option>
          {groups.map((g) => <option key={g.id} value={g.id}>{g.batch} — {g.track} ({g.student_count} طالب)</option>)}
        </select>
        <span className="small text-muted ms-auto">محاضراتي: <strong>{myRows.length}</strong></span>
      </div>

      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light"><tr><th>اليوم</th><th>من</th><th>إلى</th><th>الشعبة</th><th>المقرر</th><th>القاعة</th><th>المدرّس</th><th>الحالة</th><th></th></tr></thead>
          <tbody>
            {rows.map((r) => {
              const cancelled = r.status === 'cancelled'
              const mine = myLecturerId !== null && r.faculty_id === myLecturerId
              const group = groups.find((g) => g.id === r.group_id)
              return (
                <tr key={r.id} style={cancelled ? { opacity: 0.55 } : undefined}>
                  <td>{r.day_of_week}</td>
                  <td dir="ltr">{r.start_time.slice(0, 5)}</td>
                  <td dir="ltr">{r.end_time.slice(0, 5)}</td>
                  <td className="small">{group ? `${group.batch} — ${group.track}` : `#${r.group_id}`}</td>
                  <td><span dir="ltr" className="badge text-bg-light border">{r.course_code}</span> {r.course_name}</td>
                  <td>{r.hall_name ?? '—'}</td>
                  <td>{r.faculty_name ?? '—'}</td>
                  <td>{cancelled
                    ? <span className="badge" style={{ background: '#6c757d', color: '#fff' }}>ملغية</span>
                    : <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>معتمدة</span>}
                  </td>
                  <td className="d-flex gap-1">
                    {mine && (
                      <>
                        <button className="aust-btn-outline btn-sm" disabled={busy === `cancel:${r.id}`} onClick={() => toggleCancel(r)}>
                          {cancelled ? 'إرجاع' : 'إلغاء'}
                        </button>
                        {!cancelled && <button className="aust-btn-outline btn-sm" onClick={() => openSubstitute(r)}>طلب بديل</button>}
                      </>
                    )}
                  </td>
                </tr>
              )
            })}
            {rows.length === 0 && <tr><td colSpan={9} className="text-muted">لا توجد محاضرات في النطاق — تواصل مع مجلس المدرسين</td></tr>}
          </tbody>
        </table>
      </div>

      {/* ── نافذة طلب بديل ── */}
      {subTarget && (
        <div className="modal show d-block" tabIndex={-1} role="dialog" style={{ background: 'rgba(0,0,0,0.45)' }}>
          <div className="modal-dialog">
            <div className="modal-content">
              <div className="modal-header">
                <h5 className="modal-title">طلب بديل — {subTarget.day_of_week} {subTarget.start_time.slice(0, 5)}</h5>
                <button type="button" className="btn-close" aria-label="إغلاق" onClick={() => setSubTarget(null)} />
              </div>
              <div className="modal-body">
                <p className="small text-muted">اختر مدرسًا من نفس شعبتك — يرى الطلب في «طلبات البدلاء» ويوافق أو يرفض مباشرةً (لا حاجة لموافقة المجلس).</p>
                <div className="list-group">
                  {targets.map((t) => (
                    <label key={t.id} className="list-group-item d-flex align-items-center gap-2" style={{ cursor: 'pointer' }}>
                      <input type="radio" name="sub-target" value={t.id} checked={pick === String(t.id)} onChange={() => setPick(String(t.id))} />
                      <span><strong>{t.full_name}</strong>{t.degree ? <span className="text-muted small"> — {t.degree}</span> : null}</span>
                    </label>
                  ))}
                  {targets.length === 0 && <div className="text-muted small p-2">لا يوجد مدرسون آخرون يدرّسون في شعبك</div>}
                </div>
              </div>
              <div className="modal-footer">
                <button className="aust-btn-outline" onClick={() => setSubTarget(null)}>إلغاء</button>
                <button className="aust-btn" disabled={!pick || busy === 'sub'} onClick={submitSubstitute}>{busy === 'sub' ? '…' : 'إرسال الطلب'}</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
