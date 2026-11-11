import { useState } from 'react'
import { api } from '../api'
import type { AuthUser, LoginResponse } from '../types'

export default function LoginView({ onAuthed }: { onAuthed: (u: AuthUser, token: string) => void }) {
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
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