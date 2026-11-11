import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

type PlanList = {
  groups: { id: number; batch_id: number; track: string; student_count: number; batch: { name: string } }[]
  plans: Record<number, { course_id: number; code: string; name: string; sessions_per_week: number }[]>
}

type MasterLite = {
  courses: { id: number; code: string; name: string; credit_hours: number }[]
}

export default function AdminPlansView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [courses, setCourses] = useState<MasterLite['courses']>([])
  const [data, setData] = useState<PlanList | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [editingGroup, setEditingGroup] = useState<number | null>(null)
  const [editItems, setEditItems] = useState<{ course_id: number; sessions_per_week: number }[]>([])
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<string | null>(null)

  async function load() {
    try {
      const [pl, master] = await Promise.all([
        call<PlanList>('/api/admin/plans'),
        call<MasterLite>('/api/admin/master-data'),
      ])
      setData(pl)
      setCourses(master.courses ?? [])
    } catch (e) { setErr((e as Error).message) }
  }
  useEffect(() => { load() }, [])

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
    setSaving(true); setMsg(null)
    try {
      await call(`/api/admin/plans/${editingGroup}`, 'POST', { items: editItems })
      await load(); setEditingGroup(null); setMsg('تم حفظ الخطة ✓')
    } catch (e) { setMsg((e as Error).message) } finally { setSaving(false) }
  }

  if (err) return <div className="alert alert-danger">{err}</div>
  if (!data) return <div>جارٍ التحميل…</div>

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>الخطط الدراسية للشعب</h1>
      <p className="small text-muted mb-3">المقررات والحصص الأسبوعية لكل شعبة — هي المصدر الذي يبني عليه الاقتراح التلقائي (CP-SAT) جدول الفصل.</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}

      <div className="row g-2">
        {data.groups.map((g) => {
          const plan = data.plans?.[g.id] ?? []
          const editing = editingGroup === g.id
          return (
            <div className="col-md-6" key={g.id}>
              <div className="border rounded p-2 h-100" style={{ background: editing ? '#fdf6ee' : '#fff' }}>
                <div className="d-flex justify-content-between align-items-center mb-1">
                  <div className="small fw-bold">#{g.id} · {g.batch?.name} — {g.track} <span className="text-muted">({g.student_count} طالب)</span></div>
                  {!editing
                    ? <button className="aust-btn-outline btn-sm" onClick={() => startEdit(g.id)}>تعديل الخطة</button>
                    : <span className="d-flex gap-1"><button className="aust-btn-outline btn-sm" onClick={() => setEditingGroup(null)}>إلغاء</button><button className="aust-btn btn-sm" disabled={saving} onClick={save}>{saving ? 'جارٍ الحفظ…' : 'حفظ'}</button></span>}
                </div>
                {!editing ? (
                  <div className="d-flex flex-wrap gap-1">
                    {plan.length === 0 && <span className="small text-muted">بلا خطة — اضغط «تعديل الخطة»</span>}
                    {plan.map((p) => <span key={p.course_id} className="badge" style={{ background: '#9C4D20', color: '#fff' }}>{p.code} × {p.sessions_per_week}</span>)}
                  </div>
                ) : (
                  <div style={{ maxHeight: 300, overflowY: 'auto' }}>
                    {courses.map((c) => {
                      const item = editItems.find((i) => i.course_id === c.id)
                      return (
                        <label key={c.id} className="d-flex align-items-center gap-1 small py-1" style={{ borderBottom: '1px solid #f0ece3', cursor: 'pointer' }}>
                          <input type="checkbox" checked={!!item} onChange={() => toggle(c.id)} />
                          <span className="flex-grow-1">{c.code} — {c.name}</span>
                          {item && <input type="number" min={1} max={12} className="form-control form-control-sm" style={{ width: 56 }} value={item.sessions_per_week} onChange={(e) => setSessions(c.id, Number(e.target.value))} aria-label="حصص" />}
                        </label>
                      )
                    })}
                  </div>
                )}
              </div>
            </div>
          )
        })}
      </div>
    </>
  )
}
