<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseFaculty;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultyEntity;
use App\Models\Group;
use App\Models\Hall;
use App\Models\Period;
use App\Models\Program;

/**
 * AdminController — لوحة المدير للبيانات الرئيسية (كليات/أقسام/دفعات/قاعات...)
 * v1 قراءة-فقط في البدء؛ CRUD لاحقًا.
 */
class AdminController extends Controller
{
    public function masterData()
    {
        return response()->json([
            'faculties' => FacultyEntity::all(['id', 'name', 'code', 'description']),
            'departments' => Department::all(['id', 'faculty_id', 'name', 'code']),
            'programs' => Program::all(['id', 'name', 'description']),
            'periods' => Period::orderBy('program_id')->orderBy('day_of_week')->orderBy('sort_order')
                ->get(['id', 'program_id', 'day_of_week', 'start_time', 'end_time', 'label', 'sort_order']),
            'batches' => Batch::with('department:id,name', 'program:id,name')
                ->get(['id', 'name', 'student_count', 'department_id', 'program_id']),
            'groups' => Group::with('batch:id,name')
                ->get(['id', 'batch_id', 'track', 'student_count']),
            'halls' => Hall::all(['id', 'name', 'capacity', 'status', 'hall_type', 'equipment']),
            'courses' => Course::all(['id', 'code', 'name', 'credit_hours', 'required_equipment']),
            'faculty_members' => Faculty::with('department:id,name')
                ->get(['id', 'full_name', 'department_id', 'email', 'phone']),
            'course_assignments' => CourseFaculty::with('course:id,code,name', 'faculty:id,full_name')
                ->get(['id', 'course_id', 'faculty_id']),
        ]);
    }
}
