<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\BibleReadingPlanDay;
use App\Models\BibleReadingPlanDayChapter;
use App\Models\BibleTranslation;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DailyReview\DailyBriefingService;
use App\Services\DailyReview\DailyReviewService;
use App\Services\Spiritual\SpiritualReadingSummaryService;
use Database\Seeders\BibleContentSeeder;
use Database\Seeders\SpiritualBibleReadingPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SpiritualAndNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_spiritual_page_loads(): void
    {
        [$user] = $this->context();
        $this->seed(SpiritualBibleReadingPlanSeeder::class);
        $this->seed(BibleContentSeeder::class);

        $this->actingAs($user)
            ->get(route('spiritual.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Spiritual/Index')
                ->where('summary.total_chapters', 1189)
                ->where('translations.0.code', 'KJV')
                ->has('todayScripture.0.verses')
            );

        $this->assertSame(31103, BibleTranslation::query()->where('code', 'KJV')->withCount('verses')->first()->verses_count);
    }

    public function test_chapter_can_be_toggled_read_and_unread(): void
    {
        [$user] = $this->context();
        $this->seed(SpiritualBibleReadingPlanSeeder::class);
        $chapter = BibleReadingPlanDayChapter::query()->firstOrFail();

        $this->actingAs($user)
            ->post(route('spiritual.readings.toggle'), ['chapter_id' => $chapter->id])
            ->assertRedirect();

        $this->assertDatabaseHas('bible_reading_progress', [
            'user_id' => $user->id,
            'bible_reading_plan_day_chapter_id' => $chapter->id,
        ]);

        $this->actingAs($user)
            ->post(route('spiritual.readings.toggle'), ['chapter_id' => $chapter->id])
            ->assertRedirect();

        $this->assertDatabaseHas('bible_reading_progress', [
            'user_id' => $user->id,
            'bible_reading_plan_day_chapter_id' => $chapter->id,
            'read_at' => null,
        ]);
    }

    public function test_reading_progress_can_be_marked_complete_and_percentage_calculates(): void
    {
        [$user] = $this->context();
        $this->seed(SpiritualBibleReadingPlanSeeder::class);
        $day = BibleReadingPlanDay::query()->where('day_number', 1)->with('chapters')->firstOrFail();

        foreach ($day->chapters as $chapter) {
            $this->actingAs($user)->post(route('spiritual.readings.toggle'), ['chapter_id' => $chapter->id]);
        }

        $this->actingAs($user)
            ->get(route('spiritual.index'))
            ->assertInertia(fn ($page) => $page
                ->where('today.completed_chapters', 14)
                ->where('today.status', 'completed')
                ->where('summary.percentage_complete', 1)
            );
    }

    public function test_notes_page_loads_and_handwritten_note_can_be_saved_with_links(): void
    {
        [$user, $workspace, , $area, $portfolio, $project] = $this->context();
        $this->seed(SpiritualBibleReadingPlanSeeder::class);
        $task = Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Linked note task',
            'status' => 'todo',
            'priority' => 'medium',
            'reporter_id' => $user->id,
        ]);
        $day = BibleReadingPlanDay::query()->firstOrFail();

        $this->actingAs($user)->get(route('notes.index'))->assertOk();

        $canvasData = json_encode([
            'version' => 1,
            'strokes' => [['color' => '#111827', 'size' => 4, 'points' => [['x' => 10, 'y' => 10], ['x' => 20, 'y' => 20]]]],
        ]);

        $this->actingAs($user)
            ->post(route('notes.store'), [
                'workspace_id' => $workspace->id,
                'area_id' => $area->id,
                'portfolio_id' => $portfolio->id,
                'project_id' => $project->id,
                'task_id' => $task->id,
                'spiritual_reading_day_id' => $day->id,
                'title' => 'Stylus note',
                'content' => 'Typed context',
                'canvas_data' => $canvasData,
                'note_type' => 'mixed',
                'tags' => 'prayer, study',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notes', [
            'user_id' => $user->id,
            'task_id' => $task->id,
            'project_id' => $project->id,
            'portfolio_id' => $portfolio->id,
            'title' => 'Stylus note',
            'canvas_data' => $canvasData,
        ]);
    }

    public function test_new_routes_exist_and_do_not_modify_existing_task_data(): void
    {
        [$user, $workspace, , $area, $portfolio, $project] = $this->context();
        $this->seed(SpiritualBibleReadingPlanSeeder::class);
        $task = Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Do not change me',
            'description' => 'Original',
            'status' => 'todo',
            'priority' => 'high',
            'reporter_id' => $user->id,
        ]);
        $original = $task->replicate()->toArray();
        $chapter = BibleReadingPlanDayChapter::query()->firstOrFail();

        $this->assertTrue(Route::has('spiritual.index'));
        $this->assertTrue(Route::has('notes.index'));

        $this->actingAs($user)->post(route('spiritual.readings.toggle'), ['chapter_id' => $chapter->id]);
        $this->actingAs($user)->post(route('notes.store'), [
            'workspace_id' => $workspace->id,
            'title' => 'Unlinked handwritten note',
            'canvas_data' => '{"version":1,"strokes":[]}',
            'note_type' => 'handwritten',
        ]);

        $this->assertSame($original['title'], $task->refresh()->title);
        $this->assertSame($original['description'], $task->description);
        $this->assertSame($original['status'], $task->status);
        $this->assertSame($original['priority'], $task->priority);
    }

    public function test_dashboard_exposes_today_spiritual_reading(): void
    {
        [$user] = $this->context();
        $this->seed(SpiritualBibleReadingPlanSeeder::class);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('spiritualReading.today_label', 'Genesis 1-14')
                ->where('spiritualReading.today_completed_chapters', 0)
                ->where('spiritualReading.today_total_chapters', 14)
                ->where('spiritualReading.status_label', 'On track')
            );
    }

    public function test_spiritual_summary_and_slack_briefing_include_missed_yesterday(): void
    {
        [$user, $workspace, , , , $project] = $this->context();
        $this->seed(SpiritualBibleReadingPlanSeeder::class);
        BibleReadingPlanDay::query()->where('day_number', 1)->update(['reading_date' => now()->subDay()->toDateString()]);
        BibleReadingPlanDay::query()->where('day_number', 2)->update(['reading_date' => now()->toDateString()]);
        $this->task($user, $workspace, $project);

        $summary = app(SpiritualReadingSummaryService::class)->forUser($user);

        $this->assertSame('Genesis 15-28', $summary['today_label']);
        $this->assertTrue($summary['missed_yesterday']);
        $this->assertSame('Genesis 1-14', $summary['missed_yesterday_label']);
        $this->assertSame('1 day behind', $summary['status_label']);

        $review = app(DailyReviewService::class)->createMorningReview($user);
        $briefing = app(DailyBriefingService::class)->build($review);
        $message = app(DailyBriefingService::class)->textMessage($briefing);

        $this->assertStringContainsString('Spiritual Reading', $message);
        $this->assertStringContainsString('Today: Genesis 15-28', $message);
        $this->assertStringContainsString('Yesterday missed: Genesis 1-14', $message);
        $this->assertStringContainsString('Suggested action: Read missed portion first, then continue today if time allows.', $message);
    }

    private function context(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Miriam Workspace', 'slug' => 'miriam-workspace', 'created_by' => $user->id]);
        $team = Team::create(['workspace_id' => $workspace->id, 'name' => 'Personal Team', 'slug' => 'personal-team']);
        $area = Area::create(['name' => 'Personal Foundation', 'slug' => 'personal-foundation', 'position' => 1, 'is_active' => true]);
        $portfolio = Portfolio::create(['area_id' => $area->id, 'workspace_id' => $workspace->id, 'name' => 'Spirituality', 'slug' => 'spirituality']);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'owner_id' => $user->id,
            'name' => 'Friday',
            'slug' => 'friday',
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($user->id, ['role' => 'lead', 'joined_at' => now()]);

        return [$user, $workspace, $team, $area, $portfolio, $project];
    }

    private function task(User $user, Workspace $workspace, Project $project, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Daily briefing task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            'due_date' => now()->toDateString(),
            ...$overrides,
        ]);
    }
}
