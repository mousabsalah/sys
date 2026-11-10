import type { AuthUser, Role, ViewName } from '../types'

export function Shell({ user, active, onNav, onLogout, children }: {
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
