import { useEffect, useState } from 'react'
import { type Call } from '../api'
import ProposeView from './Propose'
import ProposeExamsView from './ProposeExams'
import CouncilSchedulesView from './CouncilSchedules'
import CouncilExamSchedulesView from './CouncilExamSchedules'

const TABS = [
  { id: 'propose', label: 'اقتراح المحاضرات' },
  { id: 'propose-exams', label: 'اقتراح الامتحانات' },
  { id: 'schedules', label: 'الجداول الدراسية' },
  { id: 'exam-schedules', label: 'جدول الامتحانات' },
] as const
type TabId = (typeof TABS)[number]['id']

export default function SchedulingWorkspace({ call }: { call: Call }) {
  const [tab, setTab] = useState<TabId>('propose')
  const [pendingLectures, setPendingLectures] = useState<number | null>(null)
  const [pendingExams, setPendingExams] = useState<number | null>(null)

  useEffect(() => {
    let alive = true
    Promise.allSettled([
      call<{ groups: { counts: { pending: number } }[] }>('/api/council/schedules?term=2026/1'),
      call<{ groups: { counts: { pending: number } }[] }>('/api/council/exam-schedules?term=2026/1'),
    ]).then(([lec, exm]) => {
      if (!alive) return
      if (lec.status === 'fulfilled') setPendingLectures(lec.value.groups.reduce((sum, g) => sum + (g.counts?.pending ?? 0), 0))
      if (exm.status === 'fulfilled') setPendingExams(exm.value.groups.reduce((sum, g) => sum + (g.counts?.pending ?? 0), 0))
    })
    return () => { alive = false }
  }, [call, tab])

  const onKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    const ids = TABS.map((t) => t.id)
    const idx = ids.indexOf(tab)
    const target = e.key === 'ArrowLeft' ? ids[(idx + 1) % ids.length]
      : e.key === 'ArrowRight' ? ids[(idx - 1 + ids.length) % ids.length]
      : e.key === 'Home' ? ids[0]
      : e.key === 'End' ? ids[ids.length - 1]
      : null
    if (target) {
      e.preventDefault()
      setTab(target)
      ;(document.getElementById(`sched-tab-${target}`) as HTMLButtonElement | null)?.focus()
    }
  }

  const badge = (n: number | null) => (n && n > 0 ? <span className="sched-badge" title={`${n} معلّق بانتظار اعتماد المدير`}>{n}</span> : null)

  return (
    <>
      {/* ترويسة موحدة للشاشة — هوية AUST + شارة مميزة */}
      <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3 border-bottom pb-3" style={{ borderColor: 'var(--aust-border)' }}>
        <div>
          <div className="d-flex align-items-center gap-2 mb-1">
            <span style={{ display: 'inline-block', width: 4, height: 26, background: 'var(--aust-accent)', borderRadius: 2 }} aria-hidden="true" />
            <h1 className="h3 fw-bold mb-0 aust-h1">لوحة الجدولة</h1>
          </div>
          <p className="small text-muted mb-0">جامعة الشرق للعلوم والتكنولوجيا — المقترح ثم الاعتماد: «اقتراح» ← معاملة واحدة «اعتمد الكل» ← اعتماد المدير لكل ما يظهر للطلاب والمدرسين.</p>
        </div>
        <span className="small fw-bold" style={{ color: 'var(--aust-primary-dark)', background: '#fdf4e8', border: '1px solid var(--aust-accent)', borderRadius: 8, padding: '4px 12px' }}>مجلس المدرسين</span>
      </div>

      {/* التبويبات — ثابتة العرض على الشاشات الكبيرة، تمرر أفقيًا على المقاسات الصغيرة */}
      <div className="sched-tabs" role="tablist" aria-label="محاور لوحة الجدولة" onKeyDown={onKeyDown}>
        {TABS.map((t) => (
          <button
            key={t.id}
            id={`sched-tab-${t.id}`}
            type="button"
            role="tab"
            aria-selected={tab === t.id}
            aria-controls={`sched-panel-${t.id}`}
            className="sched-tab"
            onClick={() => setTab(t.id)}
          >
            {t.label}
            {t.id === 'schedules' && badge(pendingLectures)}
            {t.id === 'exam-schedules' && badge(pendingExams)}
          </button>
        ))}
      </div>

      {/* اللوحات — كلها تبقى مركبة (الحالة محفوظة عند التنقل) */}
      <div role="tabpanel" id="sched-panel-propose" aria-labelledby="sched-tab-propose" hidden={tab !== 'propose'}>
        <ProposeView call={call} onAdopted={() => setTab('schedules')} embedded />
      </div>
      <div role="tabpanel" id="sched-panel-propose-exams" aria-labelledby="sched-tab-propose-exams" hidden={tab !== 'propose-exams'}>
        <ProposeExamsView call={call} embedded />
      </div>
      <div role="tabpanel" id="sched-panel-schedules" aria-labelledby="sched-tab-schedules" hidden={tab !== 'schedules'}>
        <CouncilSchedulesView call={call} embedded />
      </div>
      <div role="tabpanel" id="sched-panel-exam-schedules" aria-labelledby="sched-tab-exam-schedules" hidden={tab !== 'exam-schedules'}>
        <CouncilExamSchedulesView call={call} embedded />
      </div>
    </>
  )
}
