import { useEffect, useRef, useState } from 'react'
import { useAuthedApi } from '../api'
import { SkeletonRows, ErrorRetry } from '../components/LoadState'

type AdminUser = {
  id: number
  name: string
  username: string
  role: 'admin' | 'council' | 'guard' | 'batch' | 'lecturer'
  faculty_id: number | null
  batch_id: number | null
  is_active: boolean
  deactivated_at: string | null
  last_login_at: string | null
  email: string | null
}

type ListResp = { total: number; users: AdminUser[] }
type MasterLite = {
  faculties: { id: number; name: string }[]
  batches: { id: number; name: string; department_id: number }[]
}

const ROLE_LABEL: Record<AdminUser['role'], string> = {
  admin: 'مدير النظام',
  council: 'مجلس المدرسين',
  guard: 'حرس',
  batch: 'دفعة',
  lecturer: 'مدرس',
}

export default function AdminUsersView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [users, setUsers] = useState<AdminUser[]>([])
  const [totalUsers, setTotalUsers] = useState(0)
  const [batches, setBatches] = useState<MasterLite['batches']>([])
  const [q, setQ] = useState('')
  const [debouncedQ, setDebouncedQ] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'deactivated'>('all')
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({ name: '', username: '', email: '', password: '', role: 'council' as AdminUser['role'], faculty_id: '', batch_id: '' })
  const [tempPassword, setTempPassword] = useState<{ username: string; password: string } | null>(null)

  const seqRef = useRef(0)
  const abortRef = useRef<AbortController | null>(null)

  async function refresh() {
    // إلغاء الطلب الجاري ومنع نتائج قديمة — الطلبات المتداخلة مرتبة بـ seq
    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller
    const seq = ++seqRef.current

    setErr(null); setLoading(true)
    try {
      const qs = new URLSearchParams()
      if (debouncedQ) qs.set('q', debouncedQ)
      if (roleFilter) qs.set('role', roleFilter)
      qs.set('status', statusFilter)
      const data = await call<ListResp>(`/api/admin/users?${qs.toString()}`, 'GET', undefined, { signal: controller.signal })
      if (seq !== seqRef.current) return
      setUsers(data.users ?? [])
      setTotalUsers(data.total ?? data.users?.length ?? 0)
    } catch (e) {
      if ((e as Error).name === 'AbortError') return
      if (seq === seqRef.current) setErr((e as Error).message)
    } finally {
      if (seq === seqRef.current) setLoading(false)
    }
  }

  // debounce البحث — 300ms بدل طلب على كل ضغطة زر
  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q.trim()), 300)
    return () => clearTimeout(t)
  }, [q])

  useEffect(() => { refresh() }, [debouncedQ, roleFilter, statusFilter])

  useEffect(() => {
    call<MasterLite>('/api/admin/master-data')
      .then((d) => { setBatches(d.batches ?? []) })
      .catch(() => { /* تُعرض القوائم فارغة بدل تعطل الصفحة */ })
  }, [call])

  async function create() {
    setErr(null); setMsg(null)
    try {
      const payload: Record<string, unknown> = {
        name: form.name, username: form.username, email: form.email, password: form.password, role: form.role,
        faculty_id: form.faculty_id ? Number(form.faculty_id) : null,
        batch_id: form.batch_id ? Number(form.batch_id) : null,
      }
      const r = await call<{ ok: boolean }>('/api/admin/users', 'POST', payload)
      if (r.ok) {
        setMsg('تم إنشاء الحساب ✓')
        setForm({ name: '', username: '', email: '', password: '', role: form.role, faculty_id: '', batch_id: '' })
        setCreating(false)
        refresh()
      }
    } catch (e) { setErr((e as Error).message) }
  }

  async function toggleActive(u: AdminUser) {
    setErr(null); setMsg(null)
    if (!u.is_active && !window.confirm(`تفعيل حساب «${u.name}»؟ سيعود قادرًا على الدخول فورًا.`)) return
    if (u.is_active && !window.confirm(`إيقاف حساب «${u.name}»؟ سيُقطع كل التوكنات النشطة فورًا.`)) return
    try {
      await call(`/api/admin/users/${u.id}/${u.is_active ? 'deactivate' : 'reactivate'}`, 'POST')
      setMsg(u.is_active ? 'تم إيقاف الحساب' : 'تم تفعيل الحساب')
      refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  async function resetPassword(u: AdminUser) {
    setErr(null)
    if (!window.confirm(`توليد كلمة مرور مؤقتة لـ«${u.name}»؟ سيُبطل كل جلساته الحالية فورًا.`)) return
    try {
      const r = await call<{ ok: boolean; temporary_password: string }>(`/api/admin/users/${u.id}/reset-password`, 'POST')
      setTempPassword({ username: u.username, password: r.temporary_password })
      setMsg('تم توليد كلمة مرور مؤقتة')
      refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-1" style={{ color: '#0A1D3B' }}>إدارة المستخدمين</h1>
      <p className="small text-muted mb-3">إجمالي <strong>{totalUsers}</strong> حساب مسجّل في النظام — التعطيل فقط، بلا حذف.</p>

      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <ErrorRetry message={err} onRetry={refresh} />}

      <div className="aust-panel p-3 mb-3 d-flex flex-wrap gap-2 align-items-center">
        <input placeholder="بحث بالاسم أو اسم الدخول أو البريد…" className="form-control" style={{ maxWidth: 320 }} value={q} onChange={(e) => setQ(e.target.value)} aria-label="بحث" />
        <select className="form-select" style={{ maxWidth: 180 }} value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)} aria-label="الدور">
          <option value="">كل الأدوار</option>
          <option value="admin">مدير النظام</option>
          <option value="council">مجلس المدرسين</option>
          <option value="guard">حرس</option>
          <option value="batch">دفعة</option>
          <option value="lecturer">مدرس</option>
        </select>
        <select className="form-select" style={{ maxWidth: 180 }} value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as typeof statusFilter)} aria-label="الحالة">
          <option value="all">كل الحالات</option>
          <option value="active">مفعل</option>
          <option value="deactivated">موقوف</option>
        </select>
        <button className="aust-btn ms-auto" onClick={() => setCreating(true)}>+ حساب جديد</button>
      </div>

      {creating && (
        <div className="aust-panel p-3 mb-3">
          <h2 className="h5 mb-2" style={{ color: '#782B19' }}>إنشاء حساب جديد</h2>
          <div className="d-grid gap-2" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))' }}>
            <input className="form-control" placeholder="الاسم الكامل" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
            <input className="form-control" placeholder="اسم الدخول" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} dir="ltr" required />
            <input className="form-control" type="email" placeholder="البريد الإلكتروني" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} dir="ltr" required />
            <input className="form-control" type="password" placeholder="كلمة المرور" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} dir="ltr" required />
            <select className="form-select" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as AdminUser['role'] })}>
              <option value="admin">مدير النظام</option>
              <option value="council">مجلس المدرسين</option>
              <option value="guard">حرس</option>
              <option value="batch">دفعة</option>
            </select>
            {/* دور المجلس يعمل عبر الجامعة كلها — لا قيد كلية (الجامعة = المقررات والكليات كلها) */}
            {form.role === 'batch' && (
              <select className="form-select" value={form.batch_id} onChange={(e) => setForm({ ...form, batch_id: e.target.value })} required>
                <option value="">— اختر الدفعة —</option>
                {batches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
              </select>
            )}
            <button className="aust-btn" onClick={create}>إنشاء الحساب</button>
            <button className="aust-btn-outline" onClick={() => setCreating(false)}>إلغاء</button>
          </div>
        </div>
      )}

      {tempPassword && (
        <div className="aust-panel p-3 mb-3" style={{ borderInlineStart: '4px solid #D4822D' }}>
          <h2 className="h5 mb-2" style={{ color: '#B26225' }}>كلمة المرور المؤقتة ({tempPassword.username})</h2>
          <div className="d-flex align-items-center gap-2">
            <code className="fs-4">{tempPassword.password}</code>
            <button className="aust-btn-outline btn-sm" onClick={() => navigator.clipboard.writeText(tempPassword.password)}>نسخ</button>
            <button className="aust-btn-outline btn-sm" onClick={() => setTempPassword(null)}>إغلاق</button>
          </div>
          <p className="small text-muted mb-0 mt-1">هذه الكلمة تُعرض مرة واحدة فقط — أعطها للمستخدم بطريقة آمنة.</p>
        </div>
      )}

      <div className="table-responsive">
        <table className="table table-sm aust-panel align-middle">
          <thead className="table-light">
            <tr><th>الاسم</th><th>الدخول</th><th>الدور</th><th>الكلية/الدفعة</th><th>آخر دخول</th><th>الحالة</th><th></th></tr>
          </thead>
          <tbody>
            {loading && <tr><td colSpan={7}><SkeletonRows rows={3} height={34} /></td></tr>}
            {users.map((u) => (
              <tr key={u.id} style={!u.is_active ? { opacity: 0.6 } : undefined}>
                <td><strong>{u.name}</strong></td>
                <td dir="ltr">{u.username}</td>
                <td>{ROLE_LABEL[u.role]}</td>
                <td>{u.batch_id
                  ? (batches.find((b) => b.id === u.batch_id)?.name ?? `#${u.batch_id}`)
                  : '—'}
                </td>
                <td>{u.last_login_at ? new Date(u.last_login_at).toLocaleDateString('ar-SD') : '—'}</td>
                <td>
                  {u.is_active
                    ? <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>مفعل</span>
                    : <span className="badge" style={{ background: '#782B19', color: '#fff' }}>موقوف</span>}
                </td>
                <td className="d-flex gap-1">
                  <button className="aust-btn-outline btn-sm" onClick={() => toggleActive(u)}>{u.is_active ? 'إيقاف' : 'تفعيل'}</button>
                  <button className="aust-btn-outline btn-sm" onClick={() => resetPassword(u)}>إعادة كلمة المرور</button>
                </td>
              </tr>
            ))}
            {!loading && users.length === 0 && <tr><td colSpan={7} className="text-muted">لا توجد حسابات مطابقة للبحث</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}
