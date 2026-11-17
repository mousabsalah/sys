import { useEffect, useState } from 'react'
import { type Call } from '../api'

type PlanList = {
  groups: { id: number; batch_id: number; track: string; student_count: number; batch: { name: string } }[]
  plans: Record<number, { course_id: number; code: string; name: string; sessions_per_week: number }[]>
  courses: { id: number; code: string; name: string; credit_hours: number }[]
}

export default function CouncilPlansView({ call }: { call: Call }) {
  const [courses, setCourses] = useState<PlanList['courses']>([])
  const [data, setData] = useState<PlanList | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [editingGroup, setEditingGroup] = useState<number | null>(null)
  const [editItems, setEditItems] = useState<{ course_id: number; sessions_per_week: number }[]>([])
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<string | null>(null)

  async function load() {
    try {
      const pl = await call<PlanList>('/api/council/plans')
      setData(pl)
      setCourses(pl.courses ?? [])
      setErr(null)
    } catch (e) { setErr((e as Error).message) }
  }
  useEffect(() => { load() /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [])

  function startEdit(groupId: number) {
    const items = data?.plans?.[groupId] ?? []
    setEditItems(items.map((i) => ({ course_id: i.course_id, sessions_per_week: i.sessions_per_week })))
    setEditingGroup(groupId); setMsg(null)
  }
  function toggle(courseId: number) {
    setEditItems((prev) => (prev.some((i) => i.course_id === courseId)
      ? prev.filter((i) => i.course_id !== courseId)
      : [...prev, { course_id: courseId, sessions_per_week: courses.find((c) => c.id === courseId)?.credit_hours ?? 3 }]))
  }
  function setSessions(courseId: number, value: number) {
    setEditItems((prev) => prev.map((i) => (i.course_id === courseId ? { ...i, sessions_per_week: Math.max(1, Math.min(12, value)) } : i)))
  }
  async function save() {
    if (editingGroup === null) return
    const planSize = editItems.length
    if (!window.confirm(`حفظ الخطة لهذه الشعبة (${planSize} مقرر)؟`)) return
    setSaving(true); setMsg(null); setErr(null)
    try {
      await call(`/api/council/plans/${editingGroup}`, 'POST', { items: editItems })
      await load(); setEditingGroup(null); setMsg('تم حفظ الخطة ✓')
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  if (!data && err) return <div className="alert alert-danger">{err}</div>
  if (!data) return <div>جارٍ التحميل…</div>

  return (
    <>
      <h1 className="h3 fw-bold mb-1 aust-h1">الخطط الدراسية</h1>
      <p className="small text-muted mb-3">المواد المقررة لكل شعبة وعدد الحصص الأسبوعية — مدخل «الاقتراح التلقائي» (CP-SAT). مجموع الحصص يُفضّل أن لا يتجاوز شبكة الفترات (٦ أيام × ٣ فترات = 18).</p>
      {err && <div className="alert alert-danger py-2">{err}</div>}
      {msg && <div className="alert alert-success py-2">{msg}</div>}

      <div className="row g-2">
        {data.groups.map((g) => {
          const plan = data.plans?.[g.id] ?? []
          const editing = editingGroup === g.id
          const planTotal = plan.reduce((sum, i) => sum + i.sessions_per_week, 0)
          const editTotal = editItems.reduce((sum, i) => sum + i.sessions_per_week, 0)
          return (
            <div className="col-md-6" key={g.id}>
              <div className="aust-panel p-3 h-100">
                <div className="d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div>
                    <h2 className="h5 mb-0 aust-h2">شعبة {g.track} — {g.batch?.name}</h2>
                    <div className="small text-muted">{g.student_count} طالبًا</div>
                  </div>
                  {editing
                    ? <span className={`badge ${editTotal > 18 ? 'bg-danger' : 'bg-secondary'}`}>المجموع: {editTotal}/18</span>
                    : <span className={`badge ${planTotal > 18 ? 'bg-danger' : 'bg-secondary'}`}>المجموع: {planTotal}/18</span>}
                </div>
                {!editing ? (
                  <>
                    {plan.length === 0 ? (
                      <div className="small text-muted border rounded p-2 mt-2">لا توجد خطة — اضغط «تعديل» لإضافة المقررات.</div>
                    ) : (
                      <table className="table table-sm mb-2 mt-2">
                        <thead><tr><th>المقرر</th><th>الحصص/الأسبوع</th></tr></thead>
                        <tbody>
                          {plan.map((i) => <tr key={i.course_id}><td>{i.code} — {i.name}</td><td>{i.sessions_per_week}</td></tr>)}
                        </tbody>
                      </table>
                    )}
                    <button className="aust-btn-outline btn-sm mt-2" onClick={() => startEdit(g.id)}>تعديل الخطة</button>
                  </>
                ) : (
                  <>
                    <div className="small fw-bold mt-2 mb-1">اختر المقررات + عدد الحصص:</div>
                    <div className="d-flex flex-wrap gap-2 mb-2" style={{ maxHeight: 220, overflowY: 'auto' }}>
                      {courses.map((c) => {
                        const checked = editItems.some((i) => i.course_id === c.id)
                        return (
                          <label key={c.id} className={`form-check form-check-inline border rounded px-2 py-1 mb-1 ${checked ? 'border-primary bg-light' : ''}`} style={{ cursor: 'pointer' }}>
                            <input className="form-check-input me-1" type="checkbox" checked={checked} onChange={() => toggle(c.id)} />
                            <span className="small">{c.code} — {c.name} <span className="text-muted">({c.credit_hours} س)</span></span>
                          </label>
                        )
                      })}
                    </div>
                    {editItems.length > 0 && (
                      <table className="table table-sm mb-2">
                        <thead><tr><th>المقرر</th><th>الحصص/الأسبوع</th></tr></thead>
                        <tbody>
                          {editItems.map((i) => {
                            const c = courses.find((cc) => cc.id === i.course_id)
                            return (
                              <tr key={i.course_id}>
                                <td className="small">{c?.code} — {c?.name}</td>
                                <td>
                                  <input type="number" className="form-control form-control-sm" style={{ width: 70 }} min={1} max={12} dir="ltr" value={i.sessions_per_week} onChange={(e) => setSessions(i.course_id, Number(e.target.value))} />
                                </td>
                              </tr>
                            )
                          })}
                        </tbody>
                      </table>
                    )}
                    <div className="d-flex gap-2">
                      <button className="aust-btn btn-sm" disabled={saving || editItems.length === 0} onClick={save}>{saving ? 'جارٍ الحفظ…' : 'حفظ الخطة'}</button>
                      <button className="btn btn-outline-secondary btn-sm" onClick={() => setEditingGroup(null)} disabled={saving}>إلغاء</button>
                    </div>
                  </>
                )}
              </div>
            </div>
          )
        })}
      </div>

      {data.groups.length === 0 && <div className="alert alert-secondary">لا توجد شعب بعد — أنشئ الدفعات من «إدارة الدفعات» أولًا.</div>}
    </>
  )
}
