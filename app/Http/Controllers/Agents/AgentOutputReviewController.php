<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentOutput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AgentOutputReviewController extends Controller
{
    public function approve(Request $request, AgentOutput $output): RedirectResponse
    {
        $this->authorizeOutput($request, $output);

        $output->update([
            'status' => AgentOutput::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->string('note')->toString() ?: null,
        ]);

        return back()->with('success', 'Agent output approved.');
    }

    public function reject(Request $request, AgentOutput $output): RedirectResponse
    {
        $this->authorizeOutput($request, $output);

        $output->update([
            'status' => AgentOutput::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->string('note')->toString() ?: null,
        ]);

        return back()->with('success', 'Agent output rejected.');
    }

    public function sendToToday(Request $request, AgentOutput $output): RedirectResponse
    {
        $this->authorizeOutput($request, $output);

        $output->update([
            'sent_to_today_at' => now(),
        ]);

        return back()->with('success', 'Agent output sent to Today.');
    }

    private function authorizeOutput(Request $request, AgentOutput $output): void
    {
        $output->loadMissing('run');

        abort_unless($output->run?->user_id === $request->user()->id, 403);
    }
}
