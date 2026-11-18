<?php

namespace App\Services\Academic;

use Illuminate\Support\Facades\DB;

/**
 * SpecializationGroups — خريطة الشعب حسب التخصص للشاشات «الجداول الدراسية» و«جدول الامتحانات».
 * تخصص الشعبة = القسم (departments.name) ثم الدفعة (batches.name) ثم النوع (track) + البرنامج (programs.name).
 */
final class SpecializationGroups
{
    /**
     * @return list<array{id:int, batch:string, track:string, student_count:int, program:string, department:string, department_id:?int}>
     */
    public static function list(): array
    {
        $groups = DB::table('groups')
            ->join('batches', 'batches.id', '=', 'groups.batch_id')
            ->leftJoin('programs', 'programs.id', '=', 'batches.program_id')
            ->leftJoin('departments', 'departments.id', '=', 'batches.department_id')
            ->whereNull('batches.deleted_at')
            ->orderBy('departments.name')
            ->orderBy('batches.name')
            ->orderBy('groups.track')
            ->orderBy('groups.id')
            ->select(
                'groups.id',
                'groups.track',
                'groups.student_count',
                'batches.name AS batch_name',
                'programs.name AS program_name',
                'departments.name AS department_name',
                'departments.id AS department_id',
            )
            ->get();

        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'id' => (int) $g->id,
                'batch' => (string) $g->batch_name,
                'track' => (string) $g->track,
                'student_count' => (int) $g->student_count,
                'program' => (string) ($g->program_name ?? ''),
                'department' => (string) ($g->department_name ?? ''),
                'department_id' => $g->department_id !== null ? (int) $g->department_id : null,
            ];
        }

        return $out;
    }
}
