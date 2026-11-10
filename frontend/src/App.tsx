import { useCallback, useEffect, useState } from 'react'

type Role = 'admin' | 'dept' | 'guard' | 'batch'
type ViewName =
  | 'login'
  | 'dashboard'
  | 'timetables'
  | 'create-timetable'
  | 'propose'
  | 'exams'
  | 'create-exam'
  | 'bookings'
  | 'create-booking'
  | 'halls-available'
  | 'reports'
  | 'admin'

interface AuthUser {
  id: number
  name: string
  username: string
  role: Role
  faculty_id: number | null
  batch_id: number | null
}

interface LoginResponse {
  token: string
  user: AuthUser
}

const BASE = import.meta.env.VITE_API_BASE ?? 'http://127.0.0.1:8765'

async function api<T>(path: string, method: string = 'GET', body?: unknown, token?: string | null): Promise<T> {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  })
  if (!res.ok) {
    let detail = res.statusText
    try {
      const j = await res.json()
      detail = j.message_ar ?? j.message ?? JSON.stringify(j)
    } catch { /* ignore */ }
    throw new Error(`${res.status} ${detail}`)
  }
  return res.json() as Promise<T>
}

function LoginView({ onAuthed }: { onAuthed: (u: AuthUser, token: string) => void }) {
  const [username, setUsername] = useState('مدير_النظام')
  const [password, setPassword] = useState('Admin@123456')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError(null); setLoading(true)
    try {
      const r = await api<LoginResponse>('/api/login', 'POST', { username, password })
      onAuthed(r.user, r.token)
    } catch (err) { setError((err as Error).message) }
    finally { setLoading(false) }
  }

  return (
    <main className="min-vh-100 d-flex align-items-center justify-content-center" style={{background: 'linear-gradient(140deg,#0A1D3B 0%, #782B19 100%)'}}>
      <section className="aust-panel p-4 p-md-5" style={{minWidth: 380, maxWidth: 440, width: '100%'}}>
        <div className="text-center mb-4">
          <img src="/logo-aust.jpg" alt="شعار AUST" width="96" height="96" className="rounded mb-3" />
          <h1 className="h4 fw-bold mb-1" style={{color: '#0A1D3B'}}>نظام إدارة القاعات والجداول الأكاديمية</h1>
          <p className="small text-muted mb-0">جامعة الشرق الأهلية للعلوم والتكنولوجيا</p>
        </div>
        <form className="d-flex flex-column gap-3" onSubmit={submit}>
          <div>
            <label className="form-label" htmlFor="u">اسم المستخدم</label>
            <input id="u" className="form-control" value={username} onChange={(e) => setUsername(e.target.value)} autoFocus dir="ltr" autoComplete="username" />
          </div>
          <div>
            <label className="form-label" htmlFor="p">كلمة المرور</label>
            <input id="p" type="password" className="form-control" value={password} onChange={(e) => setPassword(e.target.value)} dir="ltr" autoComplete="current-password" />
          </div>
          {error && <div className="alert alert-danger py-2 mb-0" role="alert">{error}</div>}
          <button className="aust-btn w-100" disabled={loading}>{loading ? 'جارٍ الدخول…' : 'دخول'}</button>
          <p className="small text-muted text-center mb-0">الدور يُحدد من الحساب — مدير / قسم / حرس / دفعة</p>
        </form>
      </section>
    </main>
  )
}

function Shell({ user, active, onNav, onLogout, children }: {
  user: AuthUser
  active: ViewName
  onNav: (v: ViewName) => void
  onLogout: () => void
  children: React.ReactNode
}) {
  const items: { key: ViewName; label: string; roles: Role[] }[] = [
    { key: 'dashboard', label: 'لوحة التحكم', roles: ['admin', 'dept', 'guard', 'batch'] },
    { key: 'timetables', label: 'الجداول', roles: ['admin', 'dept', 'batch'] },
    { key: 'create-timetable', label: 'إعداد الجدول', roles: ['admin', 'dept'] },
    { key: 'propose', label: 'اقتراح تلقائي', roles: ['admin', 'dept'] },
    { key: 'exams', label: 'الامتحانات', roles: ['admin', 'dept', 'batch'] },
    { key: 'create-exam', label: 'إعداد امتحان', roles: ['admin', 'dept'] },
    { key: 'bookings', label: 'الحجوزات', roles: ['admin', 'dept', 'guard'] },
    { key: 'halls-available', label: 'القاعات المتاحة', roles: ['admin', 'dept', 'guard'] },
    { key: 'create-booking', label: 'حجز قاعة', roles: ['admin', 'dept'] },
    { key: 'reports', label: 'التقارير', roles: ['admin'] },
    { key: 'admin', label: 'البيانات الإدارية', roles: ['admin'] },
  ]

  const visible = items.filter((it) => it.roles.includes(user.role))

  return (
    <div className="d-flex min-vh-100" style={{background: 'var(--aust-bg)'}}>
      <aside className="app-sidebar d-flex flex-column" style={{width: 248}}>
        <div className="app-logo d-flex align-items-center gap-2 p-3">
          <img src="/logo-aust.jpg" alt="AUST" width="44" height="44" className="rounded" />
          <div>
            <div className="fw-bold" style={{color: '#fff', fontSize: 14, lineHeight: 1.15}}>جامعة الشرق الأهلية للعلوم والتكنولوجيا</div>
            <div className="small" style={{color: '#D4822D'}}>AUST</div>
          </div>
        </div>
        <nav className="p-2">
          {visible.map((it) => (
            <button key={it.key} className={`nav-link w-100 text-end border-0 bg-transparent ${active === it.key ? 'active' : ''}`} onClick={() => onNav(it.key)}>
              {it.label}
            </button>
          ))}
        </nav>
        <div className="mt-auto p-3" style={{borderTop: '1px solid rgba(255,255,255,0.08)'}}>
          <div className="small" style={{color: '#b8c7e3'}}>{user.name}</div>
          <div className="small" style={{color: '#D4822D'}}>{user.role === 'admin' ? 'مدير النظام' : user.role === 'dept' ? 'قسم أكاديمي' : user.role === 'guard' ? 'حرس جامعي' : 'دفعة'}</div>
          <button className="btn btn-sm mt-2 w-100" style={{border: '1px solid #D4822D', color: '#D4822D', background: 'transparent'}} onClick={onLogout}>تسجيل الخروج</button>
        </div>
      </aside>
      <main className="flex-grow-1 p-3 p-md-4">{children}</main>
    </div>
  )
}

function useAuthedApi(token: string | null) {
  return useCallback(
    <T,>(path: string, method?: string, body?: unknown) =>
      api<T>(path, method, body, token),
    [token],
  )
}

function DashboardView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
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

function TimetablesView({ call, onNew }: { call: ReturnType<typeof useAuthedApi>, onNew: () => void }) {
  const [groupId, setGroupId] = useState('5')
  const [groups, setGroups] = useState<{ id: number; batch: string; track: string; student_count: number }[]>([])
  const [rows, setRows] = useState<{id:number; course_id:number; faculty_id:number; group_id:number; hall_id:number; day_of_week:string; start_time:string; end_time:string}[]>([])
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => {
    call<{ groups: { id: number; batch: string; track: string; student_count: number }[]; courses: unknown[] }>('/api/admin/propose-timetable/context')
      .then((d) => {
        setGroups(d.groups)
        setGroupId((cur) => (d.groups.some((g) => String(g.id) === cur) ? cur : String(d.groups[0]?.id ?? cur)))
      })
      .catch(() => { /* batch role: القائمة تبقى placeholder */ })
  }, [call])

  useEffect(() => {
    call<{id:number; course_id:number; faculty_id:number; group_id:number; hall_id:number; day_of_week:string; start_time:string; end_time:string}[]>(`/api/timetables?by=group&id=${groupId}`)
      .then(setRows)
      .catch((e) => setErr((e as Error).message))
  }, [groupId, call])

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h1 className="h3 fw-bold mb-0" style={{color: '#0A1D3B'}}>الجداول</h1>
        <button className="aust-btn" onClick={onNew}>+ إعداد الجدول</button>
      </div>
      <div className="aust-panel p-3 mb-3 d-flex gap-2">
        <label className="form-label m-0 align-self-center" htmlFor="grp">الشعبة:</label>
        <select id="grp" className="form-select" style={{maxWidth: 320}} value={groupId} onChange={(e) => setGroupId(e.target.value)}>
          {groups.length > 0
            ? groups.map((g) => <option key={g.id} value={g.id}>{g.batch} — {g.track} ({g.student_count} طالب)</option>)
            : <option value={groupId}>الشعبة #{groupId}</option>}
        </select>
      </div>
      {err && <div className="alert alert-danger">{err}</div>}
      <table className="table table-sm aust-panel">
        <thead className="table-light"><tr><th>الشعبة</th><th>المقرر</th><th>اليوم</th><th>من</th><th>إلى</th><th>القاعة</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6} className="text-muted">لا توجد محاضرات لهذه الشعبة</td></tr>}
          {rows.map((r) => (
            <tr key={r.id}><td>#{r.group_id}</td><td>#{r.course_id}</td><td>{r.day_of_week}</td><td>{r.start_time}</td><td>{r.end_time}</td><td>#{r.hall_id}</td></tr>
          ))}
        </tbody>
      </table>
    </>
  )
}

function CreateTimetableView({ call, onDone }: { call: ReturnType<typeof useAuthedApi>, onDone: () => void }) {
  const [form, setForm] = useState({ course_id: 1, faculty_id: 1, group_id: 1, hall_id: 1, day_of_week: 'السبت', start_time: '11:30:00', end_time: '13:30:00', academic_term: '2026/1' })
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [verdict, setVerdict] = useState<{conflict: boolean; violations: {code: string; message_ar: string; severity: string}[]} | null>(null)

  async function check(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setMsg(null); setVerdict(null)
    try {
      const r = await call<{ok: boolean; verdict?: typeof verdict}>('/api/timetables/check', 'POST', form)
      setVerdict(r.verdict ?? { conflict: !r.ok, violations: [] })
      setMsg(r.verdict?.conflict ? 'تعارض — لم يتم الحفظ' : 'لا يوجد تعارض ✓ — متاح الحفظ')
    } catch (err) { setMsg((err as Error).message) }
    finally { setBusy(false) }
  }

  async function save() {
    setBusy(true); setMsg(null)
    try {
      const r = await call<{ok: boolean; message_ar?: string}>('/api/timetables', 'POST', form)
      if (r.ok) { setMsg('تم الحفظ ✓'); setTimeout(onDone, 800) } else setMsg(r.message_ar ?? 'تعارض')
    } catch (err) { setMsg((err as Error).message) }
    finally { setBusy(false) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>إعداد الجدول</h1>
      <form className="aust-panel p-3 d-grid gap-2" style={{gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))'}} onSubmit={check}>
        <div><label className="form-label" htmlFor="c">المقرر</label><input id="c" type="number" className="form-control" value={form.course_id} onChange={(e) => setForm({ ...form, course_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="f">عضو هيئة التدريس</label><input id="f" type="number" className="form-control" value={form.faculty_id} onChange={(e) => setForm({ ...form, faculty_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="g">الدفعة/الشعبة</label><input id="g" type="number" className="form-control" value={form.group_id} onChange={(e) => setForm({ ...form, group_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="h">القاعة</label><input id="h" type="number" className="form-control" value={form.hall_id} onChange={(e) => setForm({ ...form, hall_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="d">اليوم</label><select id="d" className="form-select" value={form.day_of_week} onChange={(e) => setForm({ ...form, day_of_week: e.target.value })}><option>السبت</option><option>الأحد</option><option>الاثنين</option><option>الثلاثاء</option><option>الأربعاء</option><option>الخميس</option></select></div>
        <div><label className="form-label" htmlFor="s">من</label><input id="s" type="time" step="1" className="form-control" value={form.start_time.slice(0, 5)} onChange={(e) => setForm({ ...form, start_time: e.target.value + ':00' })} /></div>
        <div><label className="form-label" htmlFor="e2">إلى</label><input id="e2" type="time" step="1" className="form-control" value={form.end_time.slice(0, 5)} onChange={(e) => setForm({ ...form, end_time: e.target.value + ':00' })} /></div>
        <div className="d-flex align-items-end gap-2"><button type="submit" className="aust-btn-outline" disabled={busy}>تحقق من التعارض</button>
          <button type="button" className="aust-btn" onClick={save} disabled={busy}>حفظ في الجدول</button></div>
      </form>
      {verdict && (
        <div className="aust-panel p-3 mt-3">
          <h2 className="h5" style={{color: '#782B19'}}>نتيجة التحقق</h2>
          {verdict.violations.length === 0 && <div className="text-success fw-bold">لا تعارض ✓</div>}
          <ul className="mb-0">{verdict.violations.map((v, i) => (<li key={i} className={v.severity === 'hard' ? 'text-danger' : 'text-warning'}><strong>{v.message_ar}</strong> <code>{v.code}</code></li>))}</ul>
        </div>
      )}
      {msg && <div className="alert alert-info mt-3">{msg}</div>}
    </>
  )
}

function ExamsView({ call, onNew }: { call: ReturnType<typeof useAuthedApi>, onNew: () => void }) {
  const [groupId, setGroupId] = useState('1')
  const [rows, setRows] = useState<{id:number; course_id:number; group_id:number; hall_id:number; exam_date:string; start_time:string; end_time:string; exam_type:string}[]>([])
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => {
    call<{id:number; course_id:number; group_id:number; hall_id:number; exam_date:string; start_time:string; end_time:string; exam_type:string}[]>(`/api/exams?by=group&id=${groupId}`)
      .then(setRows)
      .catch((e) => setErr((e as Error).message))
  }, [groupId, call])

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h1 className="h3 fw-bold mb-0" style={{color: '#0A1D3B'}}>الامتحانات</h1>
        <button className="aust-btn" onClick={onNew}>+ إعداد امتحان</button>
      </div>
      <div className="aust-panel p-3 mb-3 d-flex gap-2">
        <label className="form-label m-0 align-self-center" htmlFor="ge">الدفعة:</label>
        <select id="ge" className="form-select" style={{maxWidth: 200}} value={groupId} onChange={(e) => setGroupId(e.target.value)}>
          <option value="1">الدفعة 1</option>
          <option value="2">الدفعة 2</option>
          <option value="3">الدفعة 3</option>
          <option value="4">الدفعة 4</option>
        </select>
      </div>
      {err && <div className="alert alert-danger">{err}</div>}
      <table className="table table-sm aust-panel">
        <thead className="table-light"><tr><th>الشعبة</th><th>المقرر</th><th>التاريخ</th><th>من</th><th>إلى</th><th>القاعة</th><th>النوع</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={7} className="text-muted">لا توجد امتحانات لهذه الشعبة</td></tr>}
          {rows.map((r) => (<tr key={r.id}><td>#{r.group_id}</td><td>#{r.course_id}</td><td>{r.exam_date}</td><td>{r.start_time}</td><td>{r.end_time}</td><td>#{r.hall_id}</td><td>{r.exam_type}</td></tr>))}
        </tbody>
      </table>
    </>
  )
}

function CreateExamView({ call, onDone }: { call: ReturnType<typeof useAuthedApi>, onDone: () => void }) {
  const [form, setForm] = useState({ course_id: 1, group_id: 1, hall_id: 1, exam_date: '2026-11-25', start_time: '09:00:00', end_time: '11:00:00', exam_type: 'فاينل', academic_term: '2026/1' })
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [verdict, setVerdict] = useState<{conflict: boolean; violations: {code: string; message_ar: string; severity: string}[]} | null>(null)

  async function check(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setMsg(null); setVerdict(null)
    try {
      const r = await call<{ok: boolean; verdict?: typeof verdict}>('/api/exams/check', 'POST', form)
      setVerdict(r.verdict ?? { conflict: !r.ok, violations: [] })
      setMsg(r.verdict?.conflict ? 'تعارض — لم يتم الحفظ' : 'لا يوجد تعارض ✓ — متاح الحفظ')
    } catch (err) { setMsg((err as Error).message) }
    finally { setBusy(false) }
  }

  async function save() {
    setBusy(true); setMsg(null)
    try {
      const r = await call<{ok: boolean; message_ar?: string}>('/api/exams', 'POST', form)
      if (r.ok) { setMsg('تم الحفظ ✓'); setTimeout(onDone, 800) } else setMsg(r.message_ar ?? 'تعارض')
    } catch (err) { setMsg((err as Error).message) }
    finally { setBusy(false) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>إعداد الامتحان</h1>
      <form className="aust-panel p-3 d-grid gap-2" style={{gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))'}} onSubmit={check}>
        <div><label className="form-label" htmlFor="ce">المقرر</label><input id="ce" type="number" className="form-control" value={form.course_id} onChange={(e) => setForm({ ...form, course_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="ge2">الدفعة/الشعبة</label><input id="ge2" type="number" className="form-control" value={form.group_id} onChange={(e) => setForm({ ...form, group_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="he">القاعة</label><input id="he" type="number" className="form-control" value={form.hall_id} onChange={(e) => setForm({ ...form, hall_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="de">التاريخ</label><input id="de" type="date" className="form-control" value={form.exam_date} onChange={(e) => setForm({ ...form, exam_date: e.target.value })} /></div>
        <div><label className="form-label" htmlFor="se">من</label><input id="se" type="time" step="1" className="form-control" value={form.start_time.slice(0, 5)} onChange={(e) => setForm({ ...form, start_time: e.target.value + ':00' })} /></div>
        <div><label className="form-label" htmlFor="ee">إلى</label><input id="ee" type="time" step="1" className="form-control" value={form.end_time.slice(0, 5)} onChange={(e) => setForm({ ...form, end_time: e.target.value + ':00' })} /></div>
        <div><label className="form-label" htmlFor="te">نوع الامتحان</label><select id="te" className="form-select" value={form.exam_type} onChange={(e) => setForm({ ...form, exam_type: e.target.value })}><option>اختبار قصير</option><option>ميدترم</option><option>فاينل</option></select></div>
        <div className="d-flex align-items-end gap-2"><button type="submit" className="aust-btn-outline" disabled={busy}>تحقق</button><button type="button" className="aust-btn" onClick={save} disabled={busy}>حفظ</button></div>
      </form>
      {verdict && (<div className="aust-panel p-3 mt-3"><h2 className="h5" style={{color: '#782B19'}}>نتيجة التحقق</h2>{verdict.violations.length === 0 && <div className="text-success fw-bold">لا تعارض ✓</div>}<ul className="mb-0">{verdict.violations.map((v, i) => (<li key={i} className={v.severity === 'hard' ? 'text-danger' : 'text-warning'}><strong>{v.message_ar}</strong> <code>{v.code}</code></li>))}</ul></div>)}
      {msg && <div className="alert alert-info mt-3">{msg}</div>}
    </>
  )
}

type ProposalAssignment = {
  course_id: number; code: string; name: string
  period_id: number; day: string; start_time: string; end_time: string; label: string
  hall_id: number; hall_name: string; faculty_id: number; faculty_name: string
  academic_term: string; group_id: number
}
type ProposeUnplaced = { course_id: number; code: string; name: string; sessions: number; reason: string; group_id?: number }
type ProposeGroupSummary = { group_id: number; ok: boolean; status: string; placed: number; batch: string; track: string }
type ProposeResult = {
  ok: boolean; message_ar?: string
  status?: string; placed?: number; multi?: boolean
  groups?: ProposeGroupSummary[]
  assignments?: ProposalAssignment[]; unplaced?: ProposeUnplaced[]
}
type PlanItem = { course_id: number; code: string; name: string; sessions_per_week: number }
type ProposeContext = {
  groups: { id: number; batch: string; track: string; student_count: number; program_id: number }[]
  courses: { id: number; code: string; name: string; credit_hours: number }[]
  plans: Record<number, PlanItem[]>
}

function ProposeView({ call, onAdopted }: { call: ReturnType<typeof useAuthedApi>; onAdopted: () => void }) {
  const [ctx, setCtx] = useState<ProposeContext | null>(null)
  const [ctxErr, setCtxErr] = useState<string | null>(null)
  const [mode, setMode] = useState<'single' | 'all'>('single')
  const [groupId, setGroupId] = useState<number | null>(null)
  const [courseIds, setCourseIds] = useState<number[]>([])
  const [term, setTerm] = useState('2026/1')
  const [result, setResult] = useState<ProposeResult | null>(null)
  const [busy, setBusy] = useState(false)
  const [adopting, setAdopting] = useState(false)
  const [adoptState, setAdoptState] = useState<'idle' | 'ok' | 'conflict' | 'fail'>('idle')
  const [adoptMsg, setAdoptMsg] = useState<string | null>(null)

  useEffect(() => {
    call<ProposeContext>('/api/admin/propose-timetable/context')
      .then((d) => { setCtx(d); setGroupId(d.groups[0]?.id ?? null) })
      .catch((e) => setCtxErr((e as Error).message))
  }, [call])

  // عند تغيير الشعبة (أو اكتمال التحميل) ← حمولة الخطة الافتراضية لهذه الشعبة
  useEffect(() => {
    if (!ctx || groupId === null) return
    const plan = ctx.plans?.[groupId] ?? []
    setCourseIds(plan.map((p) => p.course_id))
  }, [ctx, groupId])

  function sessionsFor(courseId: number): number {
    const plan = groupId !== null ? ctx?.plans?.[groupId] : undefined
    const planned = plan?.find((p) => p.course_id === courseId)?.sessions_per_week
    if (planned) return Math.max(1, Math.min(6, planned))
    const credit = ctx?.courses.find((c) => c.id === courseId)?.credit_hours ?? 3
    return Math.max(1, Math.min(6, credit))
  }

  function toggleCourse(id: number) {
    setCourseIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  async function propose(e: React.FormEvent) {
    e.preventDefault()
    if (mode === 'single' && (!groupId || courseIds.length === 0)) return
    setBusy(true); setResult(null); setAdoptState('idle'); setAdoptMsg(null)
    try {
      const body = mode === 'single'
        ? { group_id: groupId, course_ids: courseIds, academic_term: term }
        : { group_ids: ctx?.groups.map((g) => g.id), academic_term: term }
      const r = await call<ProposeResult>('/api/admin/propose-timetable', 'POST', body)
      setResult(r)
    } catch (er) { setResult({ ok: false, message_ar: (er as Error).message }) }
    finally { setBusy(false) }
  }

  async function adoptAll() {
    if (!result?.assignments?.length) return
    setAdopting(true); setAdoptState('idle'); setAdoptMsg(null)
    try {
      const r = await call<{ ok: boolean; created?: number; message_ar?: string; conflicts?: { index: number; course_code?: string; day: string; start_time: string; violations?: { message_ar: string }[] }[] }>(
        '/api/timetables/bulk', 'POST', {
          assignments: result.assignments.map((a) => ({
            course_id: a.course_id, course_code: a.code, faculty_id: a.faculty_id, group_id: a.group_id,
            hall_id: a.hall_id, day_of_week: a.day, start_time: a.start_time, end_time: a.end_time,
            academic_term: a.academic_term,
          })),
        })
      if (r.ok) { setAdoptState('ok'); setAdoptMsg(`اعتُمدت المسودة بالكامل — ${r.created ?? result.assignments.length} محاضرة حُفظت بمعاملة واحدة.`) }
      else { setAdoptState('conflict'); setAdoptMsg((r.conflicts ?? []).map((c) => `تعارض في ${c.course_code ?? ''} — ${c.day} ${c.start_time?.slice(0,5)}: ${c.violations?.[0]?.message_ar ?? c.day}`).join(' · ') || (r.message_ar ?? 'تعارض — لم يُحفظ شيء')) }
    } catch (er) {
      setAdoptState('fail')
      setAdoptMsg((er as Error).message)
    } finally { setAdopting(false) }
  }

  if (ctxErr) return <div className="alert alert-danger">{ctxErr}</div>
  if (!ctx) return <div>جارٍ التحميل…</div>

  const totalSessions = mode === 'single'
    ? courseIds.reduce((sum, id) => sum + sessionsFor(id), 0)
    : ctx.groups.reduce((sum, g) => sum + (ctx.plans?.[g.id] ?? []).reduce((s, p) => s + p.sessions_per_week, 0), 0)

  const groupById = new Map(ctx.groups.map((g) => [g.id, g]))

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>اقتراح الجدول التلقائي (CP-SAT)</h1>
      <p className="small text-muted mb-3">مقترح للمراجعة فقط — لا يُحفظ شيء تلقائيًا؛ الاعتماد يمر بمعاملة واحدة عبر مسار check→save المعتاد (الكل أو لا شيء).</p>

      <form className="aust-panel p-3 mb-3" onSubmit={propose}>
        <div className="d-flex flex-wrap gap-3 align-items-center mb-3">
          <div className="d-flex gap-3">
            <label className="d-flex align-items-center gap-1 small fw-bold"><input type="radio" name="pmode" checked={mode === 'single'} onChange={() => setMode('single')} /> شعبة واحدة</label>
            <label className="d-flex align-items-center gap-1 small fw-bold"><input type="radio" name="pmode" checked={mode === 'all'} onChange={() => setMode('all')} /> كل الشعب ({ctx.groups.length}) حسب الخطط</label>
          </div>
          <div className="vr align-self-auto" />
          <div><label className="form-label" htmlFor="pt">الفصل</label><input id="pt" className="form-control" style={{ width: 110 }} value={term} onChange={(e) => setTerm(e.target.value)} /></div>
          {mode === 'single' && (
            <div><label className="form-label" htmlFor="pg">الشعبة</label>
              <select id="pg" className="form-select" value={groupId ?? ''} onChange={(e) => setGroupId(Number(e.target.value))} required>
                {ctx.groups.map((g) => <option key={g.id} value={g.id}>{g.batch} — {g.track} ({g.student_count} طالب)</option>)}
              </select>
            </div>
          )}
          <button type="submit" className="aust-btn align-self-end" disabled={busy || (mode === 'single' && (!groupId || courseIds.length === 0))}>{busy ? 'جارٍ الحساب…' : 'اقترح الجدول'}</button>
        </div>

        {mode === 'single' ? (
          <div className="mt-2">
            <div className="d-flex justify-content-between align-items-center mb-1">
              <span className="small fw-bold">المقررات المشمولة ({courseIds.length}) — {totalSessions} حصة/أسبوع (الخطة الدراسية)</span>
              <span className="d-flex gap-2">
                <button type="button" className="aust-btn-outline btn-sm" onClick={() => { const plan = groupId !== null ? (ctx.plans?.[groupId] ?? []) : []; setCourseIds(plan.length ? plan.map((p) => p.course_id) : ctx.courses.map((c) => c.id)) }}>طبق الخطة</button>
                <button type="button" className="aust-btn-outline btn-sm" onClick={() => setCourseIds([])}>إلغاء الكل</button>
              </span>
            </div>
            <div className="d-flex flex-wrap gap-2" style={{ maxHeight: 220, overflowY: 'auto' }}>
              {ctx.courses.map((c) => (
                <label key={c.id} className="d-flex align-items-center gap-1 small" style={{ border: '1px solid #e5ded2', borderRadius: 8, padding: '2px 8px', cursor: 'pointer', background: courseIds.includes(c.id) ? '#fdf6ee' : '#fff' }}>
                  <input type="checkbox" checked={courseIds.includes(c.id)} onChange={() => toggleCourse(c.id)} />
                  <span>{c.code} — {c.name} <span className="text-muted">({sessionsFor(c.id)} حصص)</span></span>
                </label>
              ))}
            </div>
          </div>
        ) : (
          <div className="alert alert-light small mb-0">ستُحل كل شعبة ({ctx.groups.length}) بالتسلسل بخطة دراستها — مجموع الحصص المتوقعة: <strong>{totalSessions}</strong>. قد يستغرق حتى دقيقتين.</div>
        )}
      </form>

      {result && !result.ok && <div className="alert alert-danger">{result.message_ar ?? 'تعذر الاقتراح'}</div>}

      {result?.ok && (
        <>
          <div className="aust-panel p-3 mb-3 d-flex flex-wrap gap-3 align-items-center">
            <div><div className="small text-muted">حالة المحرك</div><strong style={{ color: '#782B19' }}>{result.multi ? 'BATCH (multi)' : result.status}</strong></div>
            <div><div className="small text-muted">حصص موزعة</div><strong style={{ color: '#0A1D3B' }}>{result.placed}</strong></div>
            <div><div className="small text-muted">متعذر</div><strong style={{ color: '#B26225' }}>{result.unplaced?.length ?? 0} مقرر</strong></div>
            <div className="d-flex gap-1 flex-wrap">
              {result.groups?.map((g) => (
                <span key={g.group_id} className="badge" title={`${g.batch} — ${g.track}`} style={{ background: g.ok ? '#1f7a3d' : '#B26225', color: '#fff' }}>
                  #{g.group_id}: {g.placed} حصة
                </span>
              ))}
            </div>
            <button type="button" className="aust-btn ms-auto" onClick={adoptAll} disabled={adopting || !result.assignments?.length || adoptState === 'ok'}>{adopting ? 'جارٍ الاعتماد…' : 'اعتماد الكل (معاملة واحدة)'}</button>
            <button type="button" className="aust-btn-outline" onClick={onAdopted}>عرض الجداول</button>
          </div>

          {adoptMsg && <div className={`alert ${adoptState === 'ok' ? 'alert-success' : 'alert-warning'}`}>{adoptMsg}</div>}

          <h2 className="h6 mb-2" style={{ color: '#782B19' }}>المسودة المقترحة ({result.assignments?.length ?? 0})</h2>
          <div className="table-responsive" style={{ maxHeight: 480, overflowY: 'auto' }}>
            <table className="table table-sm aust-panel">
              <thead className="table-light" style={{ position: 'sticky', top: 0 }}><tr><th>الشعبة</th><th>اليوم</th><th>التوقيت</th><th>المقرر</th><th>القاعة</th><th>المدرس</th><th>الحالة</th></tr></thead>
              <tbody>
                {result.assignments?.map((a, i) => (
                  <tr key={i}>
                    <td>#{a.group_id} <span className="text-muted small">{groupById.get(a.group_id)?.track}</span></td>
                    <td>{a.day}</td>
                    <td><span className="text-muted small">{a.label}</span> {a.start_time?.slice(0,5)}–{a.end_time?.slice(0,5)}</td>
                    <td><strong>{a.code}</strong> — {a.name}</td>
                    <td>{a.hall_name}</td>
                    <td>{a.faculty_name}</td>
                    <td>{adoptState === 'ok' ? '✓ اعتُمدت' : 'معلقة'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {!!result.unplaced?.length && (
            <>
              <h2 className="h6 mb-2 mt-3" style={{ color: '#B26225' }}>تعذر توزيعها (راجع السعة/الطاقم/الفترات)</h2>
              <ul className="small">
                {result.unplaced.map((u, i) => <li key={i}><strong>{u.group_id ? `#${u.group_id} · ` : ''}{u.code} — {u.name}</strong>: {u.sessions} حصة — {u.reason}</li>)}
              </ul>
            </>
          )}
        </>
      )}
    </>
  )
}

function BookingsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [rows, setRows] = useState<{id:number; hall_id:number; date:string; start_time:string; end_time:string; activity_name?:string}[]>([])
  const [err, setErr] = useState<string | null>(null)
  useEffect(() => { call<typeof rows>('/api/bookings?by=date&id=2026-11-10').then(setRows).catch((e) => setErr((e as Error).message)) }, [call])
  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>الحجوزات</h1>
      {err && <div className="alert alert-danger">{err}</div>}
      <table className="table table-sm aust-panel">
        <thead className="table-light"><tr><th>القاعة</th><th>التاريخ</th><th>من</th><th>إلى</th><th>النشاط</th></tr></thead>
        <tbody>{rows.length === 0 && <tr><td colSpan={5} className="text-muted">لا توجد حجوزات</td></tr>}{rows.map((b) => (<tr key={b.id}><td>#{b.hall_id}</td><td>{b.date}</td><td>{b.start_time}</td><td>{b.end_time}</td><td>{b.activity_name ?? '—'}</td></tr>))}</tbody>
      </table>
    </>
  )
}

function HallsAvailableView({ call, onBook, canBook = true }: { call: ReturnType<typeof useAuthedApi>, onBook: (hallId: number) => void, canBook?: boolean }) {
  const today = '2026-11-10'
  const [date, setDate] = useState(today)
  const [from, setFrom] = useState('09:00:00')
  const [to, setTo] = useState('11:00:00')
  const [minCapacity, setMinCapacity] = useState('')
  const [halls, setHalls] = useState<{id:number; name:string; capacity:number; hall_type:string; equipment?:string}[]>([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [queried, setQueried] = useState(false)

  async function search(e?: React.FormEvent) {
    e?.preventDefault()
    setBusy(true); setErr(null)
    try {
      const minCap = minCapacity ? String(minCapacity) : ''
      const data = await call<{query: Record<string, unknown>; halls: typeof halls; total: number}>(
        `/api/halls/available?date=${date}&from=${from}&to=${to}${minCap ? `&min_capacity=${minCap}` : ''}`
      )
      setHalls(data.halls || [])
      setQueried(true)
    } catch (er) { setErr((er as Error).message) }
    finally { setBusy(false) }
  }

  useEffect(() => { search() }, [])

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>القاعات المتاحة</h1>
      <form className="aust-panel p-3 d-flex flex-wrap gap-2 align-items-end" onSubmit={search}>
        <div><label className="form-label" htmlFor="d">التاريخ</label><input id="d" type="date" className="form-control" value={date} onChange={(e) => setDate(e.target.value)} required /></div>
        <div><label className="form-label" htmlFor="f">من</label><input id="f" type="time" step="1" className="form-control" value={from.slice(0, 5)} onChange={(e) => setFrom(e.target.value + ':00')} /></div>
        <div><label className="form-label" htmlFor="t">إلى</label><input id="t" type="time" step="1" className="form-control" value={to.slice(0, 5)} onChange={(e) => setTo(e.target.value + ':00')} /></div>
        <div><label className="form-label" htmlFor="mc">أقل سعة</label><input id="mc" type="number" className="form-control" placeholder="الكل" value={minCapacity} onChange={(e) => setMinCapacity(e.target.value)} /></div>
        <button type="submit" className="aust-btn" disabled={busy}>{busy ? 'جارٍ البحث…' : 'بحث'}</button>
      </form>

      {err && <div className="alert alert-danger mt-3">{err}</div>}
      {queried && halls.length === 0 && <div className="alert alert-warning mt-3">لا توجد قاعات متاحة بالمواصفات. جرّب سعة أقل أو فترة زمنية أخرى.</div>}

      <div className="row g-3 mt-2">
        {halls.map((h) => (
          <div className="col-md-4" key={h.id}>
            <div className="aust-panel p-3 h-100">
              <div className="d-flex justify-content-between align-items-start">
                <h2 className="h5 mb-1" style={{color: '#0A1D3B'}}>{h.name}</h2>
                <span className="badge" style={{background: '#D4822D', color: '#fff'}}>{h.hall_type}</span>
              </div>
              <p className="mb-1 small text-muted">سعتها: <strong>{h.capacity}</strong> مقعدًا</p>
              <p className="mb-2 small">{h.equipment ? h.equipment : <span className="text-muted">بلا تجهيزات</span>}</p>
              {canBook
                ? <button className="aust-btn-outline w-100" onClick={() => onBook(h.id)}>احجز</button>
                : <span className="small text-muted d-block text-center py-2">الاستعلام فقط — الحجز من المدير أو القسم</span>}
            </div>
          </div>
        ))}
      </div>
    </>
  )
}

function CreateBookingView({ call, onDone, hallId }: { call: ReturnType<typeof useAuthedApi>, onDone: () => void, hallId: number | null }) {
  const [date, setDate] = useState('2026-11-10')
  const [from, setFrom] = useState('13:00:00')
  const [to, setTo] = useState('15:00:00')
  const [activityName, setActivityName] = useState('')
  const [courseId, setCourseId] = useState('')
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)

  async function save(e: React.FormEvent) {
    e.preventDefault()
    if (!hallId) { setMsg('اختر قاعة من «القاعات المتاحة» أولًا'); return }
    setBusy(true); setMsg(null)
    try {
      const payload: Record<string, unknown> = { hall_id: hallId, date, start_time: from, end_time: to }
      if (activityName.trim()) payload.activity_name = activityName.trim()
      if (courseId.trim()) payload.course_id = Number(courseId)
      const r = await call<{ok: boolean; message_ar?: string}>('/api/bookings', 'POST', payload)
      if (r.ok) { setSaved(true); setMsg('تم الحجز ✓'); setTimeout(onDone, 800) }
      else { setMsg(r.message_ar ?? 'تعارض') }
    } catch (er) { setMsg((er as Error).message) }
    finally { setBusy(false) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>حجز قاعة</h1>
      <div className="aust-panel p-2 p-md-3 d-inline-flex align-items-center gap-2 mb-3">
        <span className="small text-muted">القاعة المختارة:</span>
        <strong>{hallId ? `قاعة #${hallId}` : 'لم تُحدَّد — اذهب إلى «القاعات المتاحة» لاختيارها'}</strong>
      </div>

      <form className="aust-panel p-3 d-grid gap-2" style={{gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))'}} onSubmit={save}>
        <div><label className="form-label" htmlFor="d">التاريخ</label><input id="d" type="date" className="form-control" value={date} onChange={(e) => setDate(e.target.value)} required /></div>
        <div><label className="form-label" htmlFor="f">من</label><input id="f" type="time" step="1" className="form-control" value={from.slice(0, 5)} onChange={(e) => setFrom(e.target.value + ':00')} /></div>
        <div><label className="form-label" htmlFor="t">إلى</label><input id="t" type="time" step="1" className="form-control" value={to.slice(0, 5)} onChange={(e) => setTo(e.target.value + ':00')} /></div>
        <div><label className="form-label" htmlFor="ac">النشاط</label><input id="ac" className="form-control" placeholder="اجتماع، ورشة، يوم مفتوح…" value={activityName} onChange={(e) => setActivityName(e.target.value)} /></div>
        <div><label className="form-label" htmlFor="ci">أو مقرر #</label><input id="ci" type="number" className="form-control" placeholder="اختياري" value={courseId} onChange={(e) => setCourseId(e.target.value)} /></div>
        <div className="d-flex align-items-end"><button type="submit" className="aust-btn w-100" disabled={busy || saved}>{saved ? 'تم الحجز ✓' : busy ? 'جارٍ الحجز…' : 'تأكيد الحجز'}</button></div>
      </form>

      {msg && <div className="alert alert-info mt-3">{msg}</div>}
    </>
  )
}

function ReportsView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [data, setData] = useState<{utilization: {hall_id:number; hall:string; capacity:number; lectures:number; utilization_pct:number}[]} | null>(null)
  const [err, setErr] = useState<string | null>(null)
  useEffect(() => { call<typeof data>('/api/reports/utilization').then(setData).catch((e) => setErr((e as Error).message)) }, [call])
  if (err) return <div className="alert alert-danger">{err}</div>
  if (!data) return <div>جارٍ التحميل…</div>
  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h1 className="h3 fw-bold mb-0" style={{color: '#0A1D3B'}}>التقارير — نسبة استخدام القاعات</h1>
        <a className="aust-btn-outline" href={`${BASE}/api/reports/utilization?format=csv`} target="_blank" rel="noreferrer">تصدير CSV</a>
      </div>
      <table className="table table-sm aust-panel align-middle">
        <thead className="table-light"><tr><th>القاعة</th><th>السعة</th><th>عدد المحاضرات</th><th>نسبة الاستخدام</th></tr></thead>
        <tbody>
          {data.utilization.map((r) => (
            <tr key={r.hall_id}><td>{r.hall}</td><td>{r.capacity}</td><td>{r.lectures}</td><td><div className="progress" style={{height: 18}}><div className="progress-bar" style={{width: r.utilization_pct + '%', background: '#B26225'}}>{r.utilization_pct}%</div></div></td></tr>
          ))}
        </tbody>
      </table>
    </>
  )
}

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

function MasterDataView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
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

export default function App() {
  const [view, setView] = useState<ViewName>('login')
  const [auth, setAuth] = useState<{ user: AuthUser; token: string } | null>(null)
  const [bookingHallId, setBookingHallId] = useState<number | null>(null)
  const call = useAuthedApi(auth?.token ?? null)

  if (!auth) {
    return <LoginView onAuthed={(user, token) => { setAuth({ user, token }); setView('dashboard') }} />
  }

  return (
    <Shell user={auth.user} active={view} onNav={setView} onLogout={() => { setAuth(null); setView('login') }}>
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
    </Shell>
  )
}
