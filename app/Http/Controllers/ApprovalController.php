<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use Illuminate\Http\Request;

class ApprovalController extends CommandCenterController
{
    protected function modelClass(): string { return Approval::class; }
    protected function page(): string { return 'Approvals/Index'; }
    protected function openStatus(): string { return 'pending'; }
    protected function closedStatus(): string { return 'approved'; }
    protected function closedTimestampColumn(): string { return 'approved_at'; }

    protected function extraValidation(): array
    {
        return [
            'requested_by' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function update(Request $request, Approval $approval)
    {
        return $this->updateItem($request, $approval);
    }

    public function close(Request $request, Approval $approval)
    {
        return $this->closeItem($request, $approval);
    }

    public function reject(Request $request, Approval $approval)
    {
        abort_unless($approval->user_id === $request->user()->id, 403);

        $approval->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        return back()->with('success', 'Approval rejected.');
    }
}
