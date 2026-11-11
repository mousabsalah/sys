import ReactEChartsCore from 'echarts-for-react/esm/core'
import * as echarts from 'echarts/core'
import { BarChart, PieChart, LineChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import { useEffect, useState } from 'react'

echarts.use([BarChart, PieChart, LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer])

export type AdminStats = {
  kpis: {
    halls_count: number; halls_available_now: number; total_students: number; faculty_members: number
    lectures_total: number; exams_total: number; bookings_total: number; batches_count: number
    courses_count: number; groups_count: number
  }
  halls: { id: number; name: string; capacity: number; hall_type: string; status: string; lectures: number; exams: number; bookings: number }[]
  day_distribution: { day: string; lectures: number }[]
  faculty_share: { id: number; name: string; lectures: number }[]
  semester_activity: { id: number; academic_term: string; faculty?: string; department?: string; batch?: string; start_date: string | null; archived_at: string | null; lectures: number; exams: number; bookings: number }[]
}

const C = { navy: '#0A1D3B', orange: '#B26225', accent: '#D4822D', red: '#782B19', sand: '#e5ded4' }

function useCountUp(target: number, ms = 750) {
  const [value, setValue] = useState(0)
  useEffect(() => {
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      setValue(target)
      return
    }
    const start = performance.now()
    let frame = 0
    const tick = (now: number) => {
      const t = Math.min(1, (now - start) / ms)
      const eased = 1 - Math.pow(2, -10 * t)
      setValue(Math.round(target * (t === 1 ? 1 : eased)))
      if (t < 1) frame = requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(frame)
  }, [target, ms])
  return value
}

function KpiCard({ label, value }: { label: string; value: number }) {
  const n = useCountUp(value)
  return (
    <div className="aust-panel p-2 text-center h-100 kpi-card">
      <div className="fs-3 fw-bold lh-1" style={{ color: C.navy }} dir="ltr">{n}</div>
      <div className="small text-muted mt-1" style={{ fontSize: 11 }}>{label}</div>
    </div>
  )
}

type HallT = AdminStats['halls'][number]

function hallTier(h: HallT): 'idle' | 'busy' | 'hot' {
  if (h.lectures === 0) return 'idle'
  if (h.lectures <= 5) return 'busy'
  return 'hot'
}

function HallTile({ hall }: { hall: HallT }) {
  const tier = hallTier(hall)
  const border = hall.status === 'صيانة' ? '#B26225' : hall.status === 'مغلقة' ? '#782B19' : tier === 'hot' ? '#B26225' : tier === 'busy' ? '#D4822D' : '#0A1D3B'
  const lecturesPct = Math.min(100, (hall.lectures / 15) * 100)
  return (
    <div
      className={`hall-tile hall-${hall.status === 'صيانة' ? 'maint' : hall.status === 'مغلقة' ? 'closed' : tier}`}
      style={{ borderColor: border }}
      title={`${hall.name} · سعة ${hall.capacity} · ${hall.lectures} محاضرة · ${hall.exams} امتحان · ${hall.bookings} حجز`}
    >
      <div className="d-flex justify-content-between align-items-start">
        <span className="hall-kind">{hall.hall_type === 'معمل' ? '⚗' : '▦'}</span>
        <span className={`hall-dot ${hall.status === 'متاحة' ? 'ok' : 'warn'}`} />
      </div>
      <div className="hall-name">{hall.name}</div>
      <div className="hall-meta"><span dir="ltr">{hall.capacity}</span> مقعد · <span dir="ltr">{hall.lectures}</span> محاضرة</div>
      <div className="hall-bar"><i style={{ width: `${lecturesPct}%`, background: border }} /></div>
    </div>
  )
}

export default function AdminStatsView({ stats }: { stats: AdminStats }) {
  const hallBars = stats.halls.slice(0, 12)
  const hallGradient = {
    type: 'linear' as const, x: 0, y: 0, x2: 1, y2: 0,
    colorStops: [{ offset: 0, color: C.accent }, { offset: 1, color: C.orange }],
  }
  const hallBarOption = {
    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, textStyle: { fontFamily: 'inherit' } },
    grid: { left: 8, right: 8, top: 8, bottom: 4, containLabel: true },
    xAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { type: 'dashed', color: C.sand } } },
    yAxis: { type: 'category', inverse: true, data: hallBars.map((h) => h.name), axisLine: { show: false }, axisTick: { show: false } },
    series: [{
      name: 'المحاضرات',
      type: 'bar',
      data: hallBars.map((h) => h.lectures),
      itemStyle: { borderRadius: [4, 0, 0, 4], color: hallGradient },
      barMaxWidth: 18,
    }],
  }

  const dayOption = {
    tooltip: { trigger: 'axis' },
    grid: { left: 8, right: 8, top: 24, bottom: 4, containLabel: true },
    xAxis: { type: 'category', data: stats.day_distribution.map((d) => d.day), axisTick: { show: false } },
    yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { type: 'dashed', color: C.sand } } },
    series: [{ name: 'المحاضرات', type: 'bar', data: stats.day_distribution.map((d) => d.lectures), itemStyle: { borderRadius: [6, 6, 0, 0], color: C.navy }, barMaxWidth: 42 }],
  }

  const facultyOption = {
    tooltip: { trigger: 'item', formatter: '{b}<br/>المحاضرات: {c} ({d}%)' },
    legend: { bottom: 0, textStyle: { fontFamily: 'inherit' } },
    series: [{
      name: 'الحصة', type: 'pie', radius: ['48%', '74%'], itemStyle: { borderRadius: 6, borderColor: '#fff', borderWidth: 2 },
      label: { formatter: '{b}', fontFamily: 'inherit' },
      data: stats.faculty_share.map((f, i) => ({ name: f.name, value: f.lectures, itemStyle: { color: [C.orange, C.navy, C.accent][i % 3] } })),
    }],
  }

  const semOption = {
    tooltip: { trigger: 'axis' },
    legend: { top: 0, textStyle: { fontFamily: 'inherit' } },
    grid: { left: 8, right: 8, top: 32, bottom: 4, containLabel: true },
    xAxis: { type: 'category', data: stats.semester_activity.map((s) => `${s.academic_term} · ${s.batch ?? ''}`), axisLabel: { interval: 0, rotate: 25, fontSize: 10 } },
    yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { type: 'dashed', color: C.sand } } },
    series: [
      { name: 'المحاضرات', type: 'line', smooth: 0.4, symbol: 'circle', symbolSize: 8, data: stats.semester_activity.map((s) => s.lectures), itemStyle: { color: C.orange }, lineStyle: { width: 3 } },
      { name: 'الامتحانات', type: 'line', smooth: 0.4, symbol: 'circle', symbolSize: 8, data: stats.semester_activity.map((s) => s.exams), itemStyle: { color: C.red }, lineStyle: { width: 3, type: 'dashed' } },
    ],
  }

  return (
    <>
      {/* ── شريط المؤشرات ── */}
      <div className="row g-2 mb-4">
        <div className="col-6 col-md-3 col-xl-auto flex-fill"><KpiCard label="القاعات المسجلة" value={stats.kpis.halls_count} /></div>
        <div className="col-6 col-md-3 col-xl-auto flex-fill"><KpiCard label="قاعات متاحة الآن" value={stats.kpis.halls_available_now} /></div>
        <div className="col-6 col-md-3 col-xl-auto flex-fill"><KpiCard label="إجمالي الطلاب" value={stats.kpis.total_students} /></div>
        <div className="col-6 col-md-3 col-xl-auto flex-fill"><KpiCard label="أعضاء هيئة التدريس" value={stats.kpis.faculty_members} /></div>
        <div className="col-6 col-md-3 col-xl-auto flex-fill"><KpiCard label="المحاضرات المسجلة" value={stats.kpis.lectures_total} /></div>
        <div className="col-6 col-md-3 col-xl-auto flex-fill"><KpiCard label="الامتحانات" value={stats.kpis.exams_total} /></div>
        <div className="col-6 col-md-3 col-xl-auto flex-fill"><KpiCard label="الحجوزات" value={stats.kpis.bookings_total} /></div>
      </div>

      {/* ── الخريطة الهندسية للقاعات ── */}
      <section className="aust-panel p-3 mb-4 hall-map-panel">
        <div className="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
          <h2 className="h5 fw-bold mb-0" style={{ color: C.red }}>الخريطة الهندسية للقاعات</h2>
          <div className="d-flex gap-2 small">
            <span className="legend-chip"><i style={{ background: C.navy }} />فارغة</span>
            <span className="legend-chip"><i style={{ background: C.accent }} />مشغولة</span>
            <span className="legend-chip"><i style={{ background: C.orange }} />الأكثر استخدامًا</span>
            <span className="legend-chip"><i style={{ background: C.red }} />مغلقة</span>
          </div>
        </div>
        <div className="hall-map-grid">
          {stats.halls.map((h) => <HallTile key={h.id} hall={h} />)}
        </div>
      </section>

      {/* ── الرسوم البيانية ── */}
      <div className="row g-3 mb-4">
        <div className="col-lg-7">
          <section className="aust-panel p-3 h-100">
            <h2 className="h6 fw-bold mb-1" style={{ color: C.red }}>المحاضرات لكل قاعة (الأعلى)</h2>
            <ReactEChartsCore echarts={echarts} option={hallBarOption} style={{ height: 280 }} notMerge lazyUpdate />
          </section>
        </div>
        <div className="col-lg-5">
          <section className="aust-panel p-3 h-100">
            <h2 className="h6 fw-bold mb-1" style={{ color: C.red }}>توزيع المحاضرات حسب الكلية</h2>
            <ReactEChartsCore echarts={echarts} option={facultyOption} style={{ height: 280 }} notMerge lazyUpdate />
          </section>
        </div>
        <div className="col-lg-5">
          <section className="aust-panel p-3 h-100">
            <h2 className="h6 fw-bold mb-1" style={{ color: C.red }}>المحاضرات حسب اليوم</h2>
            <ReactEChartsCore echarts={echarts} option={dayOption} style={{ height: 240 }} notMerge lazyUpdate />
          </section>
        </div>
        <div className="col-lg-7">
          <section className="aust-panel p-3 h-100">
            <h2 className="h6 fw-bold mb-1" style={{ color: C.red }}>المحاضرات والامتحانات لكل فصل دراسي</h2>
            <ReactEChartsCore echarts={echarts} option={semOption} style={{ height: 240 }} notMerge lazyUpdate />
          </section>
        </div>
      </div>
    </>
  )
}
