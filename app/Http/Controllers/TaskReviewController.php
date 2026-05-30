<?php

namespace App\Http\Controllers;

use App\Services\TaskReview\TaskReviewReportService;
use Inertia\Inertia;
use Inertia\Response;

class TaskReviewController extends Controller
{
    public function index(TaskReviewReportService $reportService): Response
    {
        return Inertia::render('TaskReview/Index', [
            'review' => $reportService->build(),
        ]);
    }
}
