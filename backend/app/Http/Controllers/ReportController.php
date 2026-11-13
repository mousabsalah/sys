<?php

namespace App\Http\Controllers;

use App\Models\Timetable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // نسبة استخدام القاعات (محاضرات لكل قاعة) — مع تصدير CSV
    public function utilization(Request $request)
    {
        $format = $request->query('format', 'json'); // json|csv

        // المعتمدة فقط — أما المعلّقة فلا تُحتسب في الاستخدام (المسودة قد تُرفض)
        $rows = Timetable::notArchived()->approved()
            ->join('halls', 'halls.id', '=', 'timetables.hall_id')
            ->whereNull('halls.deleted_at')
            ->toBase()
            ->select('halls.id', 'halls.name', 'halls.capacity', DB::raw('COUNT(timetables.id) AS lectures'))
            ->groupBy('halls.id', 'halls.name', 'halls.capacity')
            ->orderByDesc('lectures')
            ->get();

        $maxLectures = max(1, (int) $rows->max('lectures'));

        $report = $rows->map(fn ($row) => [
            'hall_id' => (int) $row->id,
            'hall' => (string) $row->name,
            'capacity' => (int) $row->capacity,
            'lectures' => (int) $row->lectures,
            'utilization_pct' => (int) round(($row->lectures / $maxLectures) * 100),
        ]);

        if ($format === 'csv') {
            $csv = "hall_id,hall,capacity,lectures,utilization_pct\n";
            foreach ($report as $r) {
                $csv .= implode(',', [$r['hall_id'], '"'.str_replace('"', '""', $r['hall']).'"', $r['capacity'], $r['lectures'], $r['utilization_pct']])."\n";
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="utilization-halls.csv"',
            ]);
        }

        return response()->json(['utilization' => $report]);
    }
}
