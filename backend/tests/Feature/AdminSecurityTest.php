<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\Course;
use App\Models\Department;
use App\Models\ExamSession;
use App\Models\Faculty;
use App\Models\FacultyEntity;
use App\Models\Group;
use App\Models\GroupCourse;
use App\Models\Hall;
use App\Models\Period;
use App\Models\Program;
use App\Models\Semester;
use App\Models\Timetable;
use App\Models\User;
use App\Services\Backup\BackupGateway;
use App\Services\Engine\ConflictChecker;
use App\Services\Engine\EngineClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AdminSecurityTest — يثبت جروح الأدمن: الصلاحيات، تعطيل الحسابات،
 * حذف التوكنات، رفض الحذف المرتبط بـ 422، واحترام الأرشفة في إحصاءات اللوحة.
 */
class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'admin', bool $active = true): User
    {
        return User::create([
            'name' => 'Test '.$role,
            'username' => 'test_'.$role.'_'.uniqid(),
            'email' => uniqid().'@test.local',
            'password' => 'Password@123',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    private function makeAcademicChain(): array
    {
        $faculty = FacultyEntity::create(['name' => 'كلية العلوم', 'code' => 'SCI', 'description' => null]);
        $department = Department::create(['faculty_id' => $faculty->id, 'name' => 'علوم الحاسوب', 'code' => 'CS']);
        $program = Program::create(['name' => 'بكالوريوس', 'description' => null]);
        $batch = Batch::create(['name' => 'دفعة 2026', 'student_count' => 40, 'department_id' => $department->id, 'program_id' => $program->id]);
        $group = Group::create(['batch_id' => $batch->id, 'department_id' => $department->id, 'track' => 'بكلاريوس', 'student_count' => 40]);

        return [$faculty, $department, $batch, $group];
    }

    private function makeHall(string $name = 'قاعة اختبار'): Hall
    {
        return Hall::create([
            'name' => $name,
            'capacity' => 50,
            'hall_type' => 'قاعة',
            'status' => 'متاحة',
            'equipment' => null,
        ]);
    }

    private function makeCourseAndLecturer(int $departmentId): array
    {
        $course = Course::create([
            'code' => 'CS101',
            'name' => 'مقدمة علوم الحاسوب',
            'credit_hours' => 3,
        ]);
        $lecturer = Faculty::create([
            'full_name' => 'د. أحمد',
            'department_id' => $departmentId,
            'email' => uniqid().'@lecturer.local',
        ]);

        return [$course, $lecturer];
    }

    private function makeTimetable(int $hallId, int $groupId, int $semesterId, int $courseId = 1, int $facultyId = 1): Timetable
    {
        return Timetable::create([
            'course_id' => $courseId,
            'faculty_id' => $facultyId,
            'group_id' => $groupId,
            'hall_id' => $hallId,
            'day_of_week' => 'السبت',
            'start_time' => '08:00:00',
            'end_time' => '09:30:00',
            'academic_term' => '2026/1',
            'semester_id' => $semesterId,
        ]);
    }

    private function authedAs(User $user)
    {
        return $this->withHeaders(['Accept' => 'application/json'])->actingAs($user, 'sanctum');
    }

    // ── المصادقة والتعطيل ────────────────────────────────────────────

    public function test_deactivated_user_is_rejected_by_middleware_even_with_token(): void
    {
        $user = $this->makeUser('guard', true);
        // إيقاف من القاعدة مباشرة (حالة توكنات سابقة للإصلاح)
        $user->forceFill(['is_active' => false])->save();

        $this->authedAs($user)
            ->getJson('/api/dashboard')
            ->assertStatus(403);
    }

    public function test_login_is_rejected_for_deactivated_user(): void
    {
        $user = $this->makeUser('guard', false);

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Password@123'])
            ->assertStatus(422);
    }

    public function test_deactivate_revokes_all_target_tokens(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('guard');

        $login = $this->postJson('/api/login', ['username' => $target->username, 'password' => 'Password@123'])->json();
        $token = $login['token'] ?? null;
        $this->assertNotNull($token);
        $this->assertGreaterThan(0, $target->tokens()->count());

        $adminToken = $this->postJson('/api/login', ['username' => $admin->username, 'password' => 'Password@123'])->json('token');
        $this->withHeaders(['Authorization' => 'Bearer '.$adminToken, 'Accept' => 'application/json'])
            ->postJson('/api/admin/users/'.$target->id.'/deactivate')
            ->assertOk();

        $this->assertSame(0, $target->fresh()->tokens()->count());
        Auth::forgetUser();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/dashboard')
            ->assertStatus(401);
    }

    public function test_reset_password_revokes_all_target_tokens(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('guard');

        $this->postJson('/api/login', ['username' => $target->username, 'password' => 'Password@123']);
        $this->assertGreaterThan(0, $target->tokens()->count());

        $adminToken = $this->postJson('/api/login', ['username' => $admin->username, 'password' => 'Password@123'])->json('token');
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$adminToken, 'Accept' => 'application/json'])
            ->postJson('/api/admin/users/'.$target->id.'/reset-password')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('temporary_password', $response);
        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    public function test_admin_cannot_create_timetables_but_dept_can(): void
    {
        [$faculty, $department, $batch, $group] = $this->makeAcademicChain();
        [$course, $lecturer] = $this->makeCourseAndLecturer($department->id);
        $admin = $this->makeUser('admin');
        $deptUser = $this->makeUser('dept');
        $deptUser->forceFill(['faculty_id' => $faculty->id])->save();
        $hall = $this->makeHall();

        $payload = [
            'course_id' => $course->id,
            'faculty_id' => $lecturer->id,
            'group_id' => $group->id,
            'hall_id' => $hall->id,
            'day_of_week' => 'السبت',
            'start_time' => '08:00:00',
            'end_time' => '09:30:00',
            'academic_term' => '2026/1',
        ];

        $this->authedAs($admin)
            ->postJson('/api/timetables', $payload)
            ->assertStatus(403);

        // dept يمرر — نفكّك المحرك بالمحاكاة (لا نريد HTTP حقيقي إلى syst-engine)
        $this->mock(ConflictChecker::class, function ($mock) {
            $mock->shouldReceive('commitTimetable')->andReturn(['ok' => true, 'timetable' => null]);
        });

        $this->authedAs($deptUser)
            ->postJson('/api/timetables', $payload)
            ->assertStatus(201);
    }

    public function test_non_admin_cannot_reach_admin_routes(): void
    {
        $deptUser = $this->makeUser('dept');

        $this->authedAs($deptUser)
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    // ── رفض الحذف المرتبط ────────────────────────────────────────────

    public function test_faculty_delete_blocked_by_linked_department(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty] = $this->makeAcademicChain();

        $this->authedAs($admin)
            ->deleteJson('/api/admin/faculties/'.$faculty->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('faculties', ['id' => $faculty->id]);
    }

    public function test_hall_delete_blocked_by_linked_timetable(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch, $group] = $this->makeAcademicChain();
        [$course, $lecturer] = $this->makeCourseAndLecturer($department->id);
        $semester = Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/1',
        ]);
        $hall = $this->makeHall();
        $this->makeTimetable($hall->id, $group->id, $semester->id, $course->id, $lecturer->id);

        $this->authedAs($admin)
            ->deleteJson('/api/admin/halls/'.$hall->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('halls', ['id' => $hall->id]);
    }

    public function test_semester_delete_blocked_by_linked_timetable(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch, $group] = $this->makeAcademicChain();
        [$course, $lecturer] = $this->makeCourseAndLecturer($department->id);
        $semester = Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/1',
        ]);
        $hall = $this->makeHall();
        $this->makeTimetable($hall->id, $group->id, $semester->id, $course->id, $lecturer->id);

        $this->authedAs($admin)
            ->deleteJson('/api/admin/semesters/'.$semester->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('semesters', ['id' => $semester->id]);
    }

    public function test_semester_create_rejects_broken_academic_chain(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch] = $this->makeAcademicChain();
        $otherFaculty = FacultyEntity::create(['name' => 'كلية الهندسة', 'code' => 'ENG', 'description' => null]);

        // قسم لا ينتمي للكلية المختارة
        $this->authedAs($admin)
            ->postJson('/api/admin/semesters', [
                'faculty_id' => $otherFaculty->id,
                'department_id' => $department->id,
                'batch_id' => $batch->id,
                'academic_term' => '2026/2',
            ])
            ->assertStatus(422);
    }

    // ── احترام الأرشفة في إحصاءات لوحة الأدمن ───────────────────────

    public function test_dashboard_stats_excludes_archived_semester_data(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch, $group] = $this->makeAcademicChain();
        [$course, $lecturer] = $this->makeCourseAndLecturer($department->id);
        $hall = $this->makeHall();

        $semester = Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/1',
        ]);
        $this->makeTimetable($hall->id, $group->id, $semester->id, $course->id, $lecturer->id);

        // قبل الأرشفة: الإحصائيات تحسب المحاضرة
        $before = $this->actingAs($admin)->getJson('/api/admin/dashboard-stats')->json();
        $this->assertSame(1, $before['kpis']['lectures_total']);

        $semester->forceFill(['archived_at' => now()])->save();

        // بعد الأرشفة: الإحصائيات لا تحسبها
        $after = $this->actingAs($admin)->getJson('/api/admin/dashboard-stats')->json();
        $this->assertSame(0, $after['kpis']['lectures_total']);
        $this->assertSame(0, $after['halls'][0]['lectures']);
    }

    // ═══ اختبارات المسارات الدقيقة من جولة الإصلاح (items N1-N8) ═══

    public function test_ensure_active_blocks_non_dashboard_route_too(): void
    {
        $user = $this->makeUser('guard', true);
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertStatus(403);
    }

    public function test_reactivate_restores_access(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('guard', false);

        $this->actingAs($admin)
            ->postJson('/api/admin/users/'.$target->id.'/reactivate')
            ->assertOk();

        // بعد التفعيل يجب قراءة نسخة حديثة من القاعدة — وإلا يحتفظ الكائن بقيمة is_active=false القديمة
        $this->actingAs($target->fresh())
            ->getJson('/api/dashboard')
            ->assertOk();
    }

    public function test_semester_update_duplicate_term_rejected_but_same_term_allowed(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch] = $this->makeAcademicChain();

        $semesterA = Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/1',
        ]);
        Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/2',
        ]);

        $this->actingAs($admin)
            ->putJson('/api/admin/semesters/'.$semesterA->id, ['academic_term' => '2026/2'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson('/api/admin/semesters/'.$semesterA->id, ['academic_term' => '2026/1'])
            ->assertOk();
    }

    public function test_department_faculty_move_cascades_semester_faculty(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch] = $this->makeAcademicChain();
        $otherFaculty = FacultyEntity::create(['name' => 'كلية الطب', 'code' => 'MED', 'description' => null]);

        $semester = Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/1',
        ]);

        $this->actingAs($admin)
            ->putJson('/api/admin/departments/'.$department->id, ['faculty_id' => $otherFaculty->id])
            ->assertOk();

        $this->assertSame($otherFaculty->id, (int) $semester->fresh()->faculty_id);
    }

    public function test_hall_delete_blocked_by_linked_booking_and_exam(): void
    {
        $admin = $this->makeUser('admin');
        [, $department, $batch, $group] = $this->makeAcademicChain();
        [$course] = $this->makeCourseAndLecturer($department->id);
        $hall = $this->makeHall();

        Booking::create([
            'hall_id' => $hall->id,
            'date' => '2026-12-01',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'user_id' => $admin->id,
            'status' => 'confirmed',
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/halls/'.$hall->id)
            ->assertStatus(422);

        DB::table('bookings')->where('hall_id', $hall->id)->delete();

        ExamSession::create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'hall_id' => $hall->id,
            'exam_date' => '2026-12-01',
            'start_time' => '08:00:00',
            'end_time' => '09:30:00',
            'exam_type' => 'نهائي',
            'academic_term' => '2026/1',
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/halls/'.$hall->id)
            ->assertStatus(422);
    }

    public function test_semester_delete_blocked_by_linked_booking_and_exam(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch, $group] = $this->makeAcademicChain();
        [$course] = $this->makeCourseAndLecturer($department->id);
        $semester = Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/1',
        ]);

        Booking::create([
            'hall_id' => $this->makeHall('قاعة الحجوزات')->id,
            'date' => '2026-12-01',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'user_id' => $admin->id,
            'status' => 'confirmed',
            'semester_id' => $semester->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/semesters/'.$semester->id)
            ->assertStatus(422);

        DB::table('bookings')->where('semester_id', $semester->id)->delete();

        ExamSession::create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'hall_id' => $this->makeHall('قاعة الامتحانات')->id,
            'exam_date' => '2026-12-01',
            'start_time' => '08:00:00',
            'end_time' => '09:30:00',
            'exam_type' => 'نهائي',
            'academic_term' => '2026/1',
            'semester_id' => $semester->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/semesters/'.$semester->id)
            ->assertStatus(422);
    }

    public function test_department_create_rejects_duplicate_name_in_same_faculty(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty] = $this->makeAcademicChain();

        $this->actingAs($admin)
            ->postJson('/api/admin/departments', [
                'faculty_id' => $faculty->id,
                'name' => 'علوم الحاسوب',
                'code' => 'CS-DUP',
            ])
            ->assertStatus(422);
    }

    public function test_archived_semester_is_frozen_for_update_dates_and_delete(): void
    {
        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch] = $this->makeAcademicChain();
        $semester = Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/1',
        ]);
        $semester->forceFill(['archived_at' => now()])->save();

        $this->actingAs($admin)
            ->putJson('/api/admin/semesters/'.$semester->id, ['academic_term' => '2026/9'])
            ->assertStatus(422);
        $this->actingAs($admin)
            ->postJson('/api/admin/semesters/'.$semester->id.'/dates', [
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-30',
            ])
            ->assertStatus(422);
        $this->actingAs($admin)
            ->deleteJson('/api/admin/semesters/'.$semester->id)
            ->assertStatus(422);
    }

    public function test_archive_logs_backup_failure_and_returns_null_backup(): void
    {
        $this->app->instance(BackupGateway::class, new class implements BackupGateway
        {
            public function dump(): ?string
            {
                return null; // فشل mysqldump
            }
        });

        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch] = $this->makeAcademicChain();
        $semester = Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/1',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/semesters/'.$semester->id.'/archive')
            ->assertOk()
            ->json();

        $this->assertNull($response['backup']);
        $this->assertNotNull($semester->fresh()->archived_at);
        $this->assertTrue(DB::table('audit_logs')
            ->where('action', 'backup.auto_failed')
            ->where('target_type', 'semester')
            ->where('target_id', (string) $semester->id)
            ->exists());
    }

    public function test_archive_success_attributes_exact_backup_file(): void
    {
        $this->app->instance(BackupGateway::class, new class implements BackupGateway
        {
            public function dump(): ?string
            {
                return 'aust_graduation_2099-01-01_000000.sql';
            }
        });

        $admin = $this->makeUser('admin');
        [$faculty, $department, $batch] = $this->makeAcademicChain();
        $semester = Semester::create([
            'faculty_id' => $faculty->id,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'academic_term' => '2026/1',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/semesters/'.$semester->id.'/archive')
            ->assertOk()
            ->json();

        $this->assertSame('aust_graduation_2099-01-01_000000.sql', $response['backup']);
    }

    // ═══ تغطية خطة الشعبة + النسخ الاحتياطي ═══

    private function makeCourse(string $code): Course
    {
        return Course::create(['code' => $code, 'name' => 'مقرر '.$code, 'credit_hours' => 3]);
    }

    public function test_plans_store_replaces_plan_and_logs_audit(): void
    {
        $admin = $this->makeUser('admin');
        [, , , $group] = $this->makeAcademicChain();
        $courseA = $this->makeCourse('T100');
        $courseB = $this->makeCourse('T200');

        $this->actingAs($admin)
            ->postJson('/api/admin/plans/'.$group->id, [
                'items' => [
                    ['course_id' => $courseA->id, 'sessions_per_week' => 4],
                    ['course_id' => $courseB->id, 'sessions_per_week' => 3],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('group_courses', [
            'group_id' => $group->id,
            'course_id' => $courseA->id,
            'sessions_per_week' => 4,
        ]);
        $this->assertDatabaseHas('group_courses', [
            'group_id' => $group->id,
            'course_id' => $courseB->id,
            'sessions_per_week' => 3,
        ]);
        $this->assertTrue(DB::table('audit_logs')
            ->where('action', 'plan.replace')
            ->where('target_id', (string) $group->id)
            ->exists());
    }

    public function test_plans_store_rejects_duplicate_course(): void
    {
        $admin = $this->makeUser('admin');
        [, , , $group] = $this->makeAcademicChain();
        $course = $this->makeCourse('T100');

        $this->actingAs($admin)
            ->postJson('/api/admin/plans/'.$group->id, [
                'items' => [
                    ['course_id' => $course->id, 'sessions_per_week' => 4],
                    ['course_id' => $course->id, 'sessions_per_week' => 3],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('group_courses', ['group_id' => $group->id]);
    }

    public function test_backup_create_uses_dumper_and_returns_file(): void
    {
        $this->app->instance(BackupGateway::class, new class implements BackupGateway
        {
            public function dump(): ?string
            {
                return 'aust_graduation_2099-01-01_000000.sql';
            }
        });

        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/backup/create')
            ->assertOk()
            ->json();

        $this->assertTrue($response['ok']);
        $this->assertSame('aust_graduation_2099-01-01_000000.sql', $response['file']);
    }

    public function test_backup_create_returns_generic_error_on_dumper_failure(): void
    {
        $this->app->instance(BackupGateway::class, new class implements BackupGateway
        {
            public function dump(): ?string
            {
                return null;
            }
        });

        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->postJson('/api/admin/backup/create');

        $this->assertSame(500, $response->status());
        $this->assertFalse($response->json('ok'));
    }

    public function test_backup_download_rejects_invalid_filename(): void
    {
        $admin = $this->makeUser('admin');

        // اسم لا يطابق النمط الرسمي — يجب 422 من داخل المتحكم
        $this->actingAs($admin)
            ->getJson('/api/admin/backup/download/arbitrary-backup.sql')
            ->assertStatus(422);
    }

    public function test_backup_download_serves_existing_backup(): void
    {
        $admin = $this->makeUser('admin');
        $file = 'aust_graduation_2099-01-01_000000.sql';
        File::ensureDirectoryExists(storage_path('backups'));
        File::put(storage_path('backups/'.$file), '-- dump');

        try {
            $this->actingAs($admin)
                ->get('/api/admin/backup/download/'.$file)
                ->assertOk();
        } finally {
            File::delete(storage_path('backups/'.$file));
        }
    }

    // ═══ تغطية إصلاحات الجولة الرابعة ═══

    public function test_propose_requires_group_or_groups(): void
    {
        $deptUser = $this->makeUser('dept');
        [$faculty] = $this->makeAcademicChain();
        $deptUser->forceFill(['faculty_id' => $faculty->id])->save();

        $this->actingAs($deptUser)
            ->postJson('/api/admin/propose-timetable', [])
            ->assertStatus(422);
    }

    public function test_backup_download_writes_audit_row(): void
    {
        $admin = $this->makeUser('admin');
        $file = 'aust_graduation_2099-01-01_000000.sql';
        File::ensureDirectoryExists(storage_path('backups'));
        File::put(storage_path('backups/'.$file), '-- dump');

        try {
            $this->actingAs($admin)
                ->get('/api/admin/backup/download/'.$file)
                ->assertOk();

            $this->assertTrue(DB::table('audit_logs')
                ->where('action', 'backup.download')
                ->where('target_type', 'backup')
                ->exists());
        } finally {
            File::delete(storage_path('backups/'.$file));
        }
    }

    public function test_plans_store_rejects_empty_items(): void
    {
        $admin = $this->makeUser('admin');
        [, , , $group] = $this->makeAcademicChain();

        $this->actingAs($admin)
            ->postJson('/api/admin/plans/'.$group->id, ['items' => []])
            ->assertStatus(422);
    }

    public function test_backup_bucket_does_not_consume_login_budget(): void
    {
        $this->app->instance(BackupGateway::class, new class implements BackupGateway
        {
            public function dump(): ?string
            {
                return 'aust_graduation_2099-01-01_000000.sql';
            }
        });

        $admin = $this->makeUser('admin');
        $adminToken = $this->postJson('/api/login', ['username' => $admin->username, 'password' => 'Password@123'])->json('token');

        // 6 استدعاءات backup/create مسموحة — السابع يُرفض بـ429
        $headers = ['Authorization' => 'Bearer '.$adminToken, 'Accept' => 'application/json'];
        for ($i = 0; $i < 6; $i++) {
            $this->withHeaders($headers)->postJson('/api/admin/backup/create')->assertOk();
        }
        $this->withHeaders($headers)->postJson('/api/admin/backup/create')->assertStatus(429);

        // لكن الدخول بنفس الحساب يبقى يعمل — bucket الدخول منفصل
        $this->postJson('/api/login', ['username' => $admin->username, 'password' => 'Password@123'])
            ->assertOk();
    }

    // ═══ عقد المحرك — EngineClient يربط HTTP 503 بـ ok:false (لا يرمي) ═══

    public function test_engine_client_maps_503_to_ok_false_not_throw(): void
    {
        config(['services.syst_engine.url' => 'http://fake-engine.local']);

        Http::fake(['fake-engine.local/propose' => Http::response(['error' => 'محلل CP-SAT فشل في الترميز'], 503)]);

        $result = (new EngineClient)->propose(['group' => ['id' => 1]]);

        $this->assertFalse($result['ok']);
        $this->assertSame('محلل CP-SAT فشل في الترميز', $result['error']);
    }

    public function test_engine_client_maps_non_503_bad_status_to_exception(): void
    {
        config(['services.syst_engine.url' => 'http://fake-engine.local']);

        Http::fake(['fake-engine.local/propose' => Http::response([], 500)]);

        $this->expectException(\RuntimeException::class);

        (new EngineClient)->propose(['group' => ['id' => 1]]);
    }

    // ═══ سلوك NO_*: كل الشعب تفشل للأسباب نفسها → 200 ok:true (توثيقي) ═══

    public function test_propose_all_skip_still_returns_200_ok_true(): void
    {
        $deptUser = $this->makeUser('dept');
        $faculty = FacultyEntity::create(['name' => 'كلية بدون فترات', 'code' => 'NOP', 'description' => null]);
        $dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'قسم بدون فترات', 'code' => 'NOP-D']);
        // برنامج بلا أية Period مسجلة — propose يجب أن يرجع NO_PERIODS دون استدعاء المحرك
        $program = Program::create(['name' => 'بكالريوس فارغ', 'description' => null]);
        $batch = Batch::create(['name' => 'دفعة بلا فترات', 'student_count' => 60, 'department_id' => $dept->id, 'program_id' => $program->id]);
        $group = Group::create(['batch_id' => $batch->id, 'department_id' => $dept->id, 'track' => 'بكلاريوس', 'student_count' => 30]);

        $deptUser->forceFill(['faculty_id' => $faculty->id])->save();

        $response = $this->actingAs($deptUser)
            ->postJson('/api/admin/propose-timetable', ['group_ids' => [$group->id]]);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('placed', 0);
        $this->assertSame('NO_PERIODS', collect($response->json('groups'))->first()['status']);
    }

    public function test_login_throttle_locks_out_after_repeated_failures(): void
    {
        $user = $this->makeUser('guard');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['username' => $user->username, 'password' => 'wrong-pass']);
        }

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Password@123'])->assertStatus(429);
    }

    // ═══ عزل المحرك: شعبة تفشل لا تُسقط الدُفعة ═══

    private function makeTwoGroupChain(): array
    {
        $faculty = FacultyEntity::create(['name' => 'كلية الاختبار', 'code' => 'TST', 'description' => null]);
        $department = Department::create(['faculty_id' => $faculty->id, 'name' => 'قسم الاختبار', 'code' => 'TST-D']);
        $program = Program::create(['name' => 'بكالوريوس', 'description' => null]);
        $batch = Batch::create(['name' => 'دفعة اختبار', 'student_count' => 60, 'department_id' => $department->id, 'program_id' => $program->id]);
        $groupA = Group::create(['batch_id' => $batch->id, 'department_id' => $department->id, 'track' => 'دبلوم', 'student_count' => 30]);
        $groupB = Group::create(['batch_id' => $batch->id, 'department_id' => $department->id, 'track' => 'بكلاريوس', 'student_count' => 30]);

        Period::create([
            'program_id' => $program->id,
            'day_of_week' => 'السبت',
            'start_time' => '08:00:00',
            'end_time' => '09:30:00',
            'label' => 'الفترة الأولى',
            'sort_order' => 1,
        ]);

        $course = Course::create(['code' => 'TT100', 'name' => 'مقرر اختباري', 'credit_hours' => 3]);
        GroupCourse::create(['group_id' => $groupA->id, 'course_id' => $course->id, 'sessions_per_week' => 1]);
        GroupCourse::create(['group_id' => $groupB->id, 'course_id' => $course->id, 'sessions_per_week' => 1]);
        $hall = $this->makeHall('قاعة عزل المحرك');
        [$course2, $lecturer] = $this->makeCourseAndLecturer($department->id);

        return [$faculty, $department, $groupA, $groupB, $course, $hall, $lecturer];
    }

    public function test_engine_ok_false_for_all_groups_returns_503(): void
    {
        [$faculty, $department, $groupA, $groupB] = $this->makeTwoGroupChain();
        $deptUser = $this->makeUser('dept');
        $deptUser->forceFill(['faculty_id' => $faculty->id])->save();

        $this->mock(EngineClient::class, function ($mock) {
            // CP-SAT فشل عبر HTTP 503 — المحرك لا يرمي، بل يرجع ok:false
            $mock->shouldReceive('propose')->andReturn(['ok' => false, 'error' => 'محلل CP-SAT فشل في الترميز']);
        });

        $response = $this->actingAs($deptUser)
            ->postJson('/api/admin/propose-timetable', ['group_ids' => [$groupA->id, $groupB->id]])
            ->assertStatus(503);

        $this->assertFalse($response->json('ok'));
        $this->assertNotEmpty($response->json('message_ar'));
    }

    public function test_engine_ok_false_for_one_group_others_survive(): void
    {
        [$faculty, $department, $groupA, $groupB, $course, $hall, $lecturer] = $this->makeTwoGroupChain();
        $deptUser = $this->makeUser('dept');
        $deptUser->forceFill(['faculty_id' => $faculty->id])->save();

        $this->mock(EngineClient::class, function ($mock) use ($groupA, $course, $hall, $lecturer) {
            $mock->shouldReceive('propose')->andReturnUsing(function (array $payload) use ($groupA, $course, $hall, $lecturer) {
                if ((int) $payload['group']['id'] === $groupA->id) {
                    return ['ok' => false, 'error' => 'محلل CP-SAT فشل في الترميز'];
                }

                return [
                    'ok' => true,
                    'status' => 'OPTIMAL',
                    'placed' => 1,
                    'assignments' => [
                        [
                            'course_id' => $course->id,
                            'faculty_id' => $lecturer->id,
                            'day' => 'السبت',
                            'start_time' => '08:00:00',
                            'end_time' => '09:30:00',
                            'hall_id' => $hall->id,
                            'course_code' => $course->code,
                        ],
                    ],
                    'unplaced' => [],
                ];
            });
        });

        $response = $this->actingAs($deptUser)
            ->postJson('/api/admin/propose-timetable', ['group_ids' => [$groupA->id, $groupB->id]]);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('placed', 1)->assertJsonPath('multi', true)->assertJsonPath('status', 'BATCH');
        $summaries = collect($response->json('groups'))->keyBy('group_id');
        $this->assertSame('ENGINE_ERROR', $summaries->get($groupA->id)['status']);
        $this->assertSame('OPTIMAL', $summaries->get($groupB->id)['status']);
    }

    public function test_engine_failure_only_for_one_group_others_survive(): void
    {
        [$faculty, $department, $groupA, $groupB, $course, $hall, $lecturer] = $this->makeTwoGroupChain();
        $deptUser = $this->makeUser('dept');
        $deptUser->forceFill(['faculty_id' => $faculty->id])->save();

        $this->mock(EngineClient::class, function ($mock) use ($groupA, $course, $hall, $lecturer) {
            $mock->shouldReceive('propose')->andReturnUsing(function (array $payload) use ($groupA, $course, $hall, $lecturer) {
                if ((int) $payload['group']['id'] === $groupA->id) {
                    throw new \RuntimeException('engine down');
                }

                return [
                    'ok' => true,
                    'status' => 'OPTIMAL',
                    'placed' => 1,
                    'assignments' => [
                        [
                            'course_id' => $course->id,
                            'faculty_id' => $lecturer->id,
                            'day' => 'السبت',
                            'start_time' => '08:00:00',
                            'end_time' => '09:30:00',
                            'hall_id' => $hall->id,
                            'course_code' => $course->code,
                        ],
                    ],
                    'unplaced' => [],
                ];
            });
        });

        $response = $this->actingAs($deptUser)
            ->postJson('/api/admin/propose-timetable', ['group_ids' => [$groupA->id, $groupB->id]]);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('placed', 1)->assertJsonPath('multi', true)->assertJsonPath('status', 'BATCH');
        $summaries = collect($response->json('groups'))->keyBy('group_id');
        $this->assertSame('ENGINE_ERROR', $summaries->get($groupA->id)['status']);
        $this->assertSame('OPTIMAL', $summaries->get($groupB->id)['status']);
    }

    public function test_engine_throws_for_all_groups_returns_503(): void
    {
        [$faculty, $department, $groupA, $groupB] = $this->makeTwoGroupChain();
        $deptUser = $this->makeUser('dept');
        $deptUser->forceFill(['faculty_id' => $faculty->id])->save();

        $this->mock(EngineClient::class, function ($mock) {
            $mock->shouldReceive('propose')->andThrow(new \RuntimeException('engine down'));
        });

        $response = $this->actingAs($deptUser)
            ->postJson('/api/admin/propose-timetable', ['group_ids' => [$groupA->id, $groupB->id]])
            ->assertStatus(503);

        $this->assertFalse($response->json('ok'));
        $this->assertNotEmpty($response->json('message_ar'));
    }
}
