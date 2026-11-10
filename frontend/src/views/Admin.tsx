import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

type PlanList = {
  groups: { id: number; batch_id: number; track: string; student_count: number; batch: { name: string } }[]
  plans: Record<number, { course_id: number; code: string; name: string; sessions_per_week: number }[]>
}

function PlansEditor({ call, courses }: { call: ReturnType<typeof useAuthedApi>; courses: { id: number; code: string; name: string; credit_hours: number }[] }) {
  const [data, setData] = useState<PlanList | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [editingGroup, setEditingGroup] = useState<number | null>(null)
  const [editItems, setEditItems] = useState<{ course_id: number; sessions_per_week: number }[]>([])
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<string | null>(null)

  async function load() {
    try { setData(await call<PlanList>('/api/admin/plans')) } catch (e) { setErr((e as Error).message) }
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
  if (!data) return <div>جارٍ تحميل الخطط…</div>

  return (
    <section className="aust-panel p-3 mb-3">
      <div className="d-flex justify-content-between align-items-center mb-2">
        <h2 className="h5 mb-0" style={{ color: '#782B19' }}>الخطط الدراسية للشعب (تعبئة الاقتراح التلقائي)</h2>
        {msg && <span className="small text-success fw-bold">{msg}</span>}
      </div>
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
    </section>
  )
}

export default function MasterDataView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  type Master = {
    faculties: {id:number; name:string; code:string; description?:string}[]
    departments: {id:number; faculty_id:number; name:string; code:string}[]
    programs: {id:number; name:string; description?:string}[]
    periods: {id:number; program_id:number; day_of_week:string; start_time:string; end_time:string; label:string; sort_order:number}[]
    batches: {id:number; name:string; student_count:number; department_id:number; program_id:number; department?:{name:string}; program?:{name:string}}[]
    groups: {id:number; batch_id:number; track:string; student_count:number; batch?:{name:string}}[]
    halls: {id:number; name:string; capacity:number; status:string; hall_type:string; equipment?:string}[]
    courses: {id:number; code:string; name:string; credit_hours:number; required_equipment?:string}[]
    faculty_members: {id:number; full_name:string; department_id:number; email:string; phone?:string; department?:{name:string}}[]
    course_assignments: {id:number; course_id:number; faculty_id:number; course?:{code:string; name:string}; faculty?:{full_name:string}}[]
  }
  const [data, setData] = useState<Master | null>(null)
  const [err, setErr] = useState<string | null>(null)
  useEffect(() => { call<Master>('/api/admin/master-data').then(setData).catch((e) => setErr((e as Error).message)) }, [call])
  if (err) return <div className="alert alert-danger">{err}</div>
  if (!data) return <div>جارٍ التحميل…</div>

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>البيانات الرئيسية (Admin)</h1>
      {[
        { title: 'الكليات', rows: data.faculties, render: (f: typeof data.faculties[number]) => `${f.id} · ${f.name} (${f.code ?? '—'})` },
        { title: 'الأقسام', rows: data.departments, render: (d: typeof data.departments[number]) => `${d.id} · ${d.name}` },
        { title: 'البرامج', rows: data.programs, render: (p: typeof data.programs[number]) => `${p.id} · ${p.name}` },
        { title: 'الفترات', rows: data.periods, render: (p: typeof data.periods[number]) => `${p.id} · ${p.label} — ${p.day_of_week} ${p.start_time}–${p.end_time}` },
        { title: 'الدفعات', rows: data.batches, render: (b: typeof data.batches[number]) => `${b.id} · ${b.name} — ${b.student_count} طالب · القسم #${b.department_id}` },
        { title: 'الشعب (دبلوم/بكلاريوس)', rows: data.groups, render: (g: typeof data.groups[number]) => `${g.id} · دفعة #${g.batch_id} · ${g.track} · ${g.student_count} طالب` },
        { title: 'القاعات', rows: data.halls, render: (h: typeof data.halls[number]) => `${h.name} · ${h.capacity} مقعد · ${h.status} · ${h.hall_type}` },
        { title: 'المقررات', rows: data.courses, render: (c: typeof data.courses[number]) => `${c.code} · ${c.name} · ${c.credit_hours} ساعات` },
        { title: 'أعضاء هيئة التدريس', rows: data.faculty_members, render: (f: typeof data.faculty_members[number]) => `${f.full_name} · ${f.email}` },
        { title: 'إسناد المقررات للأساتذة', rows: data.course_assignments, render: (a: typeof data.course_assignments[number]) => `${a.course?.code ?? '#' + a.course_id} ↔ ${a.faculty?.full_name ?? '#' + a.faculty_id}` },
      ].map((section) => (
        <section key={section.title} className="aust-panel p-3 mb-3">
          <h2 className="h5" style={{color: '#782B19'}}>{section.title}</h2>
          <ul className="mb-0 small">
            {section.rows.length === 0 && <li className="text-muted">—</li>}
            {section.rows.map((it, i) => <li key={i}>{section.render(it as never)}</li>)}
          </ul>
        </section>
      ))}
      <PlansEditor call={call} courses={data.courses} />
    </>
  )
}