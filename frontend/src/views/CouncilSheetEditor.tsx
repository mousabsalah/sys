import { useEffect, useRef, useState } from 'react'
import { BASE, useAuthedApi } from '../api'
import type { SheetConfig } from '../types/sheet'
import { OfficialTimetableSheet } from './OfficialTimetable'

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <label className="d-block mb-2">
      <span className="small fw-bold" style={{ color: '#0A1D3B' }}>{label}</span>
      {hint ? <span className="small text-muted d-block">{hint}</span> : null}
      <span className="d-block mt-1">{children}</span>
    </label>
  )
}
function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <label className="form-check form-switch d-inline-flex align-items-center gap-2 mb-2">
      <input type="checkbox" className="form-check-input m-0" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      <span className="small fw-bold" style={{ color: '#0A1D3B' }}>{label}</span>
    </label>
  )
}
function ColorInput({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <label className="d-inline-flex align-items-center gap-2 me-3 mb-2">
      <input type="color" className="form-control form-control-color form-control-sm" style={{ width: 46, padding: 2 }} value={value} onChange={(e) => onChange(e.target.value)} aria-label={label} />
      <span className="small">{label}</span>
    </label>
  )
}
function NumInput({ label, value, onChange, min, max, suffix }: { label: string; value: number; onChange: (v: number) => void; min: number; max: number; suffix?: string }) {
  return (
    <label className="d-inline-flex align-items-center gap-2 me-3 mb-2">
      <input type="number" className="form-control form-control-sm" style={{ width: 90 }} min={min} max={max} dir="ltr" value={value} onChange={(e) => onChange(Number(e.target.value))} />
      <span className="small">{label}{suffix ?? ''}</span>
    </label>
  )
}

export default function CouncilSheetEditorView({ call }: { call: ReturnType<typeof useAuthedApi> }) {
  const [draft, setDraft] = useState<SheetConfig | null>(null)
  const [saved, setSaved] = useState<SheetConfig | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [uploading, setUploading] = useState<'university' | 'college' | null>(null)
  const [previewOpen, setPreviewOpen] = useState(false)
  const uniInputRef = useRef<HTMLInputElement>(null)
  const colInputRef = useRef<HTMLInputElement>(null)

  // تلميحات لأرقام الجموعات
  const section = (title: string, children: React.ReactNode) => (
    <fieldset className="border rounded p-3 mb-3">
      <legend className="h6 fw-bold px-2 mb-2" style={{ color: '#B26225', float: 'none', width: 'auto' }}>{title}</legend>
      {children}
    </fieldset>
  )

  async function load() {
    try {
      const d = await call<{ settings: SheetConfig }>('/api/council/sheet-settings')
      setDraft(structuredClone(d.settings))
      setSaved(structuredClone(d.settings))
      setErr(null)
    } catch (e) { setErr((e as Error).message) }
  }
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [call])

  function set<K extends keyof SheetConfig>(key: K, value: SheetConfig[K]) {
    setDraft((d) => (d ? { ...d, [key]: value } : d))
  }

  async function save() {
    if (!draft) return
    setSaving(true); setErr(null); setMsg(null)
    try {
      const body = draft
      await call('/api/council/sheet-settings', 'PUT', body)
      setSaved(structuredClone(draft))
      setMsg('تم حفظ إعدادات الورقة الرسمية ✓')
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function resetAll() {
    if (!window.confirm('إعادة كل خصايص الورقة إلى الوضع الافتراضي؟')) return
    setSaving(true); setErr(null); setMsg(null)
    try {
      const d = await call<{ ok: boolean; settings: SheetConfig }>('/api/council/sheet-settings/reset', 'POST')
      setDraft(structuredClone(d.settings))
      setSaved(structuredClone(d.settings))
      setMsg('تمت إعادة الورقة للحالة الافتراضية ✓')
    } catch (e) { setErr((e as Error).message) } finally { setSaving(false) }
  }

  async function uploadLogo(slot: 'university' | 'college', file: File) {
    setUploading(slot); setErr(null); setMsg(null)
    try {
      const token = sessionStorage.getItem('aust_token')
      const fd = new FormData()
      fd.append('slot', slot)
      fd.append('logo', file)
      const res = await fetch(BASE + '/api/council/sheet-settings/logo', {
        method: 'POST',
        headers: token ? { Authorization: 'Bearer ' + token } : undefined,
        body: fd,
      })
      const j = await res.json()
      if (!res.ok) throw new Error(j.message_ar ?? j.message ?? 'فشل الرفع')
      setDraft((d) => (d ? { ...d, [slot === 'university' ? 'university_logo_url' : 'college_logo_url']: j.url } : d))
      setMsg('تم رفع الشعار ✓')
    } catch (e) { setErr((e as Error).message) } finally { setUploading(null) }
  }

  if (err && !draft) return <div className="alert alert-danger">{err}</div>
  if (!draft) return <div className="text-muted p-4">جارٍ تحميل الإعدادات…</div>

  const dirty = saved ? JSON.stringify(saved) !== JSON.stringify(draft) : false

  return (
    <>
      <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <h1 className="h3 fw-bold mb-0" style={{ color: '#0A1D3B' }}>محرر الورقة الرسمية</h1>
        <div className="d-flex gap-2">
          <button className="aust-btn-outline" disabled={saving || !dirty} onClick={resetAll}>↻ استرجاع الافتراضي</button>
          <button className="aust-btn" disabled={saving || !dirty} onClick={save}>{saving ? 'جارٍ الحفظ…' : 'حفظ التغييرات'}</button>
        </div>
      </div>
      <p className="small text-muted mb-3">تحكم في كل خصايص الورقة من أعلى لأسفل: الترويسة ← العنوان ← شكل الجدول ← التوقيعات ← الشريط السفلي. المعاينة بالأسفل حية.</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <div className="alert alert-danger py-2">{err}</div>}

      {/* ═══ الترويسة ═══ */}
      {section('الترويسة (أعلى الورقة)', (
        <>
          <Toggle label="إظهار الجامعة والكلية" checked={draft.university_enabled} onChange={(v) => set('university_enabled', v)} />
          <div className="row g-2">
            <div className="col-md-6"><Field label="اسم الجامعة (عربي)"><input className="form-control" value={draft.university_name_ar} onChange={(e) => set('university_name_ar', e.target.value)} /></Field></div>
            <div className="col-md-6"><Field label="اسم الجامعة (English)"><input className="form-control" dir="ltr" value={draft.university_name_en} onChange={(e) => set('university_name_en', e.target.value)} /></Field></div>
            <div className="col-md-6"><Field label="اسم الكلية (عربي)"><input className="form-control" value={draft.college_name_ar} onChange={(e) => set('college_name_ar', e.target.value)} /></Field></div>
            <div className="col-md-4"><Field label="اسم الكلية (English)"><input className="form-control" dir="ltr" value={draft.college_name_en} onChange={(e) => set('college_name_en', e.target.value)} /></Field></div>
            <div className="col-md-2"><Field label="كود الكلية"><input className="form-control" dir="ltr" value={draft.college_code} onChange={(e) => set('college_code', e.target.value)} /></Field></div>
          </div>
          <div className="d-flex flex-wrap align-items-center gap-3 mt-2">
            <span>
              <span className="small fw-bold me-2">شعار الجامعة</span>
              <button className="aust-btn-outline btn-sm" disabled={uploading === 'university'} onClick={() => uniInputRef.current?.click()}>{uploading === 'university' ? 'جارٍ الرفع…' : (draft.university_logo_url ? 'استبدال' : 'رفع شعار')}</button>
              {draft.university_logo_url && <button className="btn btn-link btn-sm text-danger" onClick={() => set('university_logo_url', null)}>إزالة</button>}
              <input ref={uniInputRef} type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadLogo('university', f); e.target.value = '' }} />
            </span>
            <span>
              <span className="small fw-bold me-2">شعار الكلية</span>
              <button className="aust-btn-outline btn-sm" disabled={uploading === 'college'} onClick={() => colInputRef.current?.click()}>{uploading === 'college' ? 'جارٍ الرفع…' : (draft.college_logo_url ? 'استبدال' : 'رفع شعار')}</button>
              {draft.college_logo_url && <button className="btn btn-link btn-sm text-danger" onClick={() => set('college_logo_url', null)}>إزالة</button>}
              <input ref={colInputRef} type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadLogo('college', f); e.target.value = '' }} />
            </span>
            <ColorInput label="الخلفية" value={draft.masthead_bg} onChange={(v) => set('masthead_bg', v)} />
            <ColorInput label="الخط السفلي" value={draft.masthead_border} onChange={(v) => set('masthead_border', v)} />
          </div>
        </>
      ))}

      {/* ═══ اللافتة (العنوان) ═══ */}
      {section('لافتة العنوان', (
        <>
          <Toggle label="إظهار اللافتة" checked={draft.title_enabled} onChange={(v) => set('title_enabled', v)} />
          <div className="row g-2">
            <div className="col-md-3"><Field label="الكلمة الأولى"><input className="form-control" value={draft.title_prefix} onChange={(e) => set('title_prefix', e.target.value)} placeholder="جدول" /></Field></div>
            <div className="col-md-3"><Field label="الدرجة"><input className="form-control" value={draft.title_degree_word} onChange={(e) => set('title_degree_word', e.target.value)} placeholder="بكالوريوس" /></Field></div>
            <div className="col-md-3"><NumInput label="حجم الخط" value={draft.title_font_size} onChange={(v) => set('title_font_size', v)} min={10} max={40} suffix="px" /></div>
            <div className="col-md-3"><NumInput label="السمك" value={draft.title_weight} onChange={(v) => set('title_weight', v)} min={100} max={900} /></div>
          </div>
          <ColorInput label="الخلفية" value={draft.title_bg} onChange={(v) => set('title_bg', v)} />
          <ColorInput label="اللون" value={draft.title_color} onChange={(v) => set('title_color', v)} />
          <ColorInput label="الإطار" value={draft.title_border} onChange={(v) => set('title_border', v)} />
          <NumInput label="هامش داخلي" value={draft.title_padding} onChange={(v) => set('title_padding', v)} min={2} max={30} suffix="px" />
        </>
      ))}

      {/* ═══ الجدول ═══ */}
      {section('الجدول الأسبوعي', (
        <>
          <NumInput label="عدد الفترة" value={draft.column_count} onChange={(v) => set('column_count', v)} min={1} max={6} />
          <div className="d-flex flex-wrap gap-2 mt-2 mb-2">
            {draft.period_labels.map((label, i) => (
              <div key={i} className="border rounded p-2" style={{ minWidth: 180 }}>
                <Field label={`اسم الفترة ${i + 1}`}><input className="form-control form-control-sm" dir="rtl" value={label} onChange={(e) => { const next = [...draft.period_labels]; next[i] = e.target.value; set('period_labels', next) }} /></Field>
                <div className="d-flex gap-1">
                  <input className="form-control form-control-sm" dir="ltr" type="time" value={draft.period_times[i]?.[0] ?? '07:00'} onChange={(e) => { const next = [...draft.period_times]; next[i] = [e.target.value, draft.period_times[i]?.[1] ?? '09:00']; set('period_times', next) }} />
                  <input className="form-control form-control-sm" dir="ltr" type="time" value={draft.period_times[i]?.[1] ?? '09:00'} onChange={(e) => { const next = [...draft.period_times]; next[i] = [draft.period_times[i]?.[0] ?? '07:00', e.target.value]; set('period_times', next) }} />
                </div>
              </div>
            ))}
          </div>
          <ColorInput label="إطار الجدول" value={draft.table_border} onChange={(v) => set('table_border', v)} />
          <NumInput label="سمك الإطار الخارجي" value={draft.table_border_width} onChange={(v) => set('table_border_width', v)} min={0.5} max={6} suffix="px" />
          <NumInput label="سمك خطوط الخلايا" value={draft.table_cell_border_width} onChange={(v) => set('table_cell_border_width', v)} min={0.3} max={4} suffix="px" />
          <ColorInput label="رأس الفترة" value={draft.header_bg} onChange={(v) => set('header_bg', v)} />
          <ColorInput label="لون الخط رأسي" value={draft.header_color} onChange={(v) => set('header_color', v)} />
          <ColorInput label="رأس اليوم" value={draft.day_bg} onChange={(v) => set('day_bg', v)} />
          <ColorInput label="خلفية الخلايا" value={draft.cell_bg} onChange={(v) => set('cell_bg', v)} />
          <ColorInput label="الخلايا الفارغة" value={draft.cell_empty_bg} onChange={(v) => set('cell_empty_bg', v)} />
          <ColorInput label="الملغية" value={draft.cancelled_color} onChange={(v) => set('cancelled_color', v)} />
          <NumInput label="حجم خط الرأس" value={draft.header_font_size} onChange={(v) => set('header_font_size', v)} min={8} max={30} suffix="px" />
          <NumInput label="حجم خط اليوم" value={draft.day_font_size} onChange={(v) => set('day_font_size', v)} min={8} max={30} suffix="px" />
          <NumInput label="حجم خط الخلية" value={draft.cell_font_size} onChange={(v) => set('cell_font_size', v)} min={8} max={24} suffix="px" />
          <NumInput label="ارتفاع الخلية" value={draft.cell_height} onChange={(v) => set('cell_height', v)} min={40} max={160} suffix="px" />
        </>
      ))}

      {/* ═══ ملاحظة + التوقيعات ═══ */}
      {section('ملاحظة بدء الدوام والتوقيعات', (
        <>
          <Toggle label="إظهار ملاحظة الدوام" checked={draft.note_enabled} onChange={(v) => set('note_enabled', v)} />
          <NumInput label="حجم خط الملاحظة" value={draft.note_font_size} onChange={(v) => set('note_font_size', v)} min={8} max={20} suffix="px" />
          <NumInput label="سمك الملاحظة" value={draft.note_weight} onChange={(v) => set('note_weight', v)} min={100} max={900} />
          <hr className="my-2" />
          <Toggle label="إظهار التوقيعات" checked={draft.signatures_enabled} onChange={(v) => set('signatures_enabled', v)} />
          <NumInput label="حجم خط التوقيعات" value={draft.signature_font_size} onChange={(v) => set('signature_font_size', v)} min={8} max={22} suffix="px" />
          <div className="row g-2">
            <div className="col-md-6">
              <Field label="التوقيع الأيمن — المسمى"><input className="form-control form-control-sm" value={draft.signature_one_label} onChange={(e) => set('signature_one_label', e.target.value)} /></Field>
              <Field label="داخل الختم سطر 1"><input className="form-control form-control-sm" dir="ltr" value={draft.signature_one_inside_1} onChange={(e) => set('signature_one_inside_1', e.target.value)} /></Field>
              <Field label="داخل الختم سطر 2"><input className="form-control form-control-sm" dir="ltr" value={draft.signature_one_inside_2} onChange={(e) => set('signature_one_inside_2', e.target.value)} /></Field>
              <Field label="داخل الختم سطر 3"><input className="form-control form-control-sm" dir="ltr" value={draft.signature_one_inside_3} onChange={(e) => set('signature_one_inside_3', e.target.value)} /></Field>
            </div>
            <div className="col-md-6">
              <Field label="التوقيع الأيسر — المسمى"><input className="form-control form-control-sm" value={draft.signature_two_label} onChange={(e) => set('signature_two_label', e.target.value)} /></Field>
              <Field label="داخل الختم سطر 1"><input className="form-control form-control-sm" dir="ltr" value={draft.signature_two_inside_1} onChange={(e) => set('signature_two_inside_1', e.target.value)} /></Field>
              <Field label="داخل الختم سطر 2"><input className="form-control form-control-sm" dir="ltr" value={draft.signature_two_inside_2} onChange={(e) => set('signature_two_inside_2', e.target.value)} /></Field>
              <Field label="داخل الختم سطر 3"><input className="form-control form-control-sm" dir="ltr" value={draft.signature_two_inside_3} onChange={(e) => set('signature_two_inside_3', e.target.value)} /></Field>
            </div>
          </div>
        </>
      ))}

      {/* ═══ شريط الاتصال ═══ */}
      {section('شريط الاتصال (أسفل الورقة)', (
        <>
          <Toggle label="إظهار شريط الاتصال" checked={draft.contact_enabled} onChange={(v) => set('contact_enabled', v)} />
          <Field label="العلامة"><input className="form-control" value={draft.contact_brand} onChange={(e) => set('contact_brand', e.target.value)} /></Field>
          <div className="row g-2">
            <div className="col-md-4"><Field label="هاتف 1"><input className="form-control" dir="ltr" value={draft.contact_phone1} onChange={(e) => set('contact_phone1', e.target.value)} /></Field></div>
            <div className="col-md-4"><Field label="هاتف 2"><input className="form-control" dir="ltr" value={draft.contact_phone2} onChange={(e) => set('contact_phone2', e.target.value)} /></Field></div>
            <div className="col-md-4"><Field label="البريد"><input className="form-control" dir="ltr" value={draft.contact_email} onChange={(e) => set('contact_email', e.target.value)} /></Field></div>
            <div className="col-md-12"><Field label="الموقع"><input className="form-control" dir="ltr" value={draft.contact_url} onChange={(e) => set('contact_url', e.target.value)} /></Field></div>
          </div>
          <ColorInput label="الخلفية" value={draft.contact_bg} onChange={(v) => set('contact_bg', v)} />
          <ColorInput label="اللون" value={draft.contact_color} onChange={(v) => set('contact_color', v)} />
          <NumInput label="حجم الخط" value={draft.contact_font_size} onChange={(v) => set('contact_font_size', v)} min={6} max={20} suffix="px" />
        </>
      ))}

      {/* ═══ الورقة (Page setup) ═══ */}
      {section('الورقة — الـA4', (
        <>
          <NumInput label="هوامش رأس/قاع (mm)" value={draft.page_margin_mm} onChange={(v) => set('page_margin_mm', v)} min={3} max={20} suffix="mm" />
          <NumInput label="هوامش جوانب (mm)" value={draft.page_margin_side_mm} onChange={(v) => set('page_margin_side_mm', v)} min={3} max={20} suffix="mm" />
          <Field label="خط الورقة (font-family)" hint='اتركه فارغًا لافتراض "Noto Kufi Arabic, Noto Naskh Arabic, Simplified Arabic, Tahoma, serif"'>
            <input className="form-control" value={draft.font_family} onChange={(e) => set('font_family', e.target.value)} />
          </Field>
          <Field label="اتجاه الصفحة">
            <select className="form-select" value={draft.direction} onChange={(e) => set('direction', e.target.value as SheetConfig['direction'])}>
              <option value="rtl">RTL (عربي — الافتراضي)</option>
              <option value="ltr">LTR (إنجليزي)</option>
            </select>
          </Field>
        </>
      ))}

      <div className="alert alert-info">
        <strong>تذكير مهم:</strong> كل تعديل هنا ينعكس فورًا على كل الورقات الرسمية المطبوعة في الكليات. المدير لا يمس أي خصايص — ولكي تنتقل التعديلات لغيرك، يجب أن ترى ورقتك الرسمية عبر «الجداول ← الورقة الرسمية» بعد الحفظ.
      </div>

      {/* ── معاينة حية ── */}
      <div className="aust-panel p-3 mb-3 d-flex align-items-center justify-content-between gap-2">
        <div>
          <h2 className="h6 fw-bold mb-1" style={{ color: '#0A1D3B' }}>المعاينة الحية</h2>
          <div className="small text-muted">أدخل / افتح الجدول الرسمي: <strong>الجداول ← الشعبة ← 📄 الورقة الرسمية</strong></div>
        </div>
        <button className="aust-btn-outline" onClick={() => setPreviewOpen(true)}>معاينة الورقة</button>
      </div>
      {previewOpen && <OfficialTimetableSheet call={call} groupId={1} onClose={() => setPreviewOpen(false)} overrideConfig={draft} />}
    </>
  )
}
