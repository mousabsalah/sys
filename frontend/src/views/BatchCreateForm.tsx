import type { CreateFormState, Master } from './CouncilBatchAccountsTypes'

/**
 * BatchCreateForm — نموذج «دفعة جديدة» (اسم + سعة + المسارات + القسم/البرنامج + برنامج اختياري جديد).
 */
export default function BatchCreateForm({ departments, programs, form, onChange, newProgram, onNewProgramChange, creatingProgram, onCreateProgram, saving, onSubmit }: {
  departments: Master['departments']
  programs: Master['programs']
  form: CreateFormState
  onChange: (f: CreateFormState) => void
  newProgram: string
  onNewProgramChange: (v: string) => void
  creatingProgram: boolean
  onCreateProgram: () => void
  saving: boolean
  onSubmit: () => void
}) {
  return (
    <div className="aust-panel p-3 mb-3">
      <h2 className="h6 fw-bold mb-2 aust-h2">دفعة جديدة</h2>
      <div className="d-flex gap-2 flex-wrap align-items-center">
        <input className="form-control" style={{ maxWidth: 200 }} placeholder="اسم الدفعة مثل دفعة 2026" value={form.name} onChange={(e) => onChange({ ...form, name: e.target.value })} />
        <input className="form-control" style={{ maxWidth: 130 }} type="number" min={1} dir="ltr" placeholder="السعة" value={form.student_count} onChange={(e) => onChange({ ...form, student_count: e.target.value })} />
        <label className="form-check-label d-flex align-items-center gap-1"><input type="checkbox" className="form-check-input m-0" checked={form.دبلوم} onChange={(e) => onChange({ ...form, دبلوم: e.target.checked })} /> دبلوم</label>
        <label className="form-check-label d-flex align-items-center gap-1"><input type="checkbox" className="form-check-input m-0" checked={form.بكلاريوس} onChange={(e) => onChange({ ...form, بكلاريوس: e.target.checked })} /> بكلاريوس</label>
        <select className="form-select" style={{ maxWidth: 220 }} value={form.department_id} onChange={(e) => onChange({ ...form, department_id: e.target.value })} aria-label="القسم">
          <option value="">— اختر القسم —</option>
          {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
        <select className="form-select" style={{ maxWidth: 220 }} value={form.program_id} onChange={(e) => onChange({ ...form, program_id: e.target.value })} aria-label="البرنامج">
          <option value="">— اختر البرنامج —</option>
          {programs.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
        <input className="form-control" style={{ maxWidth: 200 }} placeholder="برنامج جديد (اتركه اختياريًا للدفعات الفارغة)" value={newProgram} onChange={(e) => onNewProgramChange(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); onCreateProgram() } }} />
        <button className="aust-btn-outline btn-sm" disabled={creatingProgram || !newProgram.trim()} onClick={onCreateProgram}>{creatingProgram ? '…' : '+ برنامج'}</button>
        <button className="aust-btn" disabled={saving || !form.name.trim() || !(form.دبلوم || form.بكلاريوس) || !form.program_id} onClick={onSubmit}>{saving ? '…' : 'إنشاء'}</button>
      </div>
      <p className="small text-muted mt-2 mb-0">بالشعبة المحددة — تُنشأ شعبة لكل مسار وتُقسم السعة بالتساوي (الباقي للدبلوم). إذا لم تجد البرنامج المطلوب في القائمة، اكتب اسمه في الحقل بجانبها واضغط «+ برنامج».</p>
    </div>
  )
}
