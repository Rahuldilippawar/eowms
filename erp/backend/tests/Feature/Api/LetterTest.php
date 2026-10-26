<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionScope;
use App\Models\ApprovalWorkflow;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\Letter;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class LetterTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Organization $org;

    private Department $physics;

    private Department $accounts;

    private User $clerk;

    private User $hod;

    private User $accountant;

    private User $principal;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPermissions();
        Storage::fake('local');
        $this->org = Organization::factory()->create();
        $college = Institution::factory()->for($this->org)->create();
        $this->physics = Department::factory()->for($this->org)->create(['institution_id' => $college->id, 'code' => 'PHY']);
        $this->accounts = Department::factory()->for($this->org)->create(['code' => 'ACC']);
        $office = Department::factory()->for($this->org)->create(['code' => 'OFF']);
        $this->clerk = $this->person($this->physics, 'Clerk');
        $this->hod = $this->person($this->physics, 'HOD');
        $this->accountant = $this->person($this->accounts, 'Accountant');
        $this->principal = $this->person($office, 'Principal');
        $this->outsider = $this->person($office, 'Outsider');
        $this->physics->forceFill(['head_user_id' => $this->hod->id])->save();
        $this->accounts->forceFill(['head_user_id' => $this->accountant->id])->save();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 11:00', 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-10 11:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(Department $department, string $designation, array $permissions = [], PermissionScope $scope = PermissionScope::Organization): User
    {
        $user = $this->userWithRole($this->org, $permissions, $scope, departmentIds: [$department->id]);
        Employee::factory()->in($department)->create(['user_id' => $user->id, 'name' => $user->name, 'designation' => $designation]);

        return $user;
    }

    private function workflow(): ApprovalWorkflow
    {
        $workflow = ApprovalWorkflow::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'module' => 'letters', 'name' => 'Purchase']);
        $workflow->steps()->createMany([
            ['step_order' => 1, 'name' => 'Own head', 'approver_type' => 'origin_department_head'],
            ['step_order' => 2, 'name' => 'Accounts', 'approver_type' => 'department_head', 'department_id' => $this->accounts->id],
            ['step_order' => 3, 'name' => 'Principal', 'approver_type' => 'user', 'user_id' => $this->principal->id],
        ]);

        return $workflow;
    }

    private function draft(array $override = []): TestResponse
    {
        $this->actingAs($this->clerk);

        return $this->spa()->postJson('/api/v1/letters', ['subject' => 'New microscopes', 'description' => 'Two for the lab', 'priority' => 'high', ...$override]);
    }

    private function as(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        $this->actingAs($user);

        return $this->spa()->json($method, $uri, $data);
    }

    /** @return list<string> */
    private function notificationsOf(User $user): array
    {
        return UserNotification::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_workflow_route_numbers_signature_and_public_check(): void
    {
        $workflow = $this->workflow();
        $id = $this->draft(['approval_workflow_id' => $workflow->id, 'is_budget_expense' => true, 'budget_category' => 'purchase', 'requested_amount' => 50000])
            ->assertCreated()->assertJsonPath('data.file_number', 'FILE-26-27-0001')->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.actions', ['edit', 'submit', 'remark', 'attach', 'dispatch'])->json('data.id');
        $this->spa()->postJson('/api/v1/letters', ['subject' => 'Second', 'priority' => 'normal'])->assertJsonPath('data.file_number', 'FILE-26-27-0002');

        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/submit")->assertOk()
            ->assertJsonPath('data.outward_no', 'PHY/26-27/0001')->assertJsonPath('data.holder.id', $this->hod->id)->assertJsonPath('data.current_step_order', 1)
            ->assertJsonPath('data.steps.1.approver.id', $this->accountant->id);
        $this->assertContains('letter.received', $this->notificationsOf($this->hod));
        // Same department: no inward number for the head of the creator's own department.
        $this->as($this->hod, 'GET', "/api/v1/letters/{$id}")->assertOk()->assertJsonCount(0, 'data.inward_numbers')->assertJsonPath('data.actions', ['forward', 'return', 'remark', 'attach', 'dispatch']);
        $this->as($this->hod, 'POST', "/api/v1/letters/{$id}/sign", ['password' => 'password'])->assertStatus(422);

        $this->as($this->hod, 'POST', "/api/v1/letters/{$id}/forward", ['remark' => 'Recommended'])->assertOk()->assertJsonPath('data.holder.id', $this->accountant->id);
        $this->as($this->accountant, 'GET', "/api/v1/letters/{$id}")->assertJsonPath('data.inward_numbers.0.inward_no', 'ACC/IN/26-27/0001');
        // Accounts sends it back one step: to the head, who forwards again; Accounts keeps its one inward number.
        $this->as($this->accountant, 'POST', "/api/v1/letters/{$id}/return")->assertStatus(422)->assertJsonValidationErrors('remark');
        $this->as($this->accountant, 'POST', "/api/v1/letters/{$id}/return", ['remark' => 'Attach quotation'])->assertOk()
            ->assertJsonPath('data.status', 'returned')->assertJsonPath('data.holder.id', $this->hod->id)->assertJsonPath('data.current_step_order', 1);
        $this->as($this->hod, 'POST', "/api/v1/letters/{$id}/forward")->assertOk();
        $this->as($this->accountant, 'POST', "/api/v1/letters/{$id}/forward")->assertOk()->assertJsonPath('data.holder.id', $this->principal->id)
            ->assertJsonCount(2, 'data.inward_numbers');

        $this->as($this->principal, 'GET', "/api/v1/letters/{$id}")->assertJsonPath('data.actions', ['return', 'sign', 'remark', 'attach', 'dispatch']);
        $this->as($this->principal, 'POST', "/api/v1/letters/{$id}/sign", ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
        $signed = $this->as($this->principal, 'POST', "/api/v1/letters/{$id}/sign", ['password' => 'password', 'approved_amount' => 45000])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.holder', null)->assertJsonPath('data.approved_amount', 45000)
            ->assertJsonPath('data.signatures.0.intact', true)->assertJsonPath('data.signatures.0.signer_name', $this->principal->name);
        $this->assertSame(['created', 'submitted', 'forwarded', 'returned', 'forwarded', 'forwarded', 'completed'], collect($signed->json('data.movements'))->pluck('type')->all());
        $this->assertSame('letter.completed', last($this->notificationsOf($this->clerk)));

        // Public check needs no login and never shows the subject; a later change shows as not intact.
        $code = $signed->json('data.signatures.0.verification_code');
        auth()->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->getJson("/api/v1/public/signatures/{$code}")->assertOk()->assertJsonPath('data.intact', true)->assertJsonPath('data.file_number', 'FILE-26-27-0001')
            ->assertJsonMissingPath('data.subject');
        DB::table('letters')->where('id', $id)->update(['subject' => 'New microscopes (changed)']);
        $this->getJson("/api/v1/public/signatures/{$code}")->assertOk()->assertJsonPath('data.intact', false);
        $this->getJson('/api/v1/public/signatures/NOTAREALCODE')->assertNotFound();
    }

    public function test_without_workflow_the_sender_picks_and_return_goes_back_to_the_sender(): void
    {
        $id = $this->draft()->json('data.id');
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/submit")->assertStatus(422)->assertJsonValidationErrors('to_user_id');
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/submit", ['to_user_id' => $this->clerk->id])->assertStatus(422);
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/submit", ['to_user_id' => $this->accountant->id])->assertOk()->assertJsonPath('data.holder.id', $this->accountant->id);
        // No workflow: whoever holds it may sign, forward or send it back.
        $this->as($this->accountant, 'GET', "/api/v1/letters/{$id}")->assertJsonPath('data.actions', ['forward', 'return', 'sign', 'remark', 'attach', 'dispatch']);
        $this->as($this->accountant, 'POST', "/api/v1/letters/{$id}/forward", ['to_user_id' => $this->principal->id])->assertOk();
        $this->as($this->principal, 'POST', "/api/v1/letters/{$id}/return", ['remark' => 'Ask the clerk'])->assertOk()->assertJsonPath('data.holder.id', $this->accountant->id);

        $this->as($this->accountant, 'POST', "/api/v1/letters/{$id}/return", ['remark' => 'Wrong form'])->assertOk()->assertJsonPath('data.holder.id', $this->clerk->id);
        $this->as($this->clerk, 'GET', "/api/v1/letters/{$id}")->assertJsonPath('data.actions', ['forward', 'sign', 'remark', 'attach', 'dispatch']);
        $this->as($this->clerk, 'PUT', "/api/v1/letters/{$id}", ['subject' => 'Edited', 'priority' => 'normal'])->assertStatus(422);
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/sign", ['password' => 'password'])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_who_sees_a_file_and_the_inbox(): void
    {
        $id = $this->draft()->json('data.id');
        $this->as($this->outsider, 'GET', "/api/v1/letters/{$id}")->assertNotFound();
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/submit", ['to_user_id' => $this->hod->id])->assertOk();
        $this->as($this->hod, 'GET', '/api/v1/letters')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.with_me', 1);
        $this->as($this->hod, 'POST', "/api/v1/letters/{$id}/forward", ['to_user_id' => $this->principal->id])->assertOk();
        // Handled it once: still visible, but no longer in the inbox.
        $this->as($this->hod, 'GET', "/api/v1/letters/{$id}")->assertOk()->assertJsonPath('data.actions', []);
        $this->as($this->hod, 'GET', '/api/v1/letters')->assertJsonCount(0, 'data');
        $this->as($this->hod, 'GET', '/api/v1/letters?box=all')->assertJsonCount(1, 'data');

        $viewer = $this->person($this->physics, 'Office', ['letters.view'], PermissionScope::Departments);
        $this->as($viewer, 'GET', "/api/v1/letters/{$id}")->assertOk();
        $other = $this->person($this->accounts, 'Office', ['letters.view'], PermissionScope::Departments);
        $this->as($other, 'GET', "/api/v1/letters/{$id}")->assertNotFound();
        $this->as($other, 'GET', '/api/v1/letters?box=all&search=microscope')->assertJsonCount(0, 'data');
    }

    public function test_attachments_are_private_and_sealed_by_the_signature(): void
    {
        $id = $this->draft()->json('data.id');
        $file = UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $attachment = $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/attachments", ['file' => $file])->assertOk()->json('data.attachments.0.id');
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/attachments", ['file' => UploadedFile::fake()->create('x.exe', 5)])->assertStatus(422);
        $this->as($this->clerk, 'GET', "/api/v1/letters/{$id}/attachments/{$attachment}")->assertOk();
        $this->as($this->outsider, 'GET', "/api/v1/letters/{$id}/attachments/{$attachment}")->assertNotFound();

        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/submit", ['to_user_id' => $this->hod->id]);
        $code = $this->as($this->hod, 'POST', "/api/v1/letters/{$id}/sign", ['password' => 'password'])->json('data.signatures.0.verification_code');
        $this->as($this->hod, 'POST', "/api/v1/letters/{$id}/attachments", ['file' => $file])->assertStatus(422);
        DB::table('letter_attachments')->where('id', $attachment)->update(['sha256' => str_repeat('0', 64)]);
        $this->getJson("/api/v1/public/signatures/{$code}")->assertJsonPath('data.intact', false);
    }

    public function test_settings_registers_and_reassign(): void
    {
        $admin = $this->person($this->accounts, 'Registrar', ['letters.manage', 'letters.view', 'letters.export']);
        $deptManager = $this->person($this->physics, 'Office', ['letters.manage'], PermissionScope::Departments);
        $this->as($deptManager, 'GET', '/api/v1/letter-settings/categories')->assertForbidden();

        $this->as($admin, 'POST', '/api/v1/letter-settings/categories/defaults')->assertOk()->assertJsonPath('data.added', 18);
        $this->as($admin, 'POST', '/api/v1/letter-settings/categories/defaults')->assertJsonPath('data.added', 0);
        $this->as($admin, 'POST', '/api/v1/letter-settings/workflows', ['name' => 'Bad', 'steps' => [['name' => 'X', 'approver_type' => 'department_head']]])
            ->assertStatus(422)->assertJsonValidationErrors('steps.0.department_id');
        $this->as($admin, 'POST', '/api/v1/letter-settings/workflows', ['name' => 'Two step', 'steps' => [
            ['name' => 'Head', 'approver_type' => 'origin_department_head'],
            ['name' => 'Principal', 'approver_type' => 'user', 'user_id' => $this->principal->id],
        ]])->assertCreated()->assertJsonPath('data.steps.1.resolves_to', $this->principal->name)->assertJsonPath('data.steps.1.step_order', 2);
        $this->as($this->clerk, 'GET', '/api/v1/letters/options')->assertOk()->assertJsonCount(18, 'data.categories')->assertJsonCount(1, 'data.workflows');

        // A department with no head: the file cannot start that workflow.
        $workflow = ApprovalWorkflow::withoutGlobalScopes()->where('name', 'Two step')->firstOrFail();
        $this->physics->forceFill(['head_user_id' => null])->save();
        $id = $this->draft(['approval_workflow_id' => $workflow->id])->json('data.id');
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/submit")->assertStatus(422)->assertJsonValidationErrors('workflow');
        $this->physics->forceFill(['head_user_id' => $this->hod->id])->save();
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/submit")->assertOk();

        // The head is away: an organization-wide manager gives the file to somebody else.
        $this->as($admin, 'POST', "/api/v1/letters/{$id}/reassign", ['to_user_id' => $this->accountant->id, 'remark' => 'HOD on leave'])->assertOk()
            ->assertJsonPath('data.holder.id', $this->accountant->id);
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/reassign", ['to_user_id' => $this->outsider->id])->assertStatus(422);

        $this->as($admin, 'GET', '/api/v1/letters/register?type=outward')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'PHY/26-27/0001')
            ->assertJsonPath('meta.fy', 2026);
        $this->as($admin, 'GET', '/api/v1/letters/register?type=inward')->assertJsonPath('data.0.number', 'ACC/IN/26-27/0001');
        $this->assertStringContainsString('PHY/26-27/0001', $this->as($admin, 'GET', '/api/v1/letters/register?type=outward&format=csv')->streamedContent());
        $this->as($this->hod, 'GET', '/api/v1/letters/register?type=outward')->assertForbidden();
    }

    public function test_reminders_for_files_that_do_not_move(): void
    {
        $id = $this->draft()->json('data.id');
        $this->as($this->clerk, 'POST', "/api/v1/letters/{$id}/submit", ['to_user_id' => $this->accountant->id]);
        Letter::withoutGlobalScopes()->whereKey($id)->update(['holder_since' => now()->subDays(4)]);
        $this->artisan('erp:letter-reminders')->assertSuccessful();
        $this->artisan('erp:letter-reminders')->assertSuccessful();
        $this->assertSame(['letter.overdue_holder'], array_values(array_filter($this->notificationsOf($this->accountant), fn ($t) => str_starts_with($t, 'letter.overdue'))));
        $this->assertSame(['letter.overdue'], array_values(array_filter($this->notificationsOf($this->clerk), fn ($t) => str_starts_with($t, 'letter.overdue'))));
        $this->assertSame([], $this->notificationsOf($this->hod));

        Letter::withoutGlobalScopes()->whereKey($id)->update(['holder_since' => now()->subDays(8)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-11 11:00', 'Asia/Kolkata'));
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-11 11:00', 'Asia/Kolkata'));
        $this->artisan('erp:letter-reminders')->assertSuccessful();
        $this->assertSame(['letter.overdue'], $this->notificationsOf($this->hod));
    }
}
