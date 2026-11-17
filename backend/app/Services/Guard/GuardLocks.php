<?php

namespace App\Services\Guard;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GuardLocks — مصدر واحد لمنطق «آخر إجراء حارس لكل قاعة».
 * آخر قفل يسري ما لم يُرفع بإجراء unlock لاحق (قارن بالـ id لا بالوقت).
 */
final class GuardLocks
{
    /**
     * @return Collection<int> معرفات القاعات المقفولة نشطًا (آخر إجراء = قفل)
     */
    public static function lockedHallIds(): Collection
    {
        return DB::table('guard_locks as g')
            ->whereIn('g.id', fn ($q) => $q->selectRaw('MAX(id)')->from('guard_locks')->groupBy('hall_id'))
            ->where('g.action', 'lock')
            ->pluck('g.hall_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }
}
