import { DEGREES, type Degree, type LecturerFormState, type Master } from '../views/CouncilLecturersTypes'

/**
 * LecturerForm — نموذج إضافة/تعديل بيانات المدرس وربطه بالكليات (ومسجّل الدخول عبر الحساب عند الإنشاء).
 */
export default function LecturerForm({ editing, departments, faculties, form, saving, onChange, onSave, onCancel }: {
  editing: number | null
  departments: Master['departments']
  faculties: Master['faculties']
  form: LecturerFormState
  saving: boolean
  onChange: (next: LecturerFormState) => void
  onSave: () => void
  onCancel: () => void
}) {
  return (
    <div className="aust-panel p-3 mb-3">
      <h2 className="h5 mb-2" style={{ color: '#782B19' }}>{editing !== null ? 'تعديل بيانات المدرس' : 'إضافة مدرس'}</h2>
      <div className="row g-2">
        <div className="col-md-6"><input className="form-control" placeholder="الاسم الكامل" value={form.full_name} onChange={(e) => onChange({ ...form, full_name: e.target.value })} required /></div>
        <div className="col-md-3">
          <select className="form-select" value={form.degree} onChange={(e) => onChange({ ...form, degree: e.target.value as Degree })} aria-label="الدرجة العلمية">
            {DEGREES.map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
        </div>
        <div className="col-md-3"><input className="form-control" type="email" dir="ltr" placeholder="البريد الإلكتروني" value={form.email} onChange={(e) => onChange({ ...form, email: e.target.value })} required /></div>
        <div className="col-md-3"><input className="form-control" dir="ltr" placeholder="الهاتف (اختياري)" value={form.phone} onChange={(e) => onChange({ ...form, phone: e.target.value })} /></div>
        <div className="col-md-3">
          <input className="form-control" dir="ltr" placeholder="اسم الدخول (للمدرس)" value={form.username} onChange={(e) => onChange({ ...form, username: e.target.value })} disabled={editing !== null} required={editing === null} minLength={3} />
          {editing === null && <div className="form-text">إجباري — المدرس يدخل به للنظام</div>}
        </div>
        <div className="col-md-3">
          <input className="form-control" dir="ltr" type="password" placeholder="كلمة المرور (اختياري)" value={form.password} onChange={(e) => onChange({ ...form, password: e.target.value })} disabled={editing !== null} minLength={6} />
          {editing === null && <div className="form-text">إن تُركت فارغة تُولَّد تلقائيًا وتُعرض مرة واحدة</div>}
        </div>
        <div className="col-md-3">
          <select className="form-select" value={form.department_id} onChange={(e) => onChange({ ...form, department_id: e.target.value })} aria-label="القسم (اختياري)">
            <option value="">— القسم (اختياري) —</option>
            {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
          </select>
        </div>
        <div className="col-12">
          <label className="form-label mb-1">الكليات المرتبطة بها (اختر واحدًا أو أكثر):</label>
          <div className="d-flex flex-wrap gap-2">
            {faculties.map((f) => {
              const checked = form.faculty_ids.includes(f.id)
              return (
                <label key={f.id} className="border rounded px-2 py-1 small" style={{ cursor: 'pointer', background: checked ? '#0A1D3B' : '#fff', color: checked ? '#fff' : '#0A1D3B' }}>
                  <input type="checkbox" className="d-none" checked={checked} onChange={() => onChange({ ...form, faculty_ids: checked ? form.faculty_ids.filter((id) => id !== f.id) : [...form.faculty_ids, f.id] })} />
                  {f.name}
                </label>
              )
            })}
          </div>
        </div>
        <div className="col-12 d-flex gap-2">
          <button className="aust-btn" disabled={saving || !form.full_name || !form.email || form.faculty_ids.length === 0 || (editing === null && !form.username)} onClick={onSave}>{saving ? '…' : editing !== null ? 'حفظ' : 'إضافة'}</button>
          <button className="aust-btn-outline" onClick={onCancel}>إلغاء</button>
        </div>
      </div>
    </div>
  )
}
