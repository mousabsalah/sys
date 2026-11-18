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

class AdminApprovalsBulkTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'Test '.$role,
            'username' => 'bulk_'.$role.'_'.uniqid(),
            'email' => uniqid().'@test.local',
            'password' => 'Password@123',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeChain(): array
    {
        $faculty = FacultyEntity::create(['name' => 'كلية التقنية', 'code' => 'TECH', 'description' => null]);
        $department = Department::create(['faculty_id' => $faculty->id, 'name' => 'علوم الحاسوب', 'code' => 'CS']);
        $program = Program::create(['name' => 'بكالوريوس تقنية المعلومات', 'description' => null]);
        $batch = Batch::create(['name' => 'دفعة 2026', 'student_count' => 40, 'department_id' => $department->id, 'program_id' => $program->id]);
        $group = Group::create(['batch_id' => $batch->id, 'track' => 'بكلاريوس', 'student_count' => 40]);

        return [$faculty, $department, $batch, $group];
    }

    private function seedRow(int $groupId, string $status, string $day = 'الأحد'): Timetable
    {
        $hall = Hall::firstOrCreate(['name' => 'قاعة الاعتماد', 'capacity' => 50, 'hall_type' => 'قاعة', 'status' => 'متاحة', 'equipment' => null]);
        $course = Course::firstOrCreate(['code' => 'CS-BULK', 'name' => 'مقرر الاعتماد', 'credit_hours' => 3]);
        $lecturer = Faculty::firstOrCreate(['full_name' => 'د. اعتماد', 'email' => 'bulk@lecturer.local'], ['department_id' => 1]);

        return Timetable::create([
            'course_id' => $course->id, 'faculty_id' => $lecturer->id, 'group_id' => $groupId, 'hall_id' => $hall->id,
            'day_of_week' => $day, 'start_time' => '07:00:00', 'end_time' => '09:00:00',
            'academic_term' => '2026/1', 'semester_id' => null, 'status' => $status,
        ]);
    }

    public function test_approve_all_approves_every_pending_lecture_in_one_call(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $admin = $this->makeUser('admin');

        $pending1 = $this->seedRow($group->id, 'pending', 'الأحد');
        $pending2 = $this->seedRow($group->id, 'pending', 'الثلاثاء');
        $pending3 = $this->seedRow($group->id, 'pending', 'الخميس');
        $alreadyApproved = $this->seedRow($group->id, 'approved', 'السبت');
        $cancelled = $this->seedRow($group->id, 'cancelled', 'الاثنين');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/approvals/approve-all', [])
            ->assertOk()
            ->assertJsonPath('approved', 3);

        $this->assertSame('approved', (string) $pending1->fresh()->status);
        $this->assertSame('approved', (string) $pending2->fresh()->status);
        $this->assertSame('approved', (string) $pending3->fresh()->status);
        // غير المعلّق لا يتغير
        $this->assertSame('approved', (string) $alreadyApproved->fresh()->status);
        $this->assertSame('cancelled', (string) $cancelled->fresh()->status);
        // يوجد سجل تدقيق للعملية الجماعية
        $this->assertTrue(DB::table('audit_logs')->where('action', 'timetables.approve_all')->exists());
    }

    public function test_approve_all_is_noop_when_nothing_pending(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $admin = $this->makeUser('admin');
        $this->seedRow($group->id, 'approved', 'الأحد');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/approvals/approve-all', [])
            ->assertOk()
            ->assertJsonPath('approved', 0);
    }

    public function test_approve_all_is_admin_only(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeChain();
        $council = $this->makeUser('council');

        $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($council, 'sanctum')
            ->postJson('/api/admin/approvals/approve-all', [])
            ->assertStatus(403);
    }
}
