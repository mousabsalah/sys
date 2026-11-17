import { useCallback, useRef } from 'react'

/**
 * useSeqGuard — يحمي كتابة الحالة من السباق بين طلبات متتالية؛ آخر طلب يغلب القديم.
 * الاستخدام: const { next, isCurrent } = useSeqGuard(); const seq = next(); …if (!isCurrent(seq)) return;
 */
export default function useSeqGuard() {
  const seqRef = useRef(0)
  const next = useCallback(() => ++seqRef.current, [])
  const isCurrent = useCallback((seq: number) => seq === seqRef.current, [])
  return { next, isCurrent }
}
