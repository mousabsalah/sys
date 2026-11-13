import { useEffect, useMemo, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type Degree = 'معيد' | 'محاضر' | 'أستاذ مساعد' | 'أستاذ مشارك' | 'أستاذ'

type Lecturer = {
  id: number
  full_name: string
  degree: Degree | null
  department_id: number | null
  department: string | null
  email: string
  phone: string | null
  username: string | null
  account_active: boolean | null
  faculties: { id: number; name: string; code: string }[]
  courses: { id: number; code: string; name: string }[]
  courses_count: number
}

type Master = {
  degrees: Degree[]
  faculties: { id: number; name: string; code: string }[]
  departments: { id: number; name: string; faculty_id: number }[]
  lecturers: Lecturer[]
  total: number
}

const DEGREES: Degree[] = ['معيد', 'محاضر', 'أستاذ مساعد', 'أستاذ مشارك', 'أستاذ']

const emptyForm = { full_name: '', degree: 'معيد' as Degree, email: '', phone: '', department_id: '', faculty_ids: [] as number[], username: '', password: '' }

export default function CouncilLecturersView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [data, setData] = useState<Master | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState<number | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [saving, setSaving] = useState(false)
  const [tempPassword, setTempPassword] = useState<{ username: string; password: string } | null>(null)

  // لوحة الربط بالمقررات
  const [selected, setSelected] = useState<Lecturer | null>(null)

  async function load() {
    try {
      setData(await call<Master>('/api/council/lecturers'))
      setErr(null)
    } catch (e) { setErr((e as Error).message) }
  }

  useEffect(() => { load() }, [reloadKey, call])

  function startCreate() {
    setForm(emptyForm); setEditing(null); setErr(null); setShowForm(true)
  }

  function startEdit(l: Lecturer) {
    setForm({ full_name: l.full_name, degree: l.degree ?? 'معيد', email: l.email, phone: l.phone ?? '', department_id: l.department_id ? String(l.department_id) : '', faculty_ids: l.faculties.map((f) => f.id), username: l.username ?? '', password: '' })
    setEditing(l.id); setShowForm(true); setErr(null)
  }

  async function save() {
    setSaving(true); setErr(null); setMsg(null)
    try {
      const payload: Record<string, unknown> = {
        full_name: form.full_name,
        degree: form.degree,
        email: form.email,
        phone: form.phone || null,
        department_id: form.department_id ? Number(form.department_id) : null,
        faculty_ids: form.faculty_ids,
      }
      // الحساب يُنشأ عند الإنشاء فقط — لا تعديل عليه من هنا بعد (كلمة المرور عبر زر «كلمة المرور»)
      if (editing === null) {
        payload.username = form.username
        if (form.password) payload.password = form.password
      }
      if (editing !== null) {
        await call(`/api/council/lecturers/${editing}`, 'PUT', payload)
        setMsg('تم تحديث بيانات المدرس ✓')
      } else {
        const created = await call<{ ok: boolean; account?: { username: string; temporary_password?: string } }>('/api/council/lecturers', 'POST', payload)
        setMsg('تمت إضافة المدرس ✓')
        if (created?.account?.temporary_password) {
          setTempPassword({ username: created.account.username, password: created.account.temporary_password })
        }
      }
      setShowForm(false)
      await load()
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function remove(l: Lecturer) {
    if (!window.confirm(`حذف مدرس «${l.full_name}»؟ ربطه بالمحاضرات والمقررات يمنع الحذف.`)) return
    try {
      await call(`/api/council/lecturers/${l.id}`, 'DELETE')
      setMsg('تم حذف المدرس')
      if (selected?.id === l.id) setSelected(null)
      await load()
    } catch (e) { setErr((e as Error).message) }
  }

  async function toggleAccount(l: Lecturer) {
    if (!l.username) { setErr('هذا المدرس لا يملك حسابًا — أعد إنشاؤه من هذه الشاشة.'); return }
    const action = l.account_active ? 'deactivate' : 'reactivate'
    const confirm_ = l.account_active
      ? `إيقاف حساب «${l.username}»؟ لا يدخل المدرس بعدها حتى يعيد المجلس التفعيل.`
      : `تفعيل حساب «${l.username}»؟ سيعود المدرس قادرًا على الدخول فورًا.`
    if (!window.confirm(confirm_)) return
    setErr(null); setMsg(null)
    try {
      await call(`/api/council/lecturers/${l.id}/${action}`, 'POST')
      setMsg(l.account_active ? 'تم إيقاف حساب المدرس' : 'تم تفعيل حساب المدرس')
      await load()
    } catch (e) { setErr((e as Error).message) }
  }

  async function resetAccountPassword(l: Lecturer) {
    if (!l.username) { setErr('هذا المدرس لا يملك حسابًا — أعد إنشاؤه من هذه الشاشة.'); return }
    if (!window.confirm(`توليد كلمة مرور مؤقتة لـ«${l.username}»؟ ستُبطل كل جلساته الحالية فورًا.`)) return
    setErr(null); setMsg(null)
    try {
      const r = await call<{ ok: boolean; username: string; temporary_password: string }>(`/api/council/lecturers/${l.id}/reset-password`, 'POST')
      setTempPassword({ username: r.username, password: r.temporary_password })
      setMsg('تم توليد كلمة مرور مؤقتة')
      await load()
    } catch (e) { setErr((e as Error).message) }
  }

  async function removeCourse(lecturer: Lecturer, courseId: number) {
    if (!window.confirm('فك ربط المقرر بالفعل؟ ممنوع عند وجود محاضرات مسندة.')) return
    try {
      await call(`/api/council/lecturers/${lecturer.id}/courses/${courseId}`, 'DELETE')
      setMsg('تم فك ربط المقرر')
      await load()
    } catch (e) { setErr((e as Error).message) }
  }

  async function addCourse(lecturer: Lecturer, courseId: number) {
    try {
      await call(`/api/council/lecturers/${lecturer.id}/courses/${courseId}`, 'POST')
      setMsg('تم ربط المقرر')
      await load()
    } catch (e) { setErr((e as Error).message) }
  }

  // بعد كل load — أعد بناء selected من data الحديثة
  const selectedFresh = useMemo(
    () => (selected ? data?.lecturers.find((l) => l.id === selected.id) ?? null : null),
    [data, selected],
  )

  if (err && !data) return <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />
  if (!data) return <SkeletonRows rows={6} height={64} />

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>إدارة المدرسين</h1>
      <p className="small text-muted mb-3">المجلس يضيف مدرسين بدرجاتهم العلمية، يربطهم بالكليات (أكثر من كلية)، ويربط كل مدرس بالمواد التي يدرسها (يمكن ربط مدرس بمادتين أو أكثر).</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={() => setReloadKey((k) => k + 1)} />}
      {tempPassword && (
        <div className="alert alert-warning py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap" role="alert">
          <span>
            كلمة المرور المؤقتة للحساب <strong dir="ltr">{tempPassword.username}</strong>:&nbsp;
            <strong dir="ltr">{tempPassword.password}</strong>
            {' '}— سُجّلت على هذه الشاشة مرة واحدة فقط، انسخها الآن.
          </span>
          <button className="aust-btn-outline btn-sm" onClick={() => setTempPassword(null)}>فهمت</button>
        </div>
      )}

      <div className="mb-3 d-flex align-items-center gap-2">
        <button className="aust-btn" onClick={startCreate}>+ مدرس جديد</button>
        <span className="small text-muted">{data.total} مدرسًا مسجلًا</span>
        <button className="aust-btn-outline ms-auto" onClick={() => setReloadKey((k) => k + 1)}>تحديث</button>
      </div>

      {showForm && (
        <div className="aust-panel p-3 mb-3">
          <h2 className="h5 mb-2" style={{ color: '#782B19' }}>{editing !== null ? 'تعديل بيانات المدرس' : 'إضافة مدرس'}</h2>
          <div className="row g-2">
            <div className="col-md-6"><input className="form-control" placeholder="الاسم الكامل" value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} required /></div>
            <div className="col-md-3">
              <select className="form-select" value={form.degree} onChange={(e) => setForm({ ...form, degree: e.target.value as Degree })} aria-label="الدرجة العلمية">
                {DEGREES.map((d) => <option key={d} value={d}>{d}</option>)}
              </select>
            </div>
            <div className="col-md-3"><input className="form-control" type="email" dir="ltr" placeholder="البريد الإلكتروني" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required /></div>
            <div className="col-md-3"><input className="form-control" dir="ltr" placeholder="الهاتف (اختياري)" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></div>
            <div className="col-md-3">
              <input className="form-control" dir="ltr" placeholder="اسم الدخول (للمدرس)" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} disabled={editing !== null} required={editing === null} minLength={3} />
              {editing === null && <div className="form-text">إجباري — المدرس يدخل به للنظام</div>}
            </div>
            <div className="col-md-3">
              <input className="form-control" dir="ltr" type="password" placeholder="كلمة المرور (اختياري)" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} disabled={editing !== null} minLength={6} />
              {editing === null && <div className="form-text">إن تُركت فارغة تُولَّد تلقائيًا وتُعرض مرة واحدة</div>}
            </div>
            <div className="col-md-3">
              <select className="form-select" value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })} aria-label="القسم (اختياري)">
                <option value="">— القسم (اختياري) —</option>
                {data.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
              </select>
            </div>
            <div className="col-12">
              <label className="form-label mb-1">الكليات المرتبطة بها (اختر واحدًا أو أكثر):</label>
              <div className="d-flex flex-wrap gap-2">
                {data.faculties.map((f) => {
                  const checked = form.faculty_ids.includes(f.id)
                  return (
                    <label key={f.id} className="border rounded px-2 py-1 small" style={{ cursor: 'pointer', background: checked ? '#0A1D3B' : '#fff', color: checked ? '#fff' : '#0A1D3B' }}>
                      <input type="checkbox" className="d-none" checked={checked} onChange={() => setForm({ ...form, faculty_ids: checked ? form.faculty_ids.filter((id) => id !== f.id) : [...form.faculty_ids, f.id] })} />
                      {f.name}
                    </label>
                  )
                })}
              </div>
            </div>
            <div className="col-12 d-flex gap-2">
              <button className="aust-btn" disabled={saving || !form.full_name || !form.email || form.faculty_ids.length === 0 || (editing === null && !form.username)} onClick={save}>{saving ? '…' : editing !== null ? 'حفظ' : 'إضافة'}</button>
              <button className="aust-btn-outline" onClick={() => setShowForm(false)}>إلغاء</button>
            </div>
          </div>
        </div>
      )}

      <div className="row g-3">
        <div className="col-md-7">
          <div className="table-responsive">
            <table className="table table-sm aust-panel align-middle">
              <thead className="table-light"><tr><th>#</th><th>المدرس</th><th>الدرجة</th><th>الكليات</th><th>مقررات</th><th></th></tr></thead>
              <tbody>
                {data.lecturers.map((l) => (
                  <tr key={l.id} className={selectedFresh?.id === l.id ? 'table-active' : undefined}>
                    <td>#{l.id}</td>
                    <td>
                      <strong>{l.full_name}</strong>
                      <div className="small text-muted" dir="ltr">{l.email}</div>
                      <div className="small">
                        {l.username
                          ? <span dir="ltr" className="text-muted">{l.username}</span>
                          : <span className="text-muted">بدون حساب</span>}
                        {' · '}
                        {l.account_active === true && <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>مفعل</span>}
                        {l.account_active === false && <span className="badge" style={{ background: '#782B19', color: '#fff' }}>موقوف</span>}
                      </div>
                    </td>
                    <td>{l.degree ?? '—'}</td>
                    <td className="small">{l.faculties.map((f) => f.name).join('، ') || '—'}</td>
                    <td>{l.courses_count}</td>
                    <td className="d-flex gap-1 flex-wrap">
                      <button className="aust-btn-outline btn-sm" onClick={() => setSelected(l)}>مقرراته</button>
                      <button className="aust-btn-outline btn-sm" onClick={() => startEdit(l)}>تعديل</button>
                      {l.username && <button className="aust-btn-outline btn-sm" onClick={() => toggleAccount(l)}>{l.account_active ? 'إيقاف الحساب' : 'تفعيل الحساب'}</button>}
                      {l.username && <button className="aust-btn-outline btn-sm" onClick={() => resetAccountPassword(l)}>كلمة المرور</button>}
                      <button className="aust-btn-outline btn-sm" style={{ borderColor: '#782B19', color: '#782B19' }} onClick={() => remove(l)}>حذف</button>
                    </td>
                  </tr>
                ))}
                {data.lecturers.length === 0 && <tr><td colSpan={6} className="text-muted">لا يوجد مدرسون — أضف أول مدرس من الزر أعلاه</td></tr>}
              </tbody>
            </table>
          </div>
        </div>

        <div className="col-md-5">
          <div className="aust-panel p-3">
            <h2 className="h6 fw-bold mb-2" style={{ color: '#782B19' }}>الربط بالمقررات</h2>
            {!selectedFresh && <p className="small text-muted mb-0">اختر مدرسًا من القائمة لعرض مقرراته وإدارة ربطها.</p>}
            {selectedFresh && (
              <LecturerCoursesPanel lecturer={selectedFresh} call={call} onAdd={addCourse} onRemove={removeCourse} />
            )}
          </div>
        </div>
      </div>
    </>
  )
}

function LecturerCoursesPanel({ lecturer, call, onAdd, onRemove }: {
  lecturer: Lecturer
  call: ReturnType<typeof useAuthedApi>
  onAdd: (lecturer: Lecturer, courseId: number) => void
  onRemove: (lecturer: Lecturer, courseId: number) => void
}) {
  const [allCourses, setAllCourses] = useState<{ id: number; code: string; name: string }[]>([])
  const [pick, setPick] = useState('')

  useEffect(() => {
    call<{ assigned: { id: number }[]; courses: { id: number; code: string; name: string }[] }>(`/api/council/lecturers/${lecturer.id}/courses`)
      .then((d) => setAllCourses(d.courses ?? []))
      .catch(() => { /* تُترك القائمة فارغة */ })
  }, [lecturer.id, call])

  const assignedIds = new Set(lecturer.courses.map((c) => c.id))
  const assignable = allCourses.filter((c) => !assignedIds.has(c.id))

  return (
    <>
      <div className="mb-2">
        <strong>{lecturer.full_name}</strong> — مقرراته ({lecturer.courses.length})
      </div>
      <ul className="list-group mb-3">
        {lecturer.courses.map((c) => (
          <li key={c.id} className="list-group-item d-flex align-items-center justify-content-between gap-2">
            <span><span className="badge text-bg-light border me-1" dir="ltr">{c.code}</span>{c.name}</span>
            <button className="btn btn-sm btn-outline-danger" aria-label="فك الربط" onClick={() => onRemove(lecturer, c.id)}>×</button>
          </li>
        ))}
        {lecturer.courses.length === 0 && <li className="list-group-item text-muted small">لا توجد مقررات مسندة بعد</li>}
      </ul>

      <div className="d-flex gap-2">
        <select className="form-select form-select-sm" value={pick} onChange={(e) => setPick(e.target.value)} aria-label="إضافة مقرر">
          <option value="">— اختر مقررًا للإضافة —</option>
          {assignable.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.name}</option>)}
        </select>
        <button className="aust-btn btn-sm" disabled={!pick} onClick={() => { if (pick) { onAdd(lecturer, Number(pick)); setPick('') } }}>إضافة</button>
      </div>
    </>
  )
}
