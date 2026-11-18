<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultyEntity;
use App\Models\Group;
use App\Models\Hall;
use App\Models\Program;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CouncilScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'council'): User
    {
        return User::create([
            'name' => 'Test '.$role,
            'username' => 'sched_'.$role.'_'.uniqid(),
            'email' => uniqid().'@test.local',
            'password' => 'Password@123',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @return array{FacultyEntity, Department, Batch, Group} */
    private function makeChain(): array
    {
        $faculty = FacultyEntity::create(['name' => 'كلية التقنية', 'code' => 'TECH', 'description' => null]);
        $department = Department::create(['faculty_id' => $faculty->id, 'name' => 'علوم الحاسوب', 'code' => 'CS']);
        $program = Program::create(['name' => 'بكالوريوس تقنية المعلومات', 'description' => null]);
        $batch = Batch::create(['name' => 'دفعة 2026', 'student_count' => 60, 'department_id' => $department->id, 'program_id' => $program->id]);
        $group = Group::create(['batch_id' => $batch->id, 'track' => 'بكلاريوس', 'student_count' => 60]);

        return [$faculty, $department, $batch, $group];
    }

    private function seedRows(int $groupId): array
    {
        $hall = Hall::create(['name' => 'قاعة الجدول', 'capacity' => 60, 'hall_type' => 'قاعة', 'status' => 'متاحة', 'equipment' => null]);
        $course = Course::create(['code' => 'CS-SHED', 'name' => 'مقرر الجداول', 'credit_hours' => 3]);
        $lecturer = Faculty::create(['full_name' => 'د. جدول', 'department_id' => 1, 'email' => uniqid().'@shed.local']);

        $approved = Timetable::create([
            'course_id' => $course->id, 'faculty_id' => $lecturer->id, 'group_id' => $groupId, 'hall_id' => $hall->id,
            'day_of_week' => 'الأحد', 'start_time' => '07:00:00', 'end_time' => '09:00:00',
            'academic_term' => '2026/1', 'semester_id' => null, 'status' => 'approved',
        ]);
        $pending = Timetable::create([
            'course_id' => $course->id, 'faculty_id' => $lecturer->id, 'group_id' => $groupId, 'hall_id' => $hall->id,
            'day_of_week' => 'الثلاثاء', 'start_time' => '10:00:00', 'end_time' => '12:00:00',
            'academic_term' => '2026/1', 'semester_id' => null, 'status' => 'pending',
        ]);
        $otherTerm = Timetable::create([
            'course_id' => $course->id, 'faculty_id' => $lecturer->id, 'group_id' => $groupId, 'hall_id' => $hall->id,
            'day_of_week' => 'الخميس', 'start_time' => '07:00:00', 'end_time' => '09:00:00',
            'academic_term' => '2026/2', 'semester_id' => null, 'status' => 'approved',
        ]);

        return [$approved, $pending, $otherTerm];
    }

    public function test_index_lists_groups_with_specialization_and_paginates_counts(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        [$approved, $pending] = $this->seedRows($group->id);
        $council = $this->makeUser('council');

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->getJson('/api/council/schedules?term=2026/1')
            ->assertOk();

        $groups = $response->json('groups');
        $this->assertCount(1, $groups);
        $mine = collect($groups)->firstWhere('id', $group->id);
        $this->assertSame('دفعة 2026', $mine['batch']);
        $this->assertSame('بكالوريوس تقنية المعلومات', $mine['program']);
        $this->assertSame('علوم الحاسوب', $mine['department']);
        $this->assertSame(2, $mine['counts']['total']);
        $this->assertSame(1, $mine['counts']['approved']);
        $this->assertSame(1, $mine['counts']['pending']);

        $rows = $response->json('rows');
        $this->assertCount(2, $rows);
    }

    public function test_index_forbidden_for_admin(): void
    {
        $admin = $this->makeUser('admin');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($admin, 'sanctum')
            ->getJson('/api/council/schedules')
            ->assertStatus(403);
    }

    public function test_archive_then_unarchive_roundtrip(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        [$approved, $pending, $otherTerm] = $this->seedRows($group->id);
        $council = $this->makeUser('council');

        // أرشف كل جدول الشعبة في 2026/1
        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->postJson('/api/council/schedules/archive', ['group_id' => $group->id, 'academic_term' => '2026/1'])
            ->assertOk()
            ->assertJsonPath('archived', 2);

        $this->assertSame('archived', (string) $approved->fresh()->status);
        $this->assertSame('archived', (string) $pending->fresh()->status);
        // الفصل الآخر لم يتأثر
        $this->assertSame('approved', (string) $otherTerm->fresh()->status);

        // إلغاء الأرشفة — تعود pending (وتحتاج اعتماد أدمن من جديد)
        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->postJson('/api/council/schedules/unarchive', ['group_id' => $group->id, 'academic_term' => '2026/1'])
            ->assertOk()
            ->assertJsonPath('restored', 2);

        $this->assertSame('pending', (string) $approved->fresh()->status);
        $this->assertSame('pending', (string) $pending->fresh()->status);
    }

    public function test_delete_schedule_removes_only_group_and_term(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $batch2 = Batch::create(['name' => 'دفعة 2025', 'student_count' => 30, 'department_id' => $department->id, 'program_id' => $batch->program_id]);
        $group2 = Group::create(['batch_id' => $batch2->id, 'track' => 'دبلوم', 'student_count' => 30]);
        [$approved, $pending, $otherTerm] = $this->seedRows($group->id);
        $otherGroupRow = Timetable::create([
            'course_id' => $approved->course_id, 'faculty_id' => $approved->faculty_id, 'group_id' => $group2->id,
            'hall_id' => $approved->hall_id, 'day_of_week' => 'الاثنين', 'start_time' => '07:00:00',
            'end_time' => '09:00:00', 'academic_term' => '2026/1', 'semester_id' => null, 'status' => 'approved',
        ]);
        $council = $this->makeUser('council');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->deleteJson('/api/council/schedules/'.$group->id.'?academic_term=2026/1')
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertSame(0, Timetable::where('group_id', $group->id)->where('academic_term', '2026/1')->count());
        $this->assertSame(1, Timetable::where('group_id', $group->id)->where('academic_term', '2026/2')->count());
        $this->assertSame(1, Timetable::where('group_id', $group2->id)->count());
    }

    public function test_delete_schedule_refused_by_substitute_requests(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        [$approved] = $this->seedRows($group->id);
        $lecturer = Faculty::create(['full_name' => 'د. بديل', 'department_id' => $department->id, 'email' => uniqid().'@sub.local']);
        DB::table('substitute_requests')->insert([
            'timetable_id' => $approved->id,
            'requested_by_lecturer_id' => $approved->faculty_id,
            'substitute_lecturer_id' => $lecturer->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $council = $this->makeUser('council');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->deleteJson('/api/council/schedules/'.$group->id.'?academic_term=2026/1')
            ->assertStatus(409)
            ->assertJsonPath('substitute_requests', 1);

        $this->assertSame(2, Timetable::where('group_id', $group->id)->where('academic_term', '2026/1')->count());
    }
}
