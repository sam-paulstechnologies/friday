<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\TaskReview\PrioritizationReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PrioritizationReviewController extends Controller
{
    public function index(Request $request, PrioritizationReviewService $reviewService): Response
    {
        return Inertia::render('PrioritizationReview/Index', [
            'review' => $reviewService->build($request->only([
                'area_id',
                'portfolio_id',
                'project_id',
                'status',
                'priority',
                'due_bucket',
                'suggested_bucket',
                'search',
            ])),
        ]);
    }

    public function apply(Request $request, PrioritizationReviewService $reviewService): RedirectResponse
    {
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer', Rule::exists('tasks', 'id')],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'due_date' => ['nullable', 'date'],
            'bucket' => ['nullable', Rule::in(array_keys(PrioritizationReviewService::BUCKETS))],
            'confirmation' => ['accepted'],
        ]);

        $changes = [];

        if (! empty($data['bucket'])) {
            $changes = $reviewService->bucketUpdates($data['bucket']);
        }

        foreach (['priority', 'status', 'due_date'] as $field) {
            if ($request->exists($field)) {
                $changes[$field] = $data[$field] ?? null;
            }
        }

        if ($changes === []) {
            return back()->with('error', 'Choose at least one change to apply.');
        }

        $tasks = Task::query()
            ->whereIn('id', $data['task_ids'])
            ->get();

        $updated = $reviewService->apply($tasks, $changes, $request->user()?->id);

        return back()->with('success', "Prioritization review updated {$updated} selected task(s).");
    }
}
