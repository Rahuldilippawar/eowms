<?php

namespace App\Services\Correspondence;

use App\Models\Employee;
use App\Models\Letter;
use App\Models\LetterAttachment;
use App\Models\LetterMovement;
use App\Models\LetterSignature;
use App\Models\User;
use App\Services\AttendanceRules;
use App\Services\Finance\BudgetLedger;
use App\Services\Notifier;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberSequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Correspondence files (erp/docs/CORRESPONDENCE.md): a file is created as a
 * draft, submitted to its first holder (the first workflow step, or a person
 * the creator picks), forwarded or sent back, and finally signed and closed
 * by its last holder after confirming their password. Every move is on the
 * file's register; each department a file reaches gets an inward number.
 */
final class LetterService
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    /**
     * Files the user may open: their own, ones they hold or have handled, and
     * those within their `letters.view` scope.
     *
     * @return Builder<Letter>
     */
    public static function visible(User $user): Builder
    {
        $scope = AccessContext::current()?->scope('letters.view');
        if ($scope?->isOrganizationWide()) {
            return Letter::query();
        }

        return Letter::query()->where(function (Builder $q) use ($user, $scope) {
            $q->where('created_by', $user->id)
                ->orWhere('current_holder_id', $user->id)
                ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('letter_movements')->whereColumn('letter_movements.letter_id', 'letters.id')
                    ->where(fn ($w) => $w->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id)));
            if ($scope !== null) {
                $q->orWhere(fn ($s) => $scope->apply($s, ['institution' => 'institution_id', 'department' => 'department_id', 'owner' => 'created_by', 'assignee' => 'current_holder_id']));
            }
        });
    }

    /** @return list<string> what $user may do with $letter right now */
    public function actionsFor(User $user, Letter $letter): array
    {
        $creator = $letter->created_by === $user->id;
        $holder = $letter->isMoving() && $letter->current_holder_id === $user->id;
        $final = $holder && $this->atFinalStep($letter);
        $manager = $this->canManage($letter);

        return array_values(array_filter([
            $creator && $letter->status === 'draft' ? 'edit' : null,
            $creator && $letter->status === 'draft' ? 'submit' : null,
            // Without a workflow the holder picks the next person, or signs.
            $holder && (! $final || $letter->approval_workflow_id === null) ? 'forward' : null,
            $holder && $this->returnTarget($letter, $user) !== null ? 'return' : null,
            $final ? 'sign' : null,
            $creator || $holder ? 'remark' : null,
            ($creator || $holder) && $letter->status !== 'completed' ? 'attach' : null,
            $creator || $holder || $manager ? 'dispatch' : null,
            $manager && $letter->isMoving() ? 'reassign' : null,
        ]));
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): Letter
    {
        $employee = $this->employeeOf($actor->id);
        $organization = AccessContext::current()->organization();

        return DB::transaction(function () use ($data, $actor, $employee, $organization) {
            [, $fy] = NumberSequence::financialYear($this->today());
            $seq = NumberSequence::next($organization->id, 'letter.file', $fy);
            $letter = Letter::create($this->fields($data) + [
                'file_number' => sprintf('FILE-%s-%04d', $fy, $seq),
                'department_id' => $employee?->department_id,
                'institution_id' => $employee?->institution_id ?? $employee?->department?->institution_id,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
            $this->move($letter, 'created', $actor->id, null);
            AuditLogger::record('letters', 'create', $letter, null, AuditLogger::snapshot($letter));

            return $letter;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Letter $letter, array $data, User $actor): Letter
    {
        $this->assertAllowed($actor, $letter, 'edit');
        $before = $letter->getAttributes();
        $letter->fill($this->fields($data))->save();
        AuditLogger::recordChanges('letters', 'update', $letter, $before);

        return $letter;
    }

    /** Submit a draft, or forward a held file: to the next workflow step, or to $toUserId when there is no workflow. */
    public function send(Letter $letter, string $action, ?int $toUserId, ?string $remark, User $actor): Letter
    {
        $this->assertAllowed($actor, $letter, $action === 'submit' ? 'submit' : 'forward');

        $letter = DB::transaction(function () use ($letter, $action, $toUserId, $remark, $actor) {
            $letter = $this->lock($letter);
            $this->assertAllowed($actor, $letter, $action === 'submit' ? 'submit' : 'forward');
            $stepOrder = $letter->current_step_order;
            if ($letter->approval_workflow_id !== null) {
                $workflow = $letter->workflow()->firstOrFail();
                $step = $this->engine->nextStep($workflow, $action === 'submit' ? 0 : $letter->current_step_order);
                if ($step === null) {
                    throw ValidationException::withMessages(['workflow' => [__('letters.no_steps')]]);
                }
                $to = $this->engine->approver($step, $letter->department_id);
                if ($to === null) {
                    throw ValidationException::withMessages(['workflow' => [__('letters.no_approver', ['step' => $step->name])]]);
                }
                $stepOrder = $step->step_order;
            } else {
                $to = $toUserId ? $this->recipient($toUserId, $actor) : null;
                if ($to === null) {
                    throw ValidationException::withMessages(['to_user_id' => [__('letters.choose_recipient')]]);
                }
            }
            if ($action === 'submit' && $letter->submitted_at === null) {
                $letter->submitted_at = now();
                if ($letter->department_id !== null) {
                    $letter->outward_no = $this->outwardNumber($letter);
                }
            }
            $letter->forceFill(['status' => 'in_progress', 'current_step_order' => $stepOrder])->save();
            $this->handTo($letter, $to->id, $action === 'submit' ? 'submitted' : 'forwarded', $actor, $remark);
            AuditLogger::record('letters', $action, $letter, null, ['to' => $to->id, 'step' => $stepOrder]);

            return $letter;
        });
        $this->notifyHolder($letter, 'letter.received', $actor);

        return $letter;
    }

    /** Send a held file back to whoever sent it here (or the creator), one workflow step back. */
    public function sendBack(Letter $letter, ?string $remark, User $actor): Letter
    {
        $letter = DB::transaction(function () use ($letter, $remark, $actor) {
            $letter = $this->lock($letter);
            $this->assertAllowed($actor, $letter, 'return');
            $to = $this->returnTarget($letter, $actor);
            $step = $letter->approval_workflow_id ? $this->engine->previousOrder($letter->workflow()->firstOrFail(), $letter->current_step_order) : $letter->current_step_order;
            $letter->forceFill(['status' => 'returned', 'current_step_order' => $step])->save();
            $this->handTo($letter, $to, 'returned', $actor, $remark);
            AuditLogger::record('letters', 'return', $letter, null, ['to' => $to, 'step' => $step]);

            return $letter;
        });
        $this->notifyHolder($letter, 'letter.returned', $actor);

        return $letter;
    }

    public function remark(Letter $letter, string $remark, User $actor): void
    {
        $this->assertAllowed($actor, $letter, 'remark');
        $this->move($letter, 'remark', $actor->id, null, $remark);
    }

    /** `letters.manage`: give a stuck file to somebody else (e.g. the holder left). */
    public function reassign(Letter $letter, int $toUserId, ?string $remark, User $actor): Letter
    {
        $letter = DB::transaction(function () use ($letter, $toUserId, $remark, $actor) {
            $letter = $this->lock($letter);
            $this->assertAllowed($actor, $letter, 'reassign');
            $to = $this->recipient($toUserId, null);
            if ($to === null || $to->id === $letter->current_holder_id) {
                throw ValidationException::withMessages(['to_user_id' => [__('letters.choose_recipient')]]);
            }
            $this->handTo($letter, $to->id, 'reassigned', $actor, $remark);
            AuditLogger::record('letters', 'reassign', $letter, null, ['to' => $to->id]);

            return $letter;
        });
        $this->notifyHolder($letter, 'letter.received', $actor);

        return $letter;
    }

    /** @param  array{dispatch_mode: string, dispatch_tracking_no?: ?string, dispatch_date?: ?string}  $data */
    public function dispatch(Letter $letter, array $data, User $actor): Letter
    {
        $this->assertAllowed($actor, $letter, 'dispatch');
        $before = $letter->getAttributes();
        $letter->forceFill([
            'dispatch_mode' => $data['dispatch_mode'],
            'dispatch_tracking_no' => $data['dispatch_tracking_no'] ?? null,
            'dispatch_date' => $data['dispatch_date'] ?? $this->today()->toDateString(),
        ])->save();
        $this->move($letter, 'dispatched', $actor->id, null, trim(($data['dispatch_mode']).' '.($data['dispatch_tracking_no'] ?? '')));
        AuditLogger::recordChanges('letters', 'dispatch', $letter, $before);

        return $letter;
    }

    public function attach(Letter $letter, UploadedFile $file, User $actor): LetterAttachment
    {
        $this->assertAllowed($actor, $letter, 'attach');
        $path = $file->storeAs("letters/{$letter->organization_id}/{$letter->id}", Str::uuid().'.'.$file->guessExtension(), 'local');
        $attachment = $letter->attachments()->create([
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $actor->id,
        ]);
        $this->move($letter, 'attached', $actor->id, null, $attachment->original_name);
        AuditLogger::record('letters', 'attach', $letter, null, ['file' => $attachment->original_name]);

        return $attachment;
    }

    /** Sign and close: the last holder confirms their password; the signature seals the file's content. */
    public function sign(Letter $letter, string $password, ?string $remark, ?float $approvedAmount, User $actor): LetterSignature
    {
        if (! Hash::check($password, (string) $actor->getAuthPassword())) {
            AuditLogger::record('letters', 'sign_failed', $letter);
            throw ValidationException::withMessages(['password' => [__('letters.wrong_password')]]);
        }

        return $this->complete($letter, 'password', $remark, $approvedAmount, $actor);
    }

    /** Signing after the person confirmed with their fingerprint / face / device PIN (checked by the caller). */
    public function signConfirmed(Letter $letter, ?string $remark, ?float $approvedAmount, User $actor): LetterSignature
    {
        return $this->complete($letter, 'webauthn', $remark, $approvedAmount, $actor);
    }

    private function complete(Letter $letter, string $method, ?string $remark, ?float $approvedAmount, User $actor): LetterSignature
    {
        $signature = DB::transaction(function () use ($letter, $method, $remark, $approvedAmount, $actor) {
            $letter = $this->lock($letter);
            $this->assertAllowed($actor, $letter, 'sign');
            if ($letter->is_budget_expense) {
                $letter->approved_amount = $approvedAmount ?? $letter->requested_amount;
            }
            $letter->forceFill(['status' => 'completed', 'current_holder_id' => null, 'holder_since' => null, 'completed_at' => now()])->save();
            $amount = $letter->is_budget_expense ? __('letters.approved_amount_note', ['amount' => number_format((float) $letter->approved_amount, 2)]) : null;
            $this->move($letter, 'completed', $actor->id, null, trim(implode(' — ', array_filter([$amount, $remark]))) ?: null);

            $employee = $this->employeeOf($actor->id);
            $previous = $letter->signatures()->latest('id')->value('content_hash');
            $signature = new LetterSignature([
                'user_id' => $actor->id,
                'signer_name' => $employee?->name ?? $actor->name,
                'signer_designation' => $employee?->designation,
                'action' => 'complete',
                'method' => $method,
                'verification_code' => strtoupper(Str::random(12)),
                'previous_hash' => $previous,
                'signed_at' => now()->startOfSecond(),
            ]);
            $signature->letter()->associate($letter);
            $signature->content_hash = self::contentHash($letter, $signature);
            $signature->save();
            AuditLogger::record('letters', 'sign', $letter, null, ['code' => $signature->verification_code, 'hash' => $signature->content_hash]);
            // An approved budget request is charged to the requesting department's budget.
            if ($letter->is_budget_expense && $letter->department_id !== null) {
                app(BudgetLedger::class)->record($letter->department()->firstOrFail(), (float) $letter->approved_amount, $letter->budget_category ?? 'other',
                    'letter', $letter->id, "{$letter->subject} ({$letter->file_number})", $this->today()->toDateString(), $actor->id);
            }

            return $signature;
        });
        Notifier::user($letter->created_by, $letter->organization_id, 'letter.completed', $this->summary($letter, $actor), "/letters/{$letter->id}");

        return $signature;
    }

    /**
     * What a signature seals: the file's numbers, subject, text, amount and
     * attachments, the signer and time, and the previous signature's hash. If
     * any of it changes later, the hash no longer matches.
     */
    public static function contentHash(Letter $letter, LetterSignature $signature): string
    {
        $content = [
            'letter' => $letter->id,
            'organization' => $letter->organization_id,
            'file_number' => $letter->file_number,
            'outward_no' => $letter->outward_no,
            'reference_no' => $letter->reference_no,
            'subject' => $letter->subject,
            'description' => $letter->description,
            'approved_amount' => $letter->approved_amount === null ? null : (string) $letter->approved_amount,
            'attachments' => $letter->attachments()->orderBy('id')->pluck('sha256')->all(),
            'signer' => [$signature->user_id, $signature->signer_name, $signature->signer_designation],
            'action' => $signature->action,
            'method' => $signature->method,
            'code' => $signature->verification_code,
            'signed_at' => $signature->signed_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'previous' => $signature->previous_hash,
        ];

        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Has the file stayed as it was signed? */
    public static function intact(LetterSignature $signature): bool
    {
        return hash_equals($signature->content_hash, self::contentHash($signature->letter, $signature));
    }

    public function markSeen(Letter $letter, User $user): void
    {
        if ($letter->current_holder_id === $user->id && $letter->holder_seen_at === null) {
            $letter->forceFill(['holder_seen_at' => now()])->save();
        }
    }

    public function atFinalStep(Letter $letter): bool
    {
        if ($letter->approval_workflow_id === null) {
            return true;
        }
        $workflow = $letter->workflow;

        return $workflow !== null && $letter->current_step_order > 0 && ! $this->engine->hasNext($workflow, $letter->current_step_order);
    }

    private function canManage(Letter $letter): bool
    {
        $scope = AccessContext::current()?->scope('letters.manage');

        return $scope !== null && $scope->apply(Letter::query()->whereKey($letter->id), ['institution' => 'institution_id', 'department' => 'department_id'])->exists();
    }

    /** Whoever last sent the file to $user, else the creator; null when that is $user themself. */
    private function returnTarget(Letter $letter, User $user): ?int
    {
        $from = $letter->movements()->getQuery()->reorder()->where('to_user_id', $user->id)
            ->whereIn('type', ['submitted', 'forwarded', 'reassigned'])->latest('id')->value('from_user_id');
        $to = $from ?: $letter->created_by;

        return $to === $user->id ? null : $to;
    }

    private function handTo(Letter $letter, int $toUserId, string $type, User $actor, ?string $remark): void
    {
        $letter->forceFill(['current_holder_id' => $toUserId, 'holder_since' => now(), 'holder_seen_at' => null])->save();
        $this->move($letter, $type, $actor->id, $toUserId, $remark, $letter->current_step_order ?: null);
        // The receiving department records the file in its own inward register, once.
        $department = $this->employeeOf($toUserId)?->department;
        if ($department !== null && $department->id !== $letter->department_id
            && ! $letter->inwardNumbers()->where('department_id', $department->id)->exists()) {
            [$fyStart, $fy] = NumberSequence::financialYear($this->today());
            $seq = NumberSequence::next($letter->organization_id, "letter.in.{$department->id}", $fy);
            $letter->inwardNumbers()->create([
                'department_id' => $department->id,
                'inward_no' => sprintf('%s/IN/%s/%04d', $department->code, $fy, $seq),
                'fy_start_year' => $fyStart,
            ]);
        }
    }

    private function outwardNumber(Letter $letter): string
    {
        $letter->loadMissing('department');
        [, $fy] = NumberSequence::financialYear($this->today());
        $seq = NumberSequence::next($letter->organization_id, "letter.out.{$letter->department_id}", $fy);

        return sprintf('%s/%s/%04d', $letter->department->code, $fy, $seq);
    }

    private function move(Letter $letter, string $type, ?int $from, ?int $to, ?string $remark = null, ?int $step = null): LetterMovement
    {
        return $letter->movements()->create([
            'type' => $type, 'from_user_id' => $from, 'to_user_id' => $to, 'step_order' => $step,
            'remark' => $remark === null || trim($remark) === '' ? null : mb_substr(trim($remark), 0, 2000),
        ]);
    }

    /** An active colleague with an employee record in this organization (never $except). */
    private function recipient(int $userId, ?User $except): ?User
    {
        if ($except !== null && $userId === $except->id) {
            return null;
        }
        $employee = Employee::query()->where('user_id', $userId)->where('is_active', true)->first();

        return $employee ? User::query()->whereKey($userId)->where('is_active', true)->first() : null;
    }

    private function employeeOf(int $userId): ?Employee
    {
        return Employee::query()->with('department')->where('user_id', $userId)->first();
    }

    /** @return array<string, mixed> */
    private function fields(array $data): array
    {
        $budget = (bool) ($data['is_budget_expense'] ?? false);

        return [
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'letter_category_id' => $data['letter_category_id'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'approval_workflow_id' => $data['approval_workflow_id'] ?? null,
            'is_budget_expense' => $budget,
            'budget_category' => $budget ? ($data['budget_category'] ?? 'other') : null,
            'requested_amount' => $budget ? $data['requested_amount'] : null,
        ];
    }

    private function assertAllowed(User $actor, Letter $letter, string $action): void
    {
        if (! in_array($action, $this->actionsFor($actor, $letter), true)) {
            throw ValidationException::withMessages(['status' => [__('letters.not_allowed')]]);
        }
    }

    private function lock(Letter $letter): Letter
    {
        return Letter::query()->whereKey($letter->id)->lockForUpdate()->firstOrFail();
    }

    private function today(): CarbonImmutable
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now();
    }

    private function notifyHolder(Letter $letter, string $type, User $actor): void
    {
        Notifier::user($letter->current_holder_id, $letter->organization_id, $type, $this->summary($letter, $actor), "/letters/{$letter->id}");
    }

    /** @return array<string, mixed> */
    private function summary(Letter $letter, User $actor): array
    {
        return ['file_number' => $letter->file_number, 'subject' => mb_substr($letter->subject, 0, 120), 'by' => $actor->name];
    }
}
