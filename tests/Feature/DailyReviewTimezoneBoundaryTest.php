<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DailyReview\DailyReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DailyReviewTimezoneBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public static function midnightInstants(): array
    {
        return [['2026-10-08 19:59:00 UTC', '2026-10-08'], ['2026-10-08 20:01:00 UTC', '2026-10-09']];
    }

    #[DataProvider('midnightInstants')]
    public function test_relative_groups_and_completed_instants_use_the_dubai_day(string $instant, string $day): void
    {
        $this->travelTo($instant);
        config(['app.operational_timezone' => 'Asia/Dubai']);
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Synthetic timezone', 'slug' => 'timezone', 'created_by' => $user->id]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $defaults = ['workspace_id' => $workspace->id, 'assignee_id' => $user->id, 'reporter_id' => $user->id, 'status' => 'todo'];
        $due = Task::create([...$defaults, 'title' => 'Synthetic today', 'due_date' => $day]);
        $missed = Task::create([...$defaults, 'title' => 'Synthetic yesterday', 'due_date' => CarbonImmutable::parse($day)->subDay()->toDateString()]);
        $completed = Task::create([...$defaults, 'title' => 'Synthetic completed', 'due_date' => $day,
            'status' => 'completed', 'completed_at' => CarbonImmutable::parse($instant)]);
        $groups = app(DailyReviewService::class)->collectTodayTasks($user);
        $this->assertSame([$due->id], $groups['due_today']->pluck('id')->all());
        $this->assertSame([$missed->id], $groups['missed_yesterday']->pluck('id')->all());
        $this->assertSame([$completed->id], $groups['completed_today']->pluck('id')->all());
        $this->assertSame([$missed->id], $groups['overdue']->pluck('id')->all());
    }
}
