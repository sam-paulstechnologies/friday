<?php

namespace App\Http\Controllers;

use App\Models\DailyReview;
use App\Models\DailyReviewItem;
use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamPromptPhase;
use App\Models\MiriamReleasePackage;
use App\Models\MiriamSlackPendingConfirmation;
use App\Models\Task;
use App\Models\User;
use App\Services\DevelopmentFailureRecoveryService;
use App\Services\Ai\AiBrainService;
use App\Services\Ai\AiRecommendationService;
use App\Services\Ai\AiTranscriptionService;
use App\Services\MiriamAppRegistryService;
use App\Services\MiriamCodexOutputIngestionService;
use App\Services\MiriamDevelopmentLedgerService;
use App\Services\MiriamDevelopmentManagerService;
use App\Services\MiriamPromptQueueService;
use App\Services\MiriamReleasePackageService;
use App\Services\MiriamRunnerMonitoringService;
use App\Services\MiriamSprintPlanService;
use App\Services\Slack\SlackCommandParser;
use App\Services\Slack\MiriamSlackConversationService;
use App\Services\Slack\SlackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SlackWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        SlackService $slackService,
        SlackCommandParser $parser,
        AiBrainService $aiBrain,
        AiRecommendationService $recommendations,
        AiTranscriptionService $transcription,
        MiriamPromptQueueService $promptQueue,
        MiriamCodexOutputIngestionService $codexIngestion,
        MiriamDevelopmentManagerService $developmentManager,
        DevelopmentFailureRecoveryService $failureRecovery,
        MiriamAppRegistryService $appRegistry,
        MiriamReleasePackageService $releasePackages,
        MiriamRunnerMonitoringService $runnerMonitor,
        MiriamSprintPlanService $sprintPlan,
        MiriamSlackConversationService $conversation,
    ) {
        if (! $slackService->verifySignature($request)) {
            abort(403);
        }

        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        if ($this->isMiriamSlashCommand($request)) {
            return $this->handleSlashCommand($request, $parser, $slackService, $promptQueue, $codexIngestion, $developmentManager, $failureRecovery, $appRegistry, $releasePackages, $runnerMonitor, $sprintPlan);
        }

        if ($this->interactionPayload($request) !== null) {
            return $this->handleInteractionPayload($request, $parser, $failureRecovery, $developmentManager, $conversation);
        }

        $event = $request->input('event', []);
        $channel = $event['channel'] ?? null;
        $user = $event['user'] ?? null;
        $text = trim((string) ($event['text'] ?? ''));

        if (($event['bot_id'] ?? null) || ($event['subtype'] ?? null) === 'bot_message') {
            return response()->json(['ok' => true]);
        }

        if (config('services.slack.default_channel') && $channel !== config('services.slack.default_channel')) {
            Log::warning('Slack event ignored from unconfigured channel.', ['channel' => $channel]);

            return response()->json(['ok' => true]);
        }

        if (config('services.slack.allowed_user_id') && $user !== config('services.slack.allowed_user_id')) {
            Log::warning('Slack event ignored from unconfigured user.', ['user' => $user]);

            return response()->json(['ok' => true]);
        }

        Log::info('Slack daily review message received.', ['channel' => $channel, 'user' => $user, 'text' => $text]);

        $appUser = $this->resolveUser();

        if ($promptCommand = $parser->parseMiriamPromptCommand($text)) {
            $message = $this->handlePromptCommand($promptCommand, (string) $channel, (string) $user, $slackService, $promptQueue, $codexIngestion, $developmentManager, $failureRecovery, $appRegistry, $releasePackages, $runnerMonitor, $sprintPlan, $appUser);
            $slackService->sendMessage(
                $this->codexOutputChannel((string) $channel),
                is_array($message) ? $message['text'] : $message,
                is_array($message) ? ($message['blocks'] ?? []) : []
            );

            return response()->json(['ok' => true]);
        }

        if ($naturalCommand = $parser->parseMiriamNaturalLanguage($text)) {
            $message = $this->handleNaturalLanguageCommand($naturalCommand, (string) $channel, (string) $user, $conversation, $developmentManager, $appRegistry, $runnerMonitor, $sprintPlan, $appUser);
            $slackService->sendMessage(
                $this->codexOutputChannel((string) $channel),
                is_array($message) ? $message['text'] : $message,
                is_array($message) ? ($message['blocks'] ?? []) : []
            );

            return response()->json(['ok' => true]);
        }

        if ($approval = $this->parseAiApproval($text)) {
            $message = match ($approval['action']) {
                'show' => $recommendations->formatPending($appUser?->id),
                'approve' => $recommendations->applySelection($appUser?->id, $approval['selection']),
                'reject' => $recommendations->rejectSelection($appUser?->id, $approval['selection']),
            };

            $slackService->sendMessage((string) $channel, $message);

            return response()->json(['ok' => true]);
        }

        if ($voicePath = $this->downloadVoiceAttachment($event, $slackService)) {
            $transcribed = $transcription->transcribe($voicePath);

            if (! $transcribed) {
                $slackService->sendMessage((string) $channel, 'I could not transcribe the voice note. Please send it again or type the instruction.');

                return response()->json(['ok' => true]);
            }

            $slackService->sendMessage((string) $channel, 'I heard: '.$transcribed);
            $aiBrain->sendSlackAnswer((string) $channel, $transcribed, $appUser, $slackService, ['source' => 'slack_voice']);

            return response()->json(['ok' => true]);
        }

        if ($aiBrain->isAiPrompt($text)) {
            $aiBrain->sendSlackAnswer((string) $channel, $text, $appUser, $slackService, ['source' => 'slack_text']);

            return response()->json(['ok' => true]);
        }

        $command = $parser->parse($text);

        if ($command['action'] === 'help' || $command['action'] === 'unknown') {
            $slackService->sendMessage((string) $channel, $this->helpText());

            return response()->json(['ok' => true]);
        }

        $review = DailyReview::query()
            ->with(['items.task'])
            ->where('slack_channel_id', $channel)
            ->when(env('TASKFLOW_DAILY_USER_ID'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->whereIn('status', ['sent', 'pending', 'responded'])
            ->latest('sent_at')
            ->latest()
            ->first();

        if (! $review) {
            $slackService->sendMessage((string) $channel, 'No active Friday daily review was found for this channel.');

            return response()->json(['ok' => true]);
        }

        foreach ($command['numbers'] as $number) {
            $item = $review->items->firstWhere('position', $number);

            if (! $item || ! $item->task) {
                continue;
            }

            $this->applyCommand($command, $item);
        }

        $review->update([
            'status' => 'responded',
            'responded_at' => now(),
        ]);

        $slackService->sendMessage((string) $channel, 'Friday updated the matching review item(s).');

        return response()->json(['ok' => true]);
    }

    private function applyCommand(array $command, DailyReviewItem $item): void
    {
        $task = $item->task;
        $text = $command['text'];

        match ($command['action']) {
            'done' => $this->markDone($task, $item),
            'move' => $this->moveTask($task, $item, (string) $command['date']),
            'block' => $this->blockTask($task, $item, $text),
            'waiting' => $this->waitingTask($task, $item, $text),
            'note' => $this->noteTask($task, $item, $text),
            'skip' => $this->skipTask($task, $item),
            default => null,
        };
    }

    private function isMiriamSlashCommand(Request $request): bool
    {
        return $request->isMethod('post')
            && ($this->slashCommandInput($request)['command'] ?? null) === '/miriam'
            && $this->interactionPayload($request) === null;
    }

    private function handleSlashCommand(
        Request $request,
        SlackCommandParser $parser,
        SlackService $slackService,
        MiriamPromptQueueService $promptQueue,
        MiriamCodexOutputIngestionService $codexIngestion,
        MiriamDevelopmentManagerService $developmentManager,
        DevelopmentFailureRecoveryService $failureRecovery,
        MiriamAppRegistryService $appRegistry,
        MiriamReleasePackageService $releasePackages,
        MiriamRunnerMonitoringService $runnerMonitor,
        MiriamSprintPlanService $sprintPlan,
    ) {
        $input = $this->slashCommandInput($request);
        $channel = (string) ($input['channel_id'] ?? '');
        $user = (string) ($input['user_id'] ?? '');
        $text = trim((string) ($input['text'] ?? ''));

        if (config('services.slack.default_channel') && $channel !== config('services.slack.default_channel')) {
            Log::warning('Slack slash command ignored from unconfigured channel.', ['channel' => $channel]);

            return response()->json([
                'response_type' => 'ephemeral',
                'text' => 'This Miriam command is not enabled for this Slack channel.',
            ]);
        }

        if (config('services.slack.allowed_user_id') && $user !== config('services.slack.allowed_user_id')) {
            Log::warning('Slack slash command ignored from unconfigured user.', ['user' => $user]);

            return response()->json([
                'response_type' => 'ephemeral',
                'text' => 'You are not allowed to run this Miriam command.',
            ]);
        }

        $command = $parser->parseMiriamPromptCommand('/miriam '.$text);

        if (! $command) {
            return $this->slackCommandResponse($this->promptHelpText());
        }

        $message = $this->handlePromptCommand(
            $command,
            $channel,
            $user,
            $slackService,
            $promptQueue,
            $codexIngestion,
            $developmentManager,
            $failureRecovery,
            $appRegistry,
            $releasePackages,
            $runnerMonitor,
            $sprintPlan,
            $this->resolveUser()
        );

        return $this->slackCommandResponse($message);
    }

    private function slashCommandInput(Request $request): array
    {
        $input = $request->only(['command', 'text', 'channel_id', 'user_id']);

        if (($input['command'] ?? null) === '/miriam') {
            return $input;
        }

        parse_str($request->getContent(), $parsed);

        return array_merge($input, array_intersect_key($parsed, array_flip(['command', 'text', 'channel_id', 'user_id'])));
    }

    private function slackCommandResponse(string|array $message)
    {
        return response()->json([
            'response_type' => 'ephemeral',
            'text' => is_array($message) ? $message['text'] : $message,
            'blocks' => is_array($message) ? ($message['blocks'] ?? []) : [],
        ]);
    }

    private function handlePromptCommand(
        array $command,
        string $channel,
        string $slackUser,
        SlackService $slackService,
        MiriamPromptQueueService $promptQueue,
        MiriamCodexOutputIngestionService $codexIngestion,
        MiriamDevelopmentManagerService $developmentManager,
        DevelopmentFailureRecoveryService $failureRecovery,
        MiriamAppRegistryService $appRegistry,
        MiriamReleasePackageService $releasePackages,
        MiriamRunnerMonitoringService $runnerMonitor,
        MiriamSprintPlanService $sprintPlan,
        ?User $appUser,
    ): string|array {
        return match ($command['action']) {
            'apps' => $this->appsText($appRegistry),
            'app_status' => $this->appStatusText((string) ($command['app_slug'] ?? ''), $appRegistry),
            'app_open' => $this->appOpenText((string) ($command['app_slug'] ?? ''), $appRegistry),
            'app_health' => $this->appHealthText($appRegistry),
            'app_health_one' => $this->appHealthOneText((string) ($command['app_slug'] ?? ''), $appRegistry),
            'app_dry_run' => $this->appDryRunText((string) ($command['app_slug'] ?? ''), $developmentManager, $appUser),
            'app_validate' => $this->appValidateText((string) ($command['app_slug'] ?? ''), $developmentManager, $appUser),
            'releases' => $this->releasesText(),
            'release_status' => $this->releaseStatusText((int) ($command['release_package_id'] ?? 0)),
            'release_create_job' => $this->releaseCreateJobText((int) ($command['job_id'] ?? 0), $releasePackages, $appUser),
            'release_approve' => $this->releaseApproveText((int) ($command['release_package_id'] ?? 0), $releasePackages, $appUser),
            'release_reject' => $this->releaseRejectText((int) ($command['release_package_id'] ?? 0), $releasePackages, $appUser),
            'sprint_plan' => $sprintPlan->textSummary(),
            'next_recommendation' => $this->nextRecommendationText($runnerMonitor),
            'confirmation_required' => $this->confirmationRequiredText($command),
            'next_codex' => $this->deliverNextCodexPrompt($slackUser, $promptQueue),
            'dev_status' => $this->developmentStatusText($developmentManager),
            'dev_status_app' => $this->developmentStatusAppText((string) ($command['app_slug'] ?? '')),
            'dev_go' => $this->developmentGoText($developmentManager, $appUser),
            'dev_go_multi' => $this->developmentGoMultiText($developmentManager, $appUser),
            'dev_go_app' => $this->developmentGoAppText((string) ($command['app_slug'] ?? ''), $developmentManager, $appUser),
            'dev_stop' => $this->developmentStopText($developmentManager, $appUser),
            'dev_pause' => $this->developmentPauseText($developmentManager, $appUser),
            'dev_resume' => $this->developmentResumeText($developmentManager, $appUser),
            'dev_approve_job' => $this->developmentApproveJobText((int) ($command['job_id'] ?? 0), $developmentManager, $appUser),
            'dev_failures' => $this->developmentFailuresPayload(),
            'dev_monitor' => $runnerMonitor->textSummary(),
            'dev_summary' => $this->developmentMonitorSummaryText($runnerMonitor),
            'dev_blockers' => app(MiriamDevelopmentLedgerService::class)->blockersText(),
            'dev_next' => app(MiriamDevelopmentLedgerService::class)->nextText(),
            'dev_completed_today' => app(MiriamDevelopmentLedgerService::class)->completedTodayText(),
            'runner_status' => $runnerMonitor->runnerStatusText(),
            'runner_alerts' => $runnerMonitor->alertText(),
            'dev_apply_fix' => $this->developmentApplyFixText((int) ($command['failure_id'] ?? 0), $failureRecovery),
            'dev_show_error' => $this->developmentShowErrorText((int) ($command['failure_id'] ?? 0)),
            'dev_manual_fix', 'dev_need_user_at_system' => $this->developmentManualFixText((int) ($command['failure_id'] ?? 0), $failureRecovery),
            'dev_resume_after_manual_fix' => $this->developmentResumeAfterManualFixText((int) ($command['failure_id'] ?? 0), $failureRecovery),
            'dev_rollback_phase' => $this->developmentRollbackText((int) ($command['failure_id'] ?? 0), $failureRecovery),
            'dev_stop_job' => $this->developmentStopJobText((int) ($command['job_id'] ?? 0), $failureRecovery),
            'current_phase' => $this->currentPromptPhaseText($promptQueue),
            'codex_result' => $this->ingestCodexResult($command['output'] ?? '', $slackUser, $promptQueue, $codexIngestion, false),
            'codex_next_after_result' => $this->ingestCodexResult($command['output'] ?? '', $slackUser, $promptQueue, $codexIngestion, true),
            'prompts' => $this->promptQueueSummaryText($promptQueue),
            'prompt' => $this->savedPromptForPhaseText((string) ($command['phase_key'] ?? ''), $promptQueue),
            default => $this->promptHelpText(),
        };
    }

    private function handleNaturalLanguageCommand(
        array $intent,
        string $channel,
        string $slackUser,
        MiriamSlackConversationService $conversation,
        MiriamDevelopmentManagerService $developmentManager,
        MiriamAppRegistryService $appRegistry,
        MiriamRunnerMonitoringService $runnerMonitor,
        MiriamSprintPlanService $sprintPlan,
        ?User $appUser,
    ): string|array {
        if (($intent['requires_confirmation'] ?? false) === true) {
            $pending = $conversation->createPending($slackUser, $channel, $intent);

            return [
                'text' => $this->confirmationPromptText($intent),
                'blocks' => $conversation->confirmationBlocks($pending),
            ];
        }

        return match ($intent['intent'] ?? 'unknown') {
            'status_query' => $this->conversationStatusText($runnerMonitor, $appRegistry),
            'app_status_query' => $this->appStatusText((string) ($intent['app_slug'] ?? ''), $appRegistry),
            'blocker_query' => $this->conversationBlockerText($runnerMonitor),
            'next_action_query' => ($intent['action'] ?? null) === 'sprint_plan_query'
                ? $sprintPlan->textSummary()
                : $this->nextRecommendationText($runnerMonitor),
            'release_query' => $this->releasesText(),
            'demo_readiness_query' => $this->demoReadinessText($appRegistry),
            default => $this->clarifyingQuestionText(),
        };
    }

    private function handlePendingConfirmationInteraction(
        string $decision,
        int $confirmationId,
        string $slackUser,
        string $channel,
        MiriamSlackConversationService $conversation,
        MiriamDevelopmentManagerService $developmentManager,
    ): string {
        $pending = $conversation->pendingFor($confirmationId, $slackUser, $channel);

        if (! $pending) {
            return 'Confirmation was not found.';
        }

        if ($pending->status === 'expired') {
            return 'Confirmation expired. Send the request again when you are ready.';
        }

        if ($pending->status !== 'pending') {
            return 'Confirmation is already '.$pending->status.'.';
        }

        if ($decision === 'cancel') {
            $conversation->cancel($pending);

            return 'Cancelled.';
        }

        $message = $this->performConfirmedAction($pending, $developmentManager, $this->resolveUser());
        $conversation->confirm($pending);

        return $message;
    }

    private function performConfirmedAction(MiriamSlackPendingConfirmation $pending, MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        return match ($pending->intended_action) {
            'start_next_safe_task' => $this->developmentGoAppText((string) $pending->app_slug, $developmentManager, $appUser),
            'pause_all' => $this->developmentPauseText($developmentManager, $appUser),
            'resume_app' => $this->developmentResumeText($developmentManager, $appUser),
            default => 'That confirmed action is no longer supported. No Miriam state changed.',
        };
    }

    private function confirmationPromptText(array $intent): string
    {
        $action = $intent['action'] ?? 'change_state';
        $app = $intent['app_slug'] ?? null;

        if ($action === 'start_next_safe_task') {
            return 'I found the next safe '.($app ? str($app)->headline().' ' : '').'action: supervised demo polish/release package review. No deploy will run. Confirm?';
        }

        if ($action === 'pause_all') {
            return 'Pause active Miriam development jobs? No deploy, upload, shell command, or runner execution starts from this confirmation. Confirm?';
        }

        if ($action === 'resume_app') {
            return 'Resume the matching Miriam development job? No deploy, upload, or shell command starts from this confirmation. Confirm?';
        }

        return ($intent['reason'] ?? 'This request changes Miriam state.').' Confirm?';
    }

    private function conversationStatusText(MiriamRunnerMonitoringService $runnerMonitor, MiriamAppRegistryService $appRegistry): string
    {
        $summary = $runnerMonitor->summary();
        $onlineRunnerCount = (int) (($summary['runner_counts']['online'] ?? 0) + ($summary['runner_counts']['active'] ?? 0));
        $focus = collect($appRegistry->apps())
            ->filter(fn ($app) => (bool) ($app->config()['active_build_target'] ?? false))
            ->sortBy(fn ($app) => $app->config()['build_priority'] ?? 999)
            ->map(fn ($app) => $app->name)
            ->implode(' and ');

        return implode("\n", [
            '*Current status*',
            'Active jobs: '.$summary['active_job_count'].'.',
            'Active failures: '.$summary['active_failure_count'].'.',
            'Manual actions: '.$summary['manual_action_count'].'.',
            'Runner is '.($onlineRunnerCount > 0 ? 'online' : 'not online/recent heartbeat missing').'.',
            'Active priorities: '.($focus ?: 'ChurchForce and CatererHQ').'.',
        ]);
    }

    private function conversationBlockerText(MiriamRunnerMonitoringService $runnerMonitor): string
    {
        $summary = $runnerMonitor->summary();

        if ($summary['alerts'] === [] && $summary['active_failure_count'] === 0 && $summary['manual_action_count'] === 0) {
            return 'No active blockers. Last known focus: ChurchForce and CatererHQ demo readiness and release-package review.';
        }

        return $runnerMonitor->textSummary($summary);
    }

    private function demoReadinessText(MiriamAppRegistryService $appRegistry): string
    {
        $lines = ['*Demo readiness*'];

        foreach (['churchforce', 'catererhq'] as $slug) {
            $app = $appRegistry->resolve($slug);

            if (! $app) {
                $lines[] = "- `{$slug}`: not registered";
                continue;
            }

            $safe = $appRegistry->safeConfig($app);
            $focus = $safe['development_focus'];
            $health = $safe['app_health'];
            $lines[] = "- `{$slug}`: ".($focus['demo_readiness'] ?: $health['status']).' / next action='.($focus['next_action'] ?: 'local supervised review');
        }

        return implode("\n", $lines);
    }

    private function clarifyingQuestionText(): string
    {
        return 'I can help with status, blockers, app status, releases, demo readiness, or starting a safe app task. Which one did you mean?';
    }

    private function handleInteractionPayload(
        Request $request,
        SlackCommandParser $parser,
        DevelopmentFailureRecoveryService $failureRecovery,
        MiriamDevelopmentManagerService $developmentManager,
        MiriamSlackConversationService $conversation,
    ) {
        $payload = json_decode((string) $this->interactionPayload($request), true) ?: [];
        $channel = (string) data_get($payload, 'channel.id', '');
        $user = (string) data_get($payload, 'user.id', '');
        $action = collect($payload['actions'] ?? [])->first();
        $value = (string) (($action['value'] ?? null) ?: ($action['action_id'] ?? ''));

        if (config('services.slack.default_channel') && $channel !== config('services.slack.default_channel')) {
            Log::warning('Slack interaction ignored from unconfigured channel.', ['channel' => $channel]);

            return response()->json(['response_type' => 'ephemeral', 'text' => 'This Miriam action is not enabled for this Slack channel.']);
        }

        if (config('services.slack.allowed_user_id') && $user !== config('services.slack.allowed_user_id')) {
            Log::warning('Slack interaction ignored from unconfigured user.', ['user' => $user]);

            return response()->json(['response_type' => 'ephemeral', 'text' => 'You are not allowed to run this Miriam action.']);
        }

        if (preg_match('/^miriam_(confirm|cancel):(\d+)$/', $value, $matches)) {
            $message = $this->handlePendingConfirmationInteraction(
                $matches[1],
                (int) $matches[2],
                $user,
                $channel,
                $conversation,
                $developmentManager
            );

            return response()->json([
                'response_type' => 'ephemeral',
                'replace_original' => false,
                'text' => $message,
            ]);
        }

        $command = $parser->parseMiriamPromptCommand('/miriam '.$value);

        if (! $command) {
            return response()->json(['response_type' => 'ephemeral', 'text' => 'Unknown Miriam interaction.']);
        }

        $message = match ($command['action']) {
            'dev_approve_job' => $this->developmentApproveJobText((int) ($command['job_id'] ?? 0), $developmentManager, $this->resolveUser()),
            'dev_apply_fix' => $this->developmentApplyFixText((int) ($command['failure_id'] ?? 0), $failureRecovery),
            'dev_show_error' => $this->developmentShowErrorText((int) ($command['failure_id'] ?? 0)),
            'dev_manual_fix', 'dev_need_user_at_system' => $this->developmentManualFixText((int) ($command['failure_id'] ?? 0), $failureRecovery),
            'dev_resume_after_manual_fix' => $this->developmentResumeAfterManualFixText((int) ($command['failure_id'] ?? 0), $failureRecovery),
            'dev_rollback_phase' => $this->developmentRollbackText((int) ($command['failure_id'] ?? 0), $failureRecovery),
            'dev_stop_job' => $this->developmentStopJobText((int) ($command['job_id'] ?? 0), $failureRecovery),
            default => 'This Miriam interaction is not a recovery action.',
        };

        return response()->json([
            'response_type' => 'ephemeral',
            'replace_original' => false,
            'text' => $message,
        ]);
    }

    private function interactionPayload(Request $request): ?string
    {
        if ($request->filled('payload')) {
            return (string) $request->input('payload');
        }

        parse_str($request->getContent(), $parsed);

        return isset($parsed['payload']) ? (string) $parsed['payload'] : null;
    }

    private function deliverNextCodexPrompt(string $slackUser, MiriamPromptQueueService $promptQueue): string
    {
        $prompt = $promptQueue->nextSavedPrompt();

        if (! $prompt) {
            return 'No ready Miriam Codex prompt was found. Run `php artisan miriam:seed-prompt-program` or mark a phase ready.';
        }

        $run = $promptQueue->deliverPromptIfNeeded($prompt, 'slack', $slackUser);
        $promptQueue->markDelivery($run, 'slack', $slackUser);

        return $this->promptDeliveryText($run->prompt_body, $prompt->title, $run->run_number);
    }

    private function developmentStatusText(MiriamDevelopmentManagerService $developmentManager): string
    {
        $summary = $developmentManager->statusSummary();
        /** @var \App\Models\MiriamDevelopmentJob|null $latest */
        $latest = $summary['latest_job'];
        $lastEvent = $summary['last_event'];
        $latestRunner = $summary['latest_runner'];
        $latestDryRun = $summary['latest_dry_run_event'];
        $latestOnePhase = $summary['latest_one_phase_event'];
        $latestMultiPhase = $summary['latest_multi_phase_event'] ?? null;
        $latestAppDryRun = $summary['latest_app_dry_run_event'] ?? null;
        $latestAppValidation = $summary['latest_app_validation_event'] ?? null;
        $latestFailure = $summary['latest_failure'] ?? null;
        $connected = $latestRunner?->last_seen_at && $latestRunner->last_seen_at->greaterThan(now()->subMinutes(5));
        $onePhaseMeta = $latestOnePhase?->meta() ?? [];
        $multiPhaseMeta = $latestMultiPhase?->meta() ?? [];
        $validation = $onePhaseMeta['validation'] ?? [];
        $latestOptions = $latest?->options() ?? [];

        return implode("\n", [
            '*Miriam Development Manager status*',
            'Active runners: '.$summary['active_runner_count'],
            'Local runner connected: '.($connected ? 'yes' : 'no/recent heartbeat missing'),
            'Latest runner heartbeat: '.($latestRunner?->last_seen_at?->toDateTimeString() ?? 'none'),
            'Latest job: '.($latest ? "#{$latest->id} / {$latest->status} / {$latest->title}" : 'none'),
            'Current phase: '.($latest?->currentPhase ? "{$latest->currentPhase->phase_key} — {$latest->currentPhase->title}" : 'N/A'),
            'Last event: '.($lastEvent ? "{$lastEvent->title} ({$lastEvent->created_at->toDateTimeString()})" : 'none'),
            'Latest dry-run: '.($latestDryRun ? "{$latestDryRun->title} ({$latestDryRun->created_at->toDateTimeString()})" : 'none'),
            'Latest one-phase execution: '.($latestOnePhase ? "{$latestOnePhase->title} ({$latestOnePhase->created_at->toDateTimeString()})" : 'none'),
            'Latest controlled multi-phase event: '.($latestMultiPhase ? "{$latestMultiPhase->title} ({$latestMultiPhase->created_at->toDateTimeString()})" : 'none'),
            'Latest app dry-run: '.($latestAppDryRun ? "{$latestAppDryRun->title} ({$latestAppDryRun->created_at->toDateTimeString()})" : 'none'),
            'Latest app validation-only: '.($latestAppValidation ? "{$latestAppValidation->title} ({$latestAppValidation->created_at->toDateTimeString()})" : 'none'),
            'Validation: '.($validation === [] ? 'none' : collect($validation)->map(fn ($value, $key) => "{$key}={$value}")->implode(', ')),
            'Changed files: '.(int) ($onePhaseMeta['changed_files_count'] ?? 0),
            'Safety scanner: '.(string) ($onePhaseMeta['safety_scanner'] ?? 'none'),
            'Phase progress: '.($latest ? "{$latest->completed_phases}/{$latest->total_phases}" : 'N/A'),
            'Multi-phase mode: '.(($latest?->run_mode === 'controlled_multi_phase' || ($latestOptions['multi_phase_enabled'] ?? false)) ? 'enabled for latest job' : 'disabled/not latest'),
            'Last multi-phase result: '.($latestMultiPhase ? (($multiPhaseMeta['parser_status'] ?? 'unknown').' / safety='.($multiPhaseMeta['safety_scanner'] ?? 'unknown')) : 'none'),
            'Active failures: '.(int) ($summary['active_failure_count'] ?? 0),
            'Latest failure: '.($latestFailure ? "#{$latestFailure->id} / {$latestFailure->failure_type} / {$latestFailure->status} / {$latestFailure->title}" : 'none'),
            'User needed at system: '.($latestFailure?->needs_user_at_system ? 'yes' : 'no'),
            'Waiting for real execution: '.($latest && in_array($latest->status, ['waiting_for_manual_fix', 'waiting_for_approval'], true) ? 'yes' : 'no'),
            'Paused after one phase: '.($latestOnePhase ? 'yes' : 'no'),
            'Paused or completed: '.($latest ? (in_array($latest->status, ['paused', 'completed'], true) ? 'yes' : 'no') : 'N/A'),
            'Note: Slack only changes cloud-side job state. It does not run shell commands.',
        ]);
    }

    private function developmentMonitorSummaryText(MiriamRunnerMonitoringService $runnerMonitor): string
    {
        $summary = $runnerMonitor->summary();
        $latestPackage = $summary['latest_package'];

        return implode("\n", [
            '*Miriam Development Manager summary*',
            'Overall: '.$summary['overall_status'],
            'Runners: '.$summary['runner_count'].' / '.collect($summary['runner_counts'])->map(fn ($count, $state) => "{$state}={$count}")->implode(', '),
            'Active jobs: '.$summary['active_job_count'],
            'Manual actions: '.$summary['manual_action_count'],
            'Pending approvals: '.$summary['pending_release_approval_count'],
            'Latest package: '.($latestPackage ? "#{$latestPackage['id']} / {$latestPackage['status']} / approval=".($latestPackage['approval_status'] ?? 'none') : 'none'),
            'Alerts: '.($summary['alerts'] === [] ? 'none' : count($summary['alerts'])),
            'Notification mode: quiet development mode; Slack interrupts only for safety gates, blockers, release approval, and final summaries.',
            'Open: '.route('product-brain.development-manager.index'),
        ]);
    }

    private function appsText(MiriamAppRegistryService $appRegistry): string
    {
        $apps = $appRegistry->apps();

        if ($apps->isEmpty()) {
            return 'No Miriam managed apps are registered. Run `php artisan miriam:apps:seed-defaults`.';
        }

        return "*Miriam managed apps*\n".$apps
            ->map(function ($app) use ($appRegistry): string {
                $health = $appRegistry->safeConfig($app)['app_health'];
                $ready = $health['runnable'] ? $health['status'] : $health['status'].' missing '.implode(', ', $health['missing']);

                return "- `{$app->slug}`: {$app->name} / {$app->status} / {$app->tech_stack} / {$ready}";
            })
            ->implode("\n");
    }

    private function appStatusText(string $slug, MiriamAppRegistryService $appRegistry): string
    {
        $app = $appRegistry->resolve($slug);

        if (! $app) {
            return "No managed app found for `{$slug}`.";
        }

        $safe = $appRegistry->safeConfig($app);
        $health = $safe['app_health'];
        $dashboard = app(MiriamDevelopmentLedgerService::class)->appDashboard($app);

        return implode("\n", [
            "*Miriam app status: {$app->name}*",
            "Slug: `{$app->slug}`",
            "Status: {$app->status}",
            "Tech stack: ".($app->tech_stack ?: 'N/A'),
            "Local path: ".($app->local_project_path ?: 'missing'),
            "Local URL: ".($app->local_url ?: 'N/A'),
            "Prompt program: ".($safe['prompt_program']['slug'] ?? 'missing'),
            "Runner: ".($safe['runner']['name'] ?? 'auto/none'),
            "Validation profile: ".($safe['validation_profile']['slug'] ?? 'missing'),
            "Folder exists: ".($health['folder_exists'] ? 'yes' : 'no/not checked'),
            "Health: {$health['status']}",
            "Runnable: ".($health['runnable'] ? 'yes' : 'no'),
            "Missing: ".($health['missing'] === [] ? 'none' : implode(', ', $health['missing'])),
            "Master vision: ".($dashboard['master_vision'] ?: 'not recorded'),
            "Completed work: ".(count($dashboard['completed_work']) ?: 0).' ledger item(s)',
            "Current work: ".(count($dashboard['current_work']) ?: 0).' ledger item(s)',
            "Due next: ".$dashboard['due_next'],
            "Blockers: ".(count($dashboard['blockers']) ?: 'none'),
            "Latest commit: ".($dashboard['latest_commit'] ?: 'none recorded'),
            "Demo readiness: ".$dashboard['demo_readiness'],
            "Production readiness: ".$dashboard['production_readiness'],
        ]);
    }

    private function appHealthText(MiriamAppRegistryService $appRegistry): string
    {
        $lines = ['*Miriam app health*'];

        foreach ($appRegistry->apps() as $app) {
            $safe = $appRegistry->safeConfig($app);
            $health = $safe['app_health'];
            $validation = $safe['latest_validation'];
            $focus = $safe['development_focus'];
            $target = $focus['active_build_target'] ? 'active priority '.$focus['build_priority'] : ($focus['queue_status'] ?: 'not active');
            $lines[] = "- `{$app->slug}`: {$health['status']} / {$target} / runnable=".($health['runnable'] ? 'yes' : 'no').' / latest validation='.($validation ? "#{$validation['id']} {$validation['status']}" : 'none').($health['missing'] ? ' / missing '.implode(', ', $health['missing']) : '');
        }

        return implode("\n", $lines);
    }

    private function nextRecommendationText(MiriamRunnerMonitoringService $runnerMonitor): string
    {
        $summary = $runnerMonitor->summary();

        if ($summary['active_failure_count'] > 0 || $summary['manual_action_count'] > 0) {
            return $runnerMonitor->textSummary($summary);
        }

        return implode("\n", [
            '*Miriam next recommended action*',
            'Use ChurchForce first, then CatererHQ: finish supervised local demo QA, then request manual release package review when validation is clean.',
            'Natural-language actions that change state require Confirm / Cancel before Miriam creates or resumes a job.',
        ]);
    }

    private function confirmationRequiredText(array $command): string
    {
        return implode("\n", [
            '*Confirmation required*',
            $command['reason'] ?? 'This request changes Miriam state.',
            'Run this exact command to confirm: `'.($command['command'] ?? '/miriam help').'`',
        ]);
    }

    private function appHealthOneText(string $slug, MiriamAppRegistryService $appRegistry): string
    {
        return $this->appStatusText($slug, $appRegistry);
    }

    private function appDryRunText(string $slug, MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        $job = $developmentManager->startAppDryRun($slug, $appUser, [
            'source' => 'slack',
            'no_git_primary_workflow' => true,
        ]);

        return implode("\n", [
            '*Miriam app dry-run job created*',
            "App: {$job->managedApp?->name} (`{$job->managedApp?->slug}`)",
            "Job: #{$job->id} / {$job->status}",
            "Path snapshot: {$job->local_project_path_snapshot}",
            'The runner will verify app context and stop before Codex or validation.',
            'No shell command was run from Slack.',
        ]);
    }

    private function appValidateText(string $slug, MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        $job = $developmentManager->startAppValidationOnly($slug, $appUser, [
            'source' => 'slack',
            'no_git_primary_workflow' => true,
        ]);

        return implode("\n", [
            '*Miriam app validation-only job created*',
            "App: {$job->managedApp?->name} (`{$job->managedApp?->slug}`)",
            "Job: #{$job->id} / {$job->status}",
            "Path snapshot: {$job->local_project_path_snapshot}",
            'The runner will run configured validation commands and stop. Codex execution is skipped.',
            'No shell command was run from Slack.',
        ]);
    }

    private function appOpenText(string $slug, MiriamAppRegistryService $appRegistry): string
    {
        $app = $appRegistry->resolve($slug);

        if (! $app) {
            return "No managed app found for `{$slug}`.";
        }

        return implode("\n", array_filter([
            "*Miriam app links: {$app->name}*",
            $app->local_url ? "Local: {$app->local_url}" : null,
            $app->cloud_url ? "Cloud: {$app->cloud_url}" : null,
            $app->super_admin_url ? "Super admin: {$app->super_admin_url}" : null,
            'No credentials are stored or shown.',
        ]));
    }

    private function developmentStatusAppText(string $slug): string
    {
        $job = MiriamDevelopmentJob::query()
            ->with(['managedApp', 'currentPhase'])
            ->whereHas('managedApp', fn ($query) => $query->where('slug', $slug))
            ->latest()
            ->first();

        if (! $job) {
            return "No Miriam development job found for app `{$slug}`.";
        }

        return implode("\n", [
            "*Miriam development status for {$job->managedApp?->name}*",
            "Job: #{$job->id} / {$job->status}",
            "Progress: {$job->completed_phases}/{$job->total_phases}",
            "Current phase: ".($job->currentPhase?->phase_key ?? 'N/A'),
            "Local path snapshot: ".($job->local_project_path_snapshot ?: 'N/A'),
            "Error: ".($job->error_message ?: 'none'),
            "Recommended action: ".$this->developmentJobRecommendedAction($job),
        ]);
    }

    private function developmentJobRecommendedAction(MiriamDevelopmentJob $job): string
    {
        return match ($job->status) {
            'waiting_for_approval' => 'Review the job in Development Manager before continuing.',
            'waiting_for_manual_fix' => 'Use `/miriam dev failures`, Show Error, then Resume After Manual Fix / Validate or Stop Job.',
            'blocked', 'failed' => 'Open Development Manager and resolve the gate before retrying.',
            'waiting_for_runner' => 'Start/check the local runner and run `/miriam runner status`.',
            default => 'No manual action needed right now.',
        };
    }

    private function developmentGoText(MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        $job = $developmentManager->startJobFromActiveProgram($appUser, [
            'source' => 'slack',
            'no_git_primary_workflow' => true,
            'local_runner_not_implemented' => true,
        ]);

        return implode("\n", [
            '*Miriam development job created*',
            "Job: #{$job->id} / {$job->status}",
            "Phases prepared: {$job->total_phases}",
            'Job created. Waiting for local runner.',
            'No Codex execution, shell command, Git action, migration, or deploy was started.',
        ]);
    }

    private function developmentGoMultiText(MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        $job = $developmentManager->startJobFromActiveProgram($appUser, [
            'source' => 'slack',
            'run_mode' => 'controlled_multi_phase',
            'multi_phase_enabled' => true,
            'no_git_primary_workflow' => true,
            'stop_on_failure' => true,
            'stop_on_safety_risk' => true,
            'stop_on_parser_unclear' => true,
            'stop_on_manual_approval' => true,
        ]);

        return implode("\n", [
            '*Controlled Miriam multi-phase job created*',
            "Job: #{$job->id} / {$job->status}",
            "Phases prepared: {$job->total_phases}",
            'The local runner may execute multiple phases only if its local config explicitly enables multi_phase_enabled.',
            'It will stop on failure, safety risk, unclear parser result, approval gate, or max phase limit.',
            'No shell command was run from Slack.',
        ]);
    }

    private function developmentGoAppText(string $slug, MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        $job = $developmentManager->startJobForApp($slug, $appUser, [
            'source' => 'slack',
            'no_git_primary_workflow' => true,
        ]);

        return implode("\n", [
            '*Miriam app development job created*',
            "App: {$job->managedApp?->name} (`{$job->managedApp?->slug}`)",
            "Job: #{$job->id} / {$job->status}",
            "Path snapshot: {$job->local_project_path_snapshot}",
            "Phases prepared: {$job->total_phases}",
            'No shell command was run from Slack.',
        ]);
    }

    private function releasesText(): string
    {
        $packages = MiriamReleasePackage::query()
            ->with(['managedApp', 'approvals'])
            ->latest()
            ->limit(8)
            ->get();

        if ($packages->isEmpty()) {
            return 'No Miriam release packages exist yet.';
        }

        return "*Miriam release packages*\n".$packages
            ->map(fn (MiriamReleasePackage $package) => "- #{$package->id}: {$package->managedApp?->slug} / {$package->status} / approval=".($package->latestApproval()?->status ?? 'none').' / manual deploy only')
            ->implode("\n");
    }

    private function releaseStatusText(int $packageId): string
    {
        $package = MiriamReleasePackage::with(['managedApp', 'developmentJob', 'approvals'])->find($packageId);

        if (! $package) {
            return "No release package found for #{$packageId}.";
        }

        $checklist = collect(app(MiriamReleasePackageService::class)
            ->qaChecklist($package))
            ->map(fn (array $item) => "- {$item['label']}: {$item['status']} ({$item['detail']})")
            ->implode("\n");

        return implode("\n", [
            "*Miriam release package #{$package->id}*",
            'App: '.($package->managedApp?->name ?? 'N/A'),
            "Status: {$package->status}",
            'Approval: '.($package->latestApproval()?->status ?? 'none'),
            'Package path: '.($package->package_path ?: 'not packaged yet'),
            'Package size: '.($package->package_size_bytes ?? 'N/A'),
            'Packaged at: '.($package->packaged_at?->toDateTimeString() ?? 'N/A'),
            "QA checklist:\n{$checklist}",
            'Deployment: not automated. Approval means approved for manual deployment only.',
            'Error: '.($package->error_message ?: 'none'),
        ]);
    }

    private function releaseCreateJobText(int $jobId, MiriamReleasePackageService $releasePackages, ?User $appUser): string
    {
        $job = MiriamDevelopmentJob::with(['managedApp', 'runnerAgent'])->find($jobId);

        if (! $job) {
            return "No development job found for #{$jobId}.";
        }

        $package = $releasePackages->requestForJob($job, $appUser);

        return implode("\n", [
            '*Miriam release package requested*',
            "Package: #{$package->id} / {$package->status}",
            'App: '.($package->managedApp?->name ?? 'N/A'),
            'Runner: '.($package->runnerAgent?->name ?? 'N/A'),
            'Deployment was not started. The runner will only create a package, then approval is required for manual deployment.',
        ]);
    }

    private function releaseApproveText(int $packageId, MiriamReleasePackageService $releasePackages, ?User $appUser): string
    {
        $package = MiriamReleasePackage::find($packageId);

        if (! $package) {
            return "No release package found for #{$packageId}.";
        }

        $updated = $releasePackages->approve($package, $appUser, 'Approved from Slack for manual deployment only.');

        return "Release package #{$updated->id} approved for manual deployment only. Miriam did not deploy it.";
    }

    private function releaseRejectText(int $packageId, MiriamReleasePackageService $releasePackages, ?User $appUser): string
    {
        $package = MiriamReleasePackage::find($packageId);

        if (! $package) {
            return "No release package found for #{$packageId}.";
        }

        $updated = $releasePackages->reject($package, $appUser, 'Rejected from Slack.');

        return "Release package #{$updated->id} rejected. Miriam did not deploy anything.";
    }

    private function developmentStopText(MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        $job = MiriamDevelopmentJob::query()
            ->whereIn('status', ['queued', 'waiting_for_runner'])
            ->latest()
            ->first();

        if (! $job) {
            return 'No queued or waiting Miriam development job was found to cancel.';
        }

        $updated = $developmentManager->cancelQueuedJob($job, $appUser);

        return "Miriam development job #{$updated->id} cancelled. No runner execution was stopped because local runner execution is not implemented yet.";
    }

    private function developmentPauseText(MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        $job = MiriamDevelopmentJob::query()
            ->whereIn('status', ['queued', 'waiting_for_runner', 'running'])
            ->latest()
            ->first();

        if (! $job) {
            return 'No queued, waiting, or running Miriam development job was found to pause.';
        }

        $updated = $developmentManager->pauseJob($job, $appUser);

        return "Miriam development job #{$updated->id} paused. The runner will not receive another phase until resumed.";
    }

    private function developmentResumeText(MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        $job = MiriamDevelopmentJob::query()
            ->where('status', 'paused')
            ->latest()
            ->first();

        if (! $job) {
            return 'No paused Miriam development job was found to resume.';
        }

        $updated = $developmentManager->resumeJob($job, $appUser);

        return "Miriam development job #{$updated->id} resumed as {$updated->status}. It remains gated by validation, safety scan, parser clarity, and failure checks.";
    }

    private function developmentApproveJobText(int $jobId, MiriamDevelopmentManagerService $developmentManager, ?User $appUser): string
    {
        $job = MiriamDevelopmentJob::find($jobId);

        if (! $job) {
            return "No development job found for #{$jobId}.";
        }

        try {
            $updated = $developmentManager->approveWaitingJob($job, $appUser);
        } catch (\Throwable $exception) {
            return "Job #{$job->id} was not approved: {$exception->getMessage()}";
        }

        if ($updated->status === 'completed') {
            return "Job #{$updated->id} approved and completed.";
        }

        return "Job #{$updated->id} approved. Current status: {$updated->status}. No shell command was run from Slack.";
    }

    private function developmentFailuresPayload(): array
    {
        $failures = MiriamDevelopmentFailure::query()
            ->with(['job', 'phaseRun.phase'])
            ->whereIn('status', ['open', 'fix_requested', 'fixing', 'manual_attention_required', 'failed'])
            ->latest()
            ->limit(5)
            ->get();

        if ($failures->isEmpty()) {
            return ['text' => 'No active Miriam development failures are open.', 'blocks' => []];
        }

        return [
            'text' => $failures->map(fn (MiriamDevelopmentFailure $failure) => $this->failureSummaryText($failure))->implode("\n\n"),
            'blocks' => $failures->flatMap(fn (MiriamDevelopmentFailure $failure) => $this->failureActionBlocks($failure))->values()->all(),
        ];
    }

    private function developmentApplyFixText(int $failureId, DevelopmentFailureRecoveryService $failureRecovery): string
    {
        $failure = MiriamDevelopmentFailure::find($failureId);

        if (! $failure) {
            return "No failure found for #{$failureId}.";
        }

        $attempt = $failureRecovery->applyFix($failure);

        return "Apply Fix queued for failure #{$failure->id}. Fix attempt #{$attempt->attempt_number} is waiting for the local runner. Miriam will not continue to the next phase automatically.";
    }

    private function developmentShowErrorText(int $failureId): string
    {
        $failure = MiriamDevelopmentFailure::with(['job', 'phaseRun.phase'])->find($failureId);

        if (! $failure) {
            return "No failure found for #{$failureId}.";
        }

        return $this->failureSummaryText($failure)."\n\nError excerpt:\n```".str($failure->error_excerpt ?: 'No error excerpt stored.')->limit(2800)."```";
    }

    private function developmentManualFixText(int $failureId, DevelopmentFailureRecoveryService $failureRecovery): string
    {
        $failure = MiriamDevelopmentFailure::find($failureId);

        if (! $failure) {
            return "No failure found for #{$failureId}.";
        }

        $updated = $failureRecovery->markManualAttentionRequired($failure);
        $call = config('services.slack.call_escalation_url') ?: env('CALL_ESCALATION_URL');

        return implode("\n", array_filter([
            "Manual attention marked for failure #{$updated->id}.",
            'Go to the local system, fix the issue, then use `/miriam dev resume after manual fix '.$updated->id.'`.',
            $call ? "Optional call link: {$call}" : null,
        ]));
    }

    private function developmentResumeAfterManualFixText(int $failureId, DevelopmentFailureRecoveryService $failureRecovery): string
    {
        $failure = MiriamDevelopmentFailure::find($failureId);

        if (! $failure) {
            return "No failure found for #{$failureId}.";
        }

        $failureRecovery->resumeAfterManualFix($failure);

        return "Manual validation requested for failure #{$failure->id}. The runner will validate only this phase and stop.";
    }

    private function developmentRollbackText(int $failureId, DevelopmentFailureRecoveryService $failureRecovery): string
    {
        $failure = MiriamDevelopmentFailure::find($failureId);

        if (! $failure) {
            return "No failure found for #{$failureId}.";
        }

        $failureRecovery->requestRollback($failure);

        return "Rollback requested for failure #{$failure->id}. The runner must explicitly perform rollback and stop; no next phase will run.";
    }

    private function developmentStopJobText(int $jobId, DevelopmentFailureRecoveryService $failureRecovery): string
    {
        $job = MiriamDevelopmentJob::find($jobId);

        if (! $job) {
            return "No development job found for #{$jobId}.";
        }

        $updated = $failureRecovery->stopJob($job);

        return "Development job #{$updated->id} stopped safely. No Codex execution or next phase was started from Slack.";
    }

    private function failureSummaryText(MiriamDevelopmentFailure $failure): string
    {
        $jobWaitingForApproval = $failure->job?->status === 'waiting_for_approval';
        $actions = [
            "`/miriam dev show error {$failure->id}`",
            $failure->can_auto_fix ? "`/miriam dev apply fix {$failure->id}`" : null,
            "`/miriam dev manual fix {$failure->id}`",
            $jobWaitingForApproval && $failure->job ? "`/miriam dev approve job {$failure->job->id}`" : "`/miriam dev resume after manual fix {$failure->id}`",
            "`/miriam dev rollback phase {$failure->id}`",
            $failure->job ? "`/miriam dev stop job {$failure->job->id}`" : null,
        ];

        return implode("\n", [
            '*Miriam development failure*',
            "Failure: #{$failure->id} / {$failure->failure_type} / {$failure->severity} / {$failure->status}",
            'Job: '.($failure->job ? "#{$failure->job->id} / {$failure->job->status}" : 'N/A'),
            'Phase: '.($failure->phaseRun?->phase ? "{$failure->phaseRun->phase->phase_key} - {$failure->phaseRun->phase->title}" : 'N/A'),
            "Summary: ".($failure->summary ?: $failure->title),
            'Auto-fix available: '.($failure->can_auto_fix ? 'yes' : 'no'),
            'User needed at system: '.($failure->needs_user_at_system ? 'yes' : 'no'),
            'Actions: '.collect($actions)->filter()->implode(' | '),
        ]);
    }

    private function failureActionBlocks(MiriamDevelopmentFailure $failure): array
    {
        $elements = [
            [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Show Error'],
                'action_id' => "dev_show_error:{$failure->id}",
                'value' => "dev_show_error:{$failure->id}",
            ],
            [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Manual Fix'],
                'action_id' => "dev_manual_fix:{$failure->id}",
                'value' => "dev_manual_fix:{$failure->id}",
            ],
            [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Rollback'],
                'style' => 'danger',
                'action_id' => "dev_rollback_phase:{$failure->id}",
                'value' => "dev_rollback_phase:{$failure->id}",
                'confirm' => [
                    'title' => ['type' => 'plain_text', 'text' => 'Rollback phase?'],
                    'text' => ['type' => 'mrkdwn', 'text' => 'This only requests a rollback instruction. It will not continue to another phase.'],
                    'confirm' => ['type' => 'plain_text', 'text' => 'Request rollback'],
                    'deny' => ['type' => 'plain_text', 'text' => 'Cancel'],
                ],
            ],
        ];

        if ($failure->job?->status === 'waiting_for_approval') {
            $elements[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Approve / Complete'],
                'style' => 'primary',
                'action_id' => "dev_approve_job:{$failure->job->id}",
                'value' => "dev_approve_job:{$failure->job->id}",
            ];
        } else {
            $elements[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Resume'],
                'action_id' => "dev_resume_after_manual_fix:{$failure->id}",
                'value' => "dev_resume_after_manual_fix:{$failure->id}",
            ];
        }

        if ($failure->can_auto_fix) {
            array_unshift($elements, [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Apply Fix'],
                'style' => 'primary',
                'action_id' => "dev_apply_fix:{$failure->id}",
                'value' => "dev_apply_fix:{$failure->id}",
            ]);
        }

        if ($failure->job) {
            $elements[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Stop Job'],
                'style' => 'danger',
                'action_id' => "dev_stop_job:{$failure->job->id}",
                'value' => "dev_stop_job:{$failure->job->id}",
            ];
        }

        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Failure #{$failure->id}: {$failure->failure_type} / {$failure->status}*\n".str($failure->summary ?: $failure->title)->limit(280),
                ],
            ],
            [
                'type' => 'actions',
                'elements' => $elements,
            ],
        ];
    }

    private function currentPromptPhaseText(MiriamPromptQueueService $promptQueue): string
    {
        $program = $promptQueue->activeProgram();
        $phase = $promptQueue->currentPhase($program) ?? $promptQueue->nextReadyPhase($program);
        $lastRun = $program?->codexRuns()->latest()->first();

        if (! $program || ! $phase) {
            return 'No active Miriam prompt phase was found.';
        }

        return implode("\n", [
            '*Miriam current phase*',
            "Program: {$program->name}",
            "Phase: {$phase->phase_key} — {$phase->title}",
            "Status: {$phase->status}",
            'Last run: '.($lastRun ? "#{$lastRun->run_number} / {$lastRun->status}" : 'none'),
            'Next action: '.match ($phase->status) {
                'blocked' => 'Review blocker before requesting another prompt.',
                'review_required' => 'Review the last Codex output before moving forward.',
                'passed' => 'Request the next ready Codex prompt.',
                default => 'Use `/miriam next codex` when ready.',
            },
        ]);
    }

    private function ingestCodexResult(
        string $output,
        string $slackUser,
        MiriamPromptQueueService $promptQueue,
        MiriamCodexOutputIngestionService $codexIngestion,
        bool $deliverNext,
    ): string {
        if (trim($output) === '') {
            return 'Paste the Codex output after the command, for example `/miriam codex result MIRIAM_RESULT_JSON: ...`.';
        }

        $run = $promptQueue->latestRunForRecipient($slackUser);

        if (! $run) {
            return 'No delivered Miriam Codex run was found for your Slack user.';
        }

        $updated = $codexIngestion->ingest($run, $output);
        $summary = $codexIngestion->summary($updated);

        $lines = [
            '*Miriam Codex result parsed*',
            "Status: {$updated->status}",
            'Phase: '.($summary['phase_key'] ?? 'N/A'),
            'Files changed: '.$summary['files_changed_count'],
            'Tests/validation: '.($summary['failed_validation'] ? 'failed: '.implode(', ', $summary['failed_validation']) : 'no failed checks detected'),
        ];

        if (($summary['blockers'] ?? []) !== []) {
            $lines[] = 'Blockers: '.implode('; ', $summary['blockers']);
        }

        if ($updated->nextPrompt) {
            $lines[] = 'Next prompt: '.$updated->nextPrompt->title;
        }

        if ($deliverNext && $updated->status === 'passed' && $updated->nextPrompt) {
            $nextRun = $promptQueue->deliverPromptIfNeeded($updated->nextPrompt, 'slack', $slackUser);
            $promptQueue->markDelivery($nextRun, 'slack', $slackUser);
            $lines[] = '';
            $lines[] = $this->promptDeliveryText($nextRun->prompt_body, $updated->nextPrompt->title, $nextRun->run_number);
        } elseif ($deliverNext && $updated->status !== 'passed') {
            $lines[] = 'Next build prompt was not delivered because this result requires fix/review first.';
        }

        return implode("\n", $lines);
    }

    private function promptQueueSummaryText(MiriamPromptQueueService $promptQueue): string
    {
        $program = $promptQueue->activeProgram();

        if (! $program) {
            return 'No active Miriam prompt program found.';
        }

        $counts = MiriamPromptPhase::query()
            ->where('prompt_program_id', $program->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $lines = ['*Miriam prompt queue*'];

        foreach (['queued', 'ready', 'in_progress', 'blocked', 'review_required', 'passed', 'failed', 'skipped'] as $status) {
            $lines[] = "{$status}: ".((int) ($counts[$status] ?? 0));
        }

        return implode("\n", $lines);
    }

    private function savedPromptForPhaseText(string $phaseKey, MiriamPromptQueueService $promptQueue): string
    {
        $program = $promptQueue->activeProgram();
        $phase = $program?->phases()->where('phase_key', $phaseKey)->first();

        if (! $program || ! $phase) {
            return "No Miriam prompt phase found for `{$phaseKey}`.";
        }

        $prompt = $promptQueue->nextSavedPrompt($program, $phase);

        if (! $prompt) {
            return "No active build prompt found for `{$phaseKey}`.";
        }

        return $this->promptDeliveryText($promptQueue->renderPrompt($prompt), $prompt->title, null);
    }

    private function promptDeliveryText(string $promptBody, string $title, ?int $runNumber): string
    {
        $prefix = '*Miriam Codex prompt'.($runNumber ? " #{$runNumber}" : '').": {$title}*";

        if (mb_strlen($promptBody) > 3500) {
            return $prefix."\nPrompt is too long for Slack. Open it in Miriam: ".route('product-brain.prompt-os.index');
        }

        return $prefix."\n```{$promptBody}```";
    }

    private function promptHelpText(): string
    {
        return implode("\n", [
            '*Miriam Prompt OS commands*',
            '`/miriam next codex`',
            '`/miriam apps`',
            '`/miriam app status sayaraforce`',
            '`/miriam app open catererhq`',
            '`/miriam app health`',
            '`/miriam app health catererhq`',
            '`/miriam app status churchforce`',
            '`/miriam blockers`',
            '`/miriam next`',
            '`/miriam completed today`',
            '`/miriam app dry-run catererhq`',
            '`/miriam app validate catererhq`',
            '`/miriam releases`',
            '`/miriam release create job 123`',
            '`/miriam release status 1`',
            '`/miriam release approve 1`',
            '`/miriam release reject 1`',
            '`/miriam current phase`',
            '`/miriam codex result {paste Codex output}`',
            '`/miriam codex next after result {paste Codex output}`',
            '`/miriam prompts`',
            '`/miriam prompt phase_3b_prompt_os`',
            '`/miriam dev status`',
            '`/miriam dev monitor`',
            '`/miriam dev summary`',
            '`/miriam dev go`',
            '`/miriam dev go multi`',
            '`/miriam dev go app catererhq`',
            '`/miriam dev status app sayaraforce`',
            '`/miriam dev pause`',
            '`/miriam dev resume`',
            '`/miriam dev approve job {jobId}`',
            '`/miriam dev stop`',
            '`/miriam dev failures`',
            '`/miriam runner status`',
            '`/miriam runner alerts`',
            '`/miriam dev apply fix {failureId}`',
            '`/miriam dev manual fix {failureId}`',
            '`/miriam dev resume after manual fix {failureId}`',
            '`/miriam dev rollback phase {failureId}`',
            '`/miriam dev stop job {jobId}`',
        ]);
    }

    private function codexOutputChannel(string $fallback): string
    {
        return (string) (config('services.slack.codex_output_channel') ?: $fallback);
    }

    private function markDone(Task $task, DailyReviewItem $item): void
    {
        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $task->comments()->create([
            'user_id' => $item->dailyReview->user_id,
            'body' => 'Marked complete from Slack daily review.',
        ]);
        $item->update([
            'completed_at' => now(),
            'response_text' => 'done',
        ]);
    }

    private function moveTask(Task $task, DailyReviewItem $item, string $dateText): void
    {
        $dueDate = $this->parseDate($dateText);
        $task->update(['due_date' => $dueDate]);
        $this->comment($task, $item, "Moved to {$dueDate} from Slack daily review.");
        $item->update(['response_text' => "move {$dateText}"]);
    }

    private function blockTask(Task $task, DailyReviewItem $item, ?string $text): void
    {
        $data = [];

        if (in_array('blocked', Task::STATUSES, true)) {
            $data['status'] = 'blocked';
        }

        if ($data !== []) {
            $task->update($data);
        }

        $this->comment($task, $item, 'Blocked from Slack daily review: '.($text ?: 'No detail provided.'));
        $item->update(['response_text' => $text]);
    }

    private function waitingTask(Task $task, DailyReviewItem $item, ?string $text): void
    {
        if (in_array('waiting', Task::STATUSES, true)) {
            $task->update(['status' => 'waiting']);
        }

        $this->comment($task, $item, 'Waiting update from Slack daily review: '.($text ?: 'No detail provided.'));
        $item->update(['response_text' => $text]);
    }

    private function noteTask(Task $task, DailyReviewItem $item, ?string $text): void
    {
        $this->comment($task, $item, 'Slack daily review note: '.($text ?: 'No detail provided.'));
        $item->update(['response_text' => $text]);
    }

    private function skipTask(Task $task, DailyReviewItem $item): void
    {
        $this->comment($task, $item, 'Skipped in Slack evening review.');
        $item->update(['response_text' => 'skip']);
    }

    private function comment(Task $task, DailyReviewItem $item, string $body): void
    {
        $task->comments()->create([
            'user_id' => $item->dailyReview->user_id,
            'body' => $body,
        ]);
    }

    private function parseDate(string $dateText): string
    {
        return match (strtolower(trim($dateText))) {
            'tomorrow' => now()->addDay()->toDateString(),
            'monday' => now()->next('monday')->toDateString(),
            default => \Carbon\Carbon::parse($dateText)->toDateString(),
        };
    }

    private function helpText(): string
    {
        return implode("\n", [
            '*Friday daily review commands*',
            '`done 1` or `done 2,3`',
            '`move 1 tomorrow`',
            '`move 2 monday`',
            '`block 3 waiting for Sunny`',
            '`waiting 4 waiting for client feedback`',
            '`note 2 tested partially, continue tomorrow`',
            '`skip 5`',
            '`friday what should I focus on today?`',
            '`approve ai 1` / `reject ai all` / `show ai pending`',
            '',
            '*Miriam Prompt OS commands*',
            '`/miriam next codex`',
            '`/miriam apps`',
            '`/miriam app status sayaraforce`',
            '`/miriam app open catererhq`',
            '`/miriam app health`',
            '`/miriam app status churchforce`',
            '`/miriam blockers`',
            '`/miriam next`',
            '`/miriam completed today`',
            '`/miriam app dry-run catererhq`',
            '`/miriam app validate catererhq`',
            '`/miriam releases`',
            '`/miriam release create job 123`',
            '`/miriam release approve 1`',
            '`/miriam current phase`',
            '`/miriam codex result {paste Codex output}`',
            '`/miriam prompts`',
            '`/miriam dev status`',
            '`/miriam dev monitor`',
            '`/miriam dev summary`',
            '`/miriam dev go`',
            '`/miriam dev go multi`',
            '`/miriam dev go app catererhq`',
            '`/miriam dev status app sayaraforce`',
            '`/miriam dev pause`',
            '`/miriam dev resume`',
            '`/miriam dev stop`',
            '`/miriam dev failures`',
            '`/miriam runner status`',
            '`/miriam runner alerts`',
            '`/miriam dev apply fix {failureId}`',
            '`/miriam dev manual fix {failureId}`',
            '`/miriam dev resume after manual fix {failureId}`',
        ]);
    }

    private function parseAiApproval(string $text): ?array
    {
        if (preg_match('/^\s*show\s+ai\s+pending\s*$/i', $text)) {
            return ['action' => 'show', 'selection' => 'all'];
        }

        if (preg_match('/^\s*(approve|reject)\s+ai\s+(.+)\s*$/i', $text, $matches)) {
            return ['action' => strtolower($matches[1]), 'selection' => trim($matches[2])];
        }

        return null;
    }

    private function downloadVoiceAttachment(array $event, SlackService $slackService): ?string
    {
        foreach (($event['files'] ?? []) as $file) {
            $mime = strtolower((string) ($file['mimetype'] ?? $file['filetype'] ?? ''));

            if (! str_contains($mime, 'audio') && ! str_contains($mime, 'ogg') && ! str_contains($mime, 'mpeg')) {
                continue;
            }

            $url = $file['url_private_download'] ?? $file['url_private'] ?? null;

            if ($url) {
                return $slackService->downloadFile((string) $url);
            }
        }

        return null;
    }

    private function resolveUser(): ?User
    {
        $dailyUserId = env('TASKFLOW_DAILY_USER_ID');

        if ($dailyUserId) {
            return User::find((int) $dailyUserId);
        }

        return User::query()->oldest('id')->first();
    }
}
