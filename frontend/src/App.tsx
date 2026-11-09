import { useCallback, useEffect, useState } from 'react'

type Role = 'admin' | 'dept' | 'guard' | 'batch'
type ViewName = 'login' | 'dashboard' | 'timetables' | 'create-timetable' | 'bookings' | 'reports'

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
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setLoading(false)
    }
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
          <p className="small text-muted text-center mb-0">الدور يُحدد من الحساب — مدير النظام / قسم أكاديمي / حرس جامعي / دفعة</p>
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
    { key: 'bookings', label: 'الحجوزات', roles: ['admin', 'dept', 'guard'] },
    { key: 'reports', label: 'التقارير', roles: ['admin'] },
  ]

  const visible = items.filter((it) => it.roles.includes(user.role))

  return (
    <div className="d-flex min-vh-100" style={{background: 'var(--aust-bg)'}}>
      <aside className="app-sidebar d-flex flex-column" style={{width: 240}}>
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
  const [groupId, setGroupId] = useState('1')
  const [rows, setRows] = useState<{id:number; course_id:number; faculty_id:number; group_id:number; hall_id:number; day_of_week:string; start_time:string; end_time:string}[]>([])
  const [err, setErr] = useState<string | null>(null)

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
        <label className="form-label m-0 align-self-center">الدفعة:</label>
        <select id="grp" className="form-select" style={{maxWidth: 200}} value={groupId} onChange={(e) => setGroupId(e.target.value)}>
          <option value="1">الدفعة 1</option>
          <option value="2">الدفعة 2</option>
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

  async function run(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setMsg(null); setVerdict(null)
    try {
      const r = await call<{ok: boolean; timetable?: unknown; verdict?: typeof verdict}>('/api/timetables/check', 'POST', form)
      setVerdict(r.verdict ?? { conflict: !r.ok, violations: [] })
      if (r.verdict?.conflict) setMsg('تعارض — لم يتم الحفظ')
      else setMsg('لا يوجد تعارض ✓ — حفظ الجدول')
    } catch (err) { setMsg((err as Error).message) }
    finally { setBusy(false) }
  }

  async function save() {
    setBusy(true); setMsg(null)
    try {
      const r = await call<{ok: boolean; message_ar?: string}>('/api/timetables', 'POST', form)
      setMsg(r.ok ? 'تم الحفظ ✓' : (r.message_ar ?? 'تعارض'))
      setTimeout(onDone, 800)
    } catch (err) { setMsg((err as Error).message) }
    finally { setBusy(false) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{color: '#0A1D3B'}}>إعداد الجدول</h1>
      <form className="aust-panel p-3 d-grid gap-2" style={{gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))'}} onSubmit={run}>
        <div><label className="form-label" htmlFor="c">المقرر</label><input id="c" type="number" className="form-control" value={form.course_id} onChange={(e) => setForm({ ...form, course_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="f">عضو هيئة التدريس</label><input id="f" type="number" className="form-control" value={form.faculty_id} onChange={(e) => setForm({ ...form, faculty_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="g">الدفعة/الشعبة</label><input id="g" type="number" className="form-control" value={form.group_id} onChange={(e) => setForm({ ...form, group_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="h">القاعة</label><input id="h" type="number" className="form-control" value={form.hall_id} onChange={(e) => setForm({ ...form, hall_id: Number(e.target.value) })} /></div>
        <div><label className="form-label" htmlFor="d">اليوم</label><select id="d" className="form-select" value={form.day_of_week} onChange={(e) => setForm({ ...form, day_of_week: e.target.value })}><option>السبت</option><option>الأحد</option><option>الاثنين</option><option>الثلاثاء</option><option>الأربعاء</option><option>الخميس</option></select></div>
        <div><label className="form-label" htmlFor="s">من</label><input id="s" type="time" step="1" className="form-control" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value + ':00' })} /></div>
        <div><label className="form-label" htmlFor="e2">إلى</label><input id="e2" type="time" step="1" className="form-control" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value + ':00' })} /></div>
        <div className="d-flex align-items-end gap-2"><button type="submit" className="aust-btn-outline" disabled={busy}>تحقق من التعارض</button>
          <button type="button" className="aust-btn" onClick={save} disabled={busy}>حفظ في الجدول</button></div>
      </form>
      {verdict && (
        <div className="aust-panel p-3 mt-3">
          <h2 className="h5" style={{color: '#782B19'}}>نتيجة التحقق</h2>
          {verdict.violations.length === 0 && <div className="text-success fw-bold">لا تعارض ✓</div>}
          <ul className="mb-0">
            {verdict.violations.map((v, i) => (
              <li key={i} className={v.severity === 'hard' ? 'text-danger' : 'text-warning'}><strong>{v.message_ar}</strong> ({v.code})</li>
            ))}
          </ul>
        </div>
      )}
      {msg && <div className="alert alert-info mt-3">{msg}</div>}
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

export default function App() {
  const [view, setView] = useState<ViewName>('login')
  const [auth, setAuth] = useState<{ user: AuthUser; token: string } | null>(null)
  const call = useAuthedApi(auth?.token ?? null)

  if (!auth) {
    return <LoginView onAuthed={(user, token) => { setAuth({ user, token }); setView('dashboard') }} />
  }

  return (
    <Shell user={auth.user} active={view} onNav={setView} onLogout={() => setAuth(null)}>
      {view === 'dashboard' && <DashboardView call={call} />}
      {view === 'timetables' && <TimetablesView call={call} onNew={() => setView('create-timetable')} />}
      {view === 'create-timetable' && <CreateTimetableView call={call} onDone={() => setView('timetables')} />}
      {view === 'bookings' && <BookingsView call={call} />}
      {view === 'reports' && <ReportsView call={call} />}
    </Shell>
  )
}
