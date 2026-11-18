import { useEffect, useRef, useState } from 'react'
import { BASE, type Call } from '../api'
import type { SheetConfig } from '../types/sheet'
import { OfficialTimetableSheet } from './OfficialTimetable'
import {
  ContactSection,
  MastheadSection,
  NoteSignaturesSection,
  PageSection,
  TableSection,
  TitleSection,
} from './CouncilSheetEditorSections'

export default function CouncilSheetEditorView({ call }: { call: Call }) {
  const [draft, setDraft] = useState<SheetConfig | null>(null)
  const [saved, setSaved] = useState<SheetConfig | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [uploading, setUploading] = useState<'university' | 'college' | null>(null)
  const [previewOpen, setPreviewOpen] = useState(false)
  const uniInputRef = useRef<HTMLInputElement>(null)
  const colInputRef = useRef<HTMLInputElement>(null)

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
      await call('/api/council/sheet-settings', 'PUT', draft)
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
        <h1 className="h3 fw-bold mb-0 aust-h1">محرر الورقة الرسمية</h1>
        <div className="d-flex gap-2">
          <button className="aust-btn-outline" disabled={saving || !dirty} onClick={resetAll}>↻ استرجاع الافتراضي</button>
          <button className="aust-btn" disabled={saving || !dirty} onClick={save}>{saving ? 'جارٍ الحفظ…' : 'حفظ التغييرات'}</button>
        </div>
      </div>
      <p className="small text-muted mb-3">تحكم في كل خصايص الورقة من أعلى لأسفل: الترويسة ← العنوان ← شكل الجدول ← التوقيعات ← الشريط السفلي. المعاينة بالأسفل حية.</p>
      {msg && <div className="alert alert-success py-2">{msg}</div>}
      {err && <div className="alert alert-danger py-2">{err}</div>}

      <MastheadSection draft={draft} set={set} uniInputRef={uniInputRef} colInputRef={colInputRef} uploading={uploading} onUploadLogo={uploadLogo} />
      <TitleSection draft={draft} set={set} />
      <TableSection draft={draft} set={set} />
      <NoteSignaturesSection draft={draft} set={set} />
      <ContactSection draft={draft} set={set} />
      <PageSection draft={draft} set={set} />

      <div className="alert alert-info">
        <strong>تذكير مهم:</strong> كل تعديل هنا ينعكس فورًا على كل الورقات الرسمية المطبوعة في الكليات. المدير لا يمس أي خصايص — ولكي تنتقل التعديلات لغيرك، يجب أن ترى ورقتك الرسمية عبر «الجداول الدراسية ← الورقة الرسمية» بعد الحفظ.
      </div>

      {/* ── معاينة حية ── */}
      <div className="aust-panel p-3 mb-3 d-flex align-items-center justify-content-between gap-2">
        <div>
          <h2 className="h6 fw-bold mb-1" style={{ color: '#0A1D3B' }}>المعاينة الحية</h2>
          <div className="small text-muted">أدخل / افتح الجدول الرسمي: <strong>«الجداول الدراسية» ← اختيار الشعبة ← «الورقة الرسمية»</strong></div>
        </div>
        <button className="aust-btn-outline" onClick={() => setPreviewOpen(true)}>معاينة الورقة</button>
      </div>
      {previewOpen && <OfficialTimetableSheet call={call} groupId={1} onClose={() => setPreviewOpen(false)} overrideConfig={draft} />}
    </>
  )
}
