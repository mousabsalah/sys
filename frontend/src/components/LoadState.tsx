/**
 * LoadState — كتل التحميل والخطأ الموحدة عبر الشاشات.
 */
export function SkeletonRows({ rows = 3, height = 46 }: { rows?: number; height?: number }) {
  return (
    <div aria-hidden="true" className="d-grid gap-2">
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="aust-skeleton" style={{ height }} />
      ))}
    </div>
  )
}

export function SkeletonCards({ count = 6 }: { count?: number }) {
  return (
    <div className="row g-3 mt-2" aria-hidden="true">
      {Array.from({ length: count }, (_, i) => (
        <div className="col-md-4" key={i}>
          <div className="aust-skeleton" style={{ height: 150 }} />
        </div>
      ))}
    </div>
  )
}

export function ErrorRetry({ message, onRetry, retryLabel = 'إعادة المحاولة' }: { message: string; onRetry: () => void; retryLabel?: string }) {
  return (
    <div className="alert alert-danger d-flex align-items-center justify-content-between gap-2 mt-2" role="alert">
      <span>{message}</span>
      <button className="aust-btn-outline btn-sm" onClick={onRetry}>{retryLabel}</button>
    </div>
  )
}
