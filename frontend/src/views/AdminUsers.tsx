import { useEffect, useState } from 'react'
import { useAuthedApi } from '../api'

type AdminUser = {
  id: number
  name: string
  username: string
  role: 'admin' | 'dept' | 'guard' | 'batch'
  faculty_id: number | null
  batch_id: number | null
  is_active: boolean
  deactivated_at: string | null
  last_login_at: string | null
  email: string | null
}

type ListResp = { total: number; users: AdminUser[] }

const ROLE_LABEL: Record<AdminUser['role'], string> = {
  admin: 'مدير النظام',
  dept: 'قسم أكاديمي',
  guard: 'حرس',
  batch: 'دفعة',
}

const FACULTY_LABEL: Record<number, string> = { 1: 'كلية العلوم', 2: 'كلية الهندسة', 3: 'كلية إدارة الأعمال' }

export default function AdminUsersView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [users, setUsers] = useState<AdminUser[]>([])
  const [q, setQ] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'deactivated'>('all')
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({ name: '', username: '', email: '', password: '', role: 'dept' as AdminUser['role'], faculty_id: '', batch_id: '' })
  const [tempPassword, setTempPassword] = useState<{ username: string; password: string } | null>(null)
  const [resetting, setResetting] = useState<number | null>(null)

  async function refresh() {
    setErr(null); setLoading(true)
    try {
      const qs = new URLSearchParams()
      if (q) qs.set('q', q)
      if (roleFilter) qs.set('role', roleFilter)
      qs.set('status', statusFilter)
      const data = await call<ListResp>(`/api/admin/users?${qs.toString()}`)
      setUsers(data.users ?? [])
    } catch (e) { setErr((e as Error).message) }
    finally { setLoading(false) }
  }

  useEffect(() => { refresh() }, [q, roleFilter, statusFilter])

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
        setForm({ name: '', username: '', email: '', password: '', role: 'dept', faculty_id: '', batch_id: '' })
        setCreating(false)
        refresh()
      }
    } catch (e) { setErr((e as Error).message) }
  }

  async function toggleActive(u: AdminUser) {
    setErr(null); setMsg(null)
    try {
      await call(`/api/admin/users/${u.id}/${u.is_active ? 'deactivate' : 'reactivate'}`, 'POST')
      setMsg(u.is_active ? 'تم إيقاف الحساب' : 'تم تفعيل الحساب')
      refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  async function resetPassword(u: AdminUser) {
    setErr(null)
    try {
      const r = await call<{ ok: boolean; temporary_password: string }>(`/api/admin/users/${u.id}/reset-password`, 'POST')
      setTempPassword({ username: u.username, password: r.temporary_password })
      setResetting(null)
      setMsg('تم توليد كلمة مرور مؤقتة')
      refresh()
    } catch (e) { setErr((e as Error).message) }
  }

  return (
    <>
      <h1 className="h3 fw-bold mb-3" style={{ color: '#0A1D3B' }}>إدارة المستخدمين</h1>

      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <div className="alert alert-danger py-2">{err}</div>}

      <div className="aust-panel p-3 mb-3 d-flex flex-wrap gap-2 align-items-center">
        <input placeholder="بحث بالاسم أو اسم الدخول أو البريد…" className="form-control" style={{ maxWidth: 320 }} value={q} onChange={(e) => setQ(e.target.value)} aria-label="بحث" />
        <select className="form-select" style={{ maxWidth: 180 }} value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)} aria-label="الدور">
          <option value="">كل الأدوار</option>
          <option value="admin">مدير النظام</option>
          <option value="dept">قسم أكاديمي</option>
          <option value="guard">حرس</option>
          <option value="batch">دفعة</option>
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
              <option value="dept">قسم أكاديمي</option>
              <option value="guard">حرس</option>
              <option value="batch">دفعة</option>
            </select>
            {form.role === 'dept' && (
              <select className="form-select" value={form.faculty_id} onChange={(e) => setForm({ ...form, faculty_id: e.target.value })}>
                <option value="">— اختر الكلية —</option>
                <option value="1">كلية العلوم</option>
                <option value="2">كلية الهندسة</option>
                <option value="3">كلية إدارة الأعمال</option>
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
            {loading && <tr><td colSpan={7} className="text-muted">جارٍ التحميل…</td></tr>}
            {users.map((u) => (
              <tr key={u.id} style={!u.is_active ? { opacity: 0.6 } : undefined}>
                <td><strong>{u.name}</strong></td>
                <td dir="ltr">{u.username}</td>
                <td>{ROLE_LABEL[u.role]}</td>
                <td>{u.role === 'dept' ? (FACULTY_LABEL[u.faculty_id ?? 0] ?? '—') : u.batch_id ? `#${u.batch_id}` : '—'}</td>
                <td>{u.last_login_at ? new Date(u.last_login_at).toLocaleDateString('ar-SD') : '—'}</td>
                <td>
                  {u.is_active
                    ? <span className="badge" style={{ background: '#1f7a3d', color: '#fff' }}>مفعل</span>
                    : <span className="badge" style={{ background: '#782B19', color: '#fff' }}>موقوف</span>}
                </td>
                <td className="d-flex gap-1">
                  <button className="aust-btn-outline btn-sm" onClick={() => toggleActive(u)}>{u.is_active ? 'إيقاف' : 'تفعيل'}</button>
                  <button className="aust-btn-outline btn-sm" onClick={() => resetting === u.id ? setResetting(null) : resetPassword(u)}>إعادة كلمة المرور</button>
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
