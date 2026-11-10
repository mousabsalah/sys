<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * AuditLogger — سجل تدقيق موحّد لكل الإجراءات الإدارية الحساسة.
 * يُخزّن: من فعل + نوع الإجراء + الهدف + سياق JSON + ip + user_agent.
 */
class AuditLogger
{
    public function log(Request $request, string $action, string $targetType, int|string|null $targetId = null, array $context = []): void
    {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? (string) $targetId : null,
            'context' => $context,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->header('User-Agent', ''), 0, 500),
        ]);
    }

    public function logForUser(User|int|null $actor, string $action, string $targetType, int|string|null $targetId = null, array $context = []): void
    {
        $actorId = $actor instanceof User ? $actor->id : (is_int($actor) ? $actor : null);

        AuditLog::create([
            'user_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? (string) $targetId : null,
            'context' => $context,
        ]);
    }
}
