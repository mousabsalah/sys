export default function App() {
  return (
    <main className="min-vh-100 d-flex align-items-center justify-content-center" style={{background: 'linear-gradient(135deg,#0A1D3B 0%, #782B19 140%)'}}>
      <section className="aust-panel p-5" style={{minWidth: 380, maxWidth: 440}}>
        <div className="text-center mb-4">
          <img src="/logo-aust.jpg" alt="AUST" width="96" height="96" className="rounded mb-3" />
          <h1 className="h4 fw-bold mb-0" style={{color:'#0A1D3B'}}>نظام إدارة القاعات والجداول الأكاديمية</h1>
          <p className="small text-muted mt-1 mb-0">جامعة الشرق الأهلية للعلوم والتكنولوجيا</p>
        </div>
        <form className="d-flex flex-column gap-3" onSubmit={(e) => e.preventDefault()}>
          <div>
            <label className="form-label" htmlFor="u">اسم المستخدم</label>
            <input id="u" name="username" className="form-control" autoFocus autoComplete="username" placeholder="admin" />
          </div>
          <div>
            <label className="form-label" htmlFor="p">كلمة المرور</label>
            <input id="p" name="password" type="password" className="form-control" autoComplete="current-password" placeholder="••••••••" />
          </div>
          <button type="submit" className="aust-btn w-100">دخول</button>
          <p className="small text-muted text-center mb-0">الدور يتحدد من الحساب — مدير النظام / قسم أكاديمي / حرس جامعي / دفعة</p>
        </form>
      </section>
    </main>
  )
}
