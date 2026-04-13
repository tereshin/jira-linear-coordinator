<?php

namespace App\Services;

use App\Models\SyncLock;
use Illuminate\Support\Carbon;

class SyncLockService
{
    public function isLocked(string $source, string $issueRef): bool
    {
        return SyncLock::where('source', $source)
            ->where('issue_ref', $issueRef)
            ->where('expires_at', '>', Carbon::now())
            ->exists();
    }

    public function lock(string $source, string $issueRef, int $ttlSeconds = 30): void
    {
        SyncLock::updateOrCreate(
            ['source' => $source, 'issue_ref' => $issueRef],
            ['expires_at' => Carbon::now()->addSeconds($ttlSeconds)]
        );
    }

    public function unlock(string $source, string $issueRef): void
    {
        SyncLock::where('source', $source)
            ->where('issue_ref', $issueRef)
            ->delete();
    }

    public function clearExpired(): void
    {
        SyncLock::where('expires_at', '<=', Carbon::now())->delete();
    }
}
