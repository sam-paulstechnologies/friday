<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CareerHelpingOthersTasksSeeder extends Seeder
{
    private const MARKER = 'Seeder: CareerHelpingOthersTasksSeeder';

    public function run(): void
    {
        $now = now();
        $userId = optional(DB::table('users')->orderBy('id')->first())->id;

        if (! $userId) {
            throw new \RuntimeException('No user found. Create one user first, then run this seeder.');
        }

        $workspaceId = optional(DB::table('workspaces')->orderBy('id')->first())->id;

        if (! $workspaceId) {
            throw new \RuntimeException('No workspace found. Create one workspace first, then run this seeder.');
        }

        $careerAreaId = $this->firstOrCreate('areas', ['slug' => 'career'], [
            'name' => 'Career',
            'slug' => 'career',
            'description' => 'Career work, clients, and professional growth.',
            'color' => '#2563eb',
            'icon' => 'briefcase',
            'position' => 10,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $helpingAreaId = $this->firstOrCreate('areas', ['slug' => 'helping-others'], [
            'name' => 'Helping Others',
            'slug' => 'helping-others',
            'description' => 'Projects and support work for friends, family, and community.',
            'color' => '#16a34a',
            'icon' => 'heart-handshake',
            'position' => 30,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $portfolios = [
            'Stellantis GCC' => $this->portfolio($workspaceId, $careerAreaId, 'Stellantis GCC', 1, 'Career portfolio for Stellantis GCC work.'),
            'Stellantis South Africa' => $this->portfolio($workspaceId, $careerAreaId, 'Stellantis South Africa', 2, 'Career portfolio for Stellantis South Africa work.'),
            'Digitas Internal' => $this->portfolio($workspaceId, $careerAreaId, 'Digitas Internal', 3, 'Internal Digitas follow-up and reporting work.'),
            'Career Growth' => $this->portfolio($workspaceId, $careerAreaId, 'Career Growth', 4, 'Career growth, certifications, and personal brand work.'),
            'Paul’s Photography' => $this->portfolio($workspaceId, $helpingAreaId, 'Paul’s Photography', 1, 'Photography business and premium wedding brand launch.'),
            'UAE Realtor Agents App' => $this->portfolio($workspaceId, $helpingAreaId, 'UAE Realtor Agents App', 2, 'Dubai real estate CRM MVP pilot launch.'),
        ];

        $this->cleanupSeededData(array_values($portfolios));

        foreach ($this->seedPlan() as $portfolioName => $projects) {
            $portfolioId = $portfolios[$portfolioName];
            $areaId = in_array($portfolioName, ['Paul’s Photography', 'UAE Realtor Agents App'], true) ? $helpingAreaId : $careerAreaId;
            $projectPosition = 1;
            $taskPosition = 1;

            foreach ($projects as $projectName => $tasks) {
                $projectId = $this->updateOrCreate('projects', [
                    'workspace_id' => $workspaceId,
                    'slug' => Str::slug($portfolioName.' '.$projectName),
                ], [
                    'workspace_id' => $workspaceId,
                    'area_id' => $areaId,
                    'portfolio_id' => $portfolioId,
                    'owner_id' => $userId,
                    'name' => $projectName,
                    'slug' => Str::slug($portfolioName.' '.$projectName),
                    'description' => self::MARKER."\n{$portfolioName} project seeded for Miriam / Friday.",
                    'status' => 'active',
                    'visibility' => 'private',
                    'health' => 'on_track',
                    'sort_order' => $projectPosition,
                    'position' => $projectPosition,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($tasks as $task) {
                    $this->insertFiltered('tasks', [
                        'workspace_id' => $workspaceId,
                        'area_id' => $areaId,
                        'portfolio_id' => $portfolioId,
                        'project_id' => $projectId,
                        'reporter_id' => $userId,
                        'assignee_id' => $this->validUserId($task['assignee_id'] ?? null, $userId),
                        'title' => $task['title'],
                        'description' => $this->description($task['notes'] ?? null),
                        'status' => $task['status'] ?? $this->statusFor($task['title'], $task['notes'] ?? ''),
                        'priority' => $task['priority'] ?? $this->priorityFor($task['title'], $task['notes'] ?? ''),
                        'section' => $projectName,
                        'sort_order' => $taskPosition,
                        'position' => $taskPosition,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $taskPosition++;
                }

                $projectPosition++;
            }
        }

        $this->command?->info('Seeded Career and Helping Others tasks without touching unrelated portfolio data.');
    }

    private function portfolio(int $workspaceId, int $areaId, string $name, int $position, string $description): int
    {
        return $this->firstOrCreate('portfolios', ['slug' => Str::slug($name)], [
            'workspace_id' => $workspaceId,
            'area_id' => $areaId,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $description,
            'status' => 'active',
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cleanupSeededData(array $portfolioIds): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        $projectIds = Schema::hasTable('projects')
            ? DB::table('projects')
                ->whereIn('portfolio_id', $portfolioIds)
                ->where('description', 'like', '%'.self::MARKER.'%')
                ->pluck('id')
            : collect();

        $taskIds = DB::table('tasks')
            ->whereIn('portfolio_id', $portfolioIds)
            ->where(function ($query) use ($projectIds): void {
                $query->where('description', 'like', '%'.self::MARKER.'%')
                    ->when($projectIds->isNotEmpty(), fn ($inner) => $inner->orWhereIn('project_id', $projectIds));
            })
            ->pluck('id');

        if ($taskIds->isNotEmpty()) {
            foreach (['task_comments', 'task_activities', 'task_attachments', 'task_reminders', 'daily_review_items'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('task_id', $taskIds)->delete();
                }
            }

            if (Schema::hasTable('custom_field_values')) {
                DB::table('custom_field_values')
                    ->where('entity_type', 'App\\Models\\Task')
                    ->whereIn('entity_id', $taskIds)
                    ->delete();
            }

            DB::table('tasks')->whereIn('id', $taskIds)->delete();
        }

        if ($projectIds->isNotEmpty()) {
            if (Schema::hasTable('custom_field_values')) {
                DB::table('custom_field_values')
                    ->where('entity_type', 'App\\Models\\Project')
                    ->whereIn('entity_id', $projectIds)
                    ->delete();
            }

            DB::table('projects')->whereIn('id', $projectIds)->delete();
        }
    }

    private function seedPlan(): array
    {
        return [
            'Stellantis GCC' => [
                'Lead Uploads & Salesforce Closure' => [
                    $this->task('Venkata walk-in upload', 'Venkata to share walk-in details; tech to review Naboodah flow because opportunities are assigned to Venkata no matter who creates them.', 'high'),
                    $this->task('MBM Lead Quality analysis', 'Investigate April lead quality with Harsh; follow up with Harsh; no action required from CX.', 'medium', 'completed'),
                    $this->task('Salesforce leads closure issue - Western Motors', 'Aftersales opportunities showing line mandate error while closing opportunity; check with Jheel; line mandatory issue edge case when aftersales opportunity owner does not respond to call.', 'high'),
                    $this->task('Snapchat leads upload', 'Update Snapchat leads on daily basis.', 'high'),
                    $this->task('TE Leads Meta Download concern', 'No one has download access except Stellantis Digitas; unable to download leads at campaign level; downloading adset/ad level gives fewer leads; escalated to Meta support.', 'high'),
                    $this->task('Closed Won Count', 'Created Date Filter + Delivery Date blank for many records; Delivery date issue; discrepancy due to blank delivery date.', 'medium'),
                    $this->task('Leads vs Opportunities Discrepancy', 'Blank lead sources causing discrepancy; solution implemented: DD hardcoded field value for dealer-side manually uploaded leads.', 'high'),
                    $this->task('Mopar leads Upload', 'Would need tech team support to upload aftersales leads and convert them into opportunities.', 'high'),
                    $this->task('Iraq forms', 'Stellantis check.', 'medium'),
                ],
                'Dealer Anomaly & Data Quality' => [
                    $this->task('Dealer Activity Anomaly Monitoring', "Lightweight Python system to flag dealer-level anomalies and trigger early alerts for integration/process gaps.\nSummary review.\nEnhance speed done.\nAddress open leads for Teyseer Motor, MBM and OBY done.\nInactive user deletion WIP.\nVIN identifier done.\nEDM reporting WIP.", 'high', 'in_progress'),
                    $this->task('Showroom visited Date Discrepancy', "Under investigation with PTC IT team.\nTech team says PTCT team will populate visit date as a new field.\nShowroom visited date is working without issue; check on ability to select past/future dates.", 'high'),
                    $this->task('Data cleanup - Stellantis leads vs dealer leads - Mopar opps under sales', "Pipeline = critical fields left blank, wrong dealer country, blank privacy policy, blank request origin from website leads, blank lead source, blank brand or line, lead source.\nShare reports showing what will update historical data early next week.\nInvestigating blank extended privacy policy.\nMove to phases 1, 2 and 3.\nPhase 1 deployed and monitored.\nPhase 2 deployed.\nPhase 2.5: Reiterate communication about fund attribution with dealers + session with dealers.\nPhase 3: Implement dashboard filters.\nF unfiltered dashboard review.\nTech team task: all dealer uploaded leads should be marked as DD.", 'urgent'),
                ],
                'Reporting & Dashboards' => [
                    $this->task('Future Opportunities - Reporting', "Reports to be shared by Sam.\nEscalated to tech team.\nCould not see SFM reopened data against reopened opportunities.", 'medium', 'completed'),
                    $this->task('Queue view update - Petromin', "Tech team to confirm aging and add it to the view.\nJheel confirmed this would need development time.\nAwaiting tech team time.", 'high'),
                    $this->task('Gargash go live', "Dynamic dashboard for sales executives.\nLast checked on 22/5.\nAwaiting dynamic dashboard.", 'high'),
                    $this->task('WM Dashboards', "Appointment dashboard completed.\nDealership dashboard completed.\nBranch dashboard Abu Dhabi completed.\nBranch dashboard Al Ain pending.\nSales executive pending due to dynamic dashboard license.\nAwaiting dynamic dashboard.", 'high'),
                    $this->task('Quarterly Dashboard Filters', 'Quarterly / yearly dashboard filters to be updated.', 'medium'),
                    $this->task('Journeys Reporting', 'Datorama lower-funnel request; update with Sam.', 'medium'),
                ],
            ],
            'Stellantis South Africa' => [
                'South Africa Dealer & OEM Dashboards' => [
                    $this->task('New Dealer onboarding', 'Build dashboard and share it with respective dealer.', 'high'),
                    $this->task('Location vs Dealer discrepancy', 'Get to the root cause and resolve it.', 'high'),
                    $this->task('Dashboard for OEM leads per dealer', 'Build OEM leads per dealer dashboard.', 'high'),
                ],
            ],
            'Digitas Internal' => [
                'Internal Reporting & Stakeholder Follow-up' => [
                    $this->task('Follow up with Wisam on dashboard feedback', null, 'high'),
                    $this->task('Build and share Product and Product Owner report with Samuel', null, 'high'),
                ],
            ],
            'Career Growth' => [
                'Career Growth Plan' => [
                    $this->task('Complete 12 certifications', null, 'high'),
                    $this->task('Get LinkedIn profile in place', null, 'high'),
                    $this->task('Build LinkedIn posting calendar', null, 'medium'),
                    $this->task('Post on LinkedIn consistently', null, 'medium'),
                ],
            ],
            'Paul’s Photography' => $this->paulsPhotographyProjects(),
            'UAE Realtor Agents App' => $this->uaeRealtorProjects(),
        ];
    }

    private function paulsPhotographyProjects(): array
    {
        return [
            'Two-Brand Strategy' => [
                $this->task('Finalize two-brand positioning', 'Define clear separation between The Pauls Photography and premium wedding brand.', 'high'),
                $this->task('Define budget brand offer', 'Clarify The Pauls Photography packages, target audience, price band, and messaging.', 'high'),
                $this->task('Define premium brand offer', 'Clarify premium brand positioning, audience, city focus, emotional tone, and editorial style.', 'high'),
                $this->task('Finalize premium brand name', null, 'high'),
                $this->task('Write brand story and tagline', null, 'high'),
            ],
            'Premium Brand Identity' => $this->tasksFromTitles([
                'Create premium logo' => 'high',
                'Create icon / monogram' => 'medium',
                'Define color palette' => 'high',
                'Select heading and body fonts' => 'high',
                'Create Instagram highlight covers' => 'medium',
                'Create post / reel templates' => 'medium',
                'Create proposal / quotation PDF template' => 'high',
                'Create branded invoice / quotation template' => 'medium',
                'Create watermark' => 'medium',
                'Define album branding style' => 'medium',
            ]),
            'Website & Lead Capture' => $this->tasksFromTitles([
                'Build premium homepage hero' => 'high',
                'Write About the Brand section' => 'medium',
                'Add wedding photography section' => 'medium',
                'Add wedding films section' => 'medium',
                'Add package tiers and starting prices' => 'high',
                'Add portfolio / galleries' => 'high',
                'Add testimonials' => 'medium',
                'Add cities served' => 'medium',
                'Build enquiry form' => 'high',
                'Add WhatsApp CTA' => 'high',
                'Add FAQ' => 'medium',
                'Add privacy policy' => 'medium',
                'Add terms and conditions' => 'medium',
            ]),
            'Packages & Sales Process' => $this->tasksFromTitles([
                'Define Essential / Entry package',
                'Define Signature / Mid package',
                'Define Premium / Heirloom package',
                'Define package inclusions',
                'Define travel policy',
                'Define advance payment rule',
                'Define upsell list',
                'Create qualification questions',
                'Create discovery call flow',
                'Create custom quote flow',
                'Create booking confirmation flow',
                'Create delivery follow-up and testimonial request flow',
            ]),
            'Portfolio & Content Bank' => $this->tasksFromTitles([
                'Select 3-5 best weddings',
                'Select bride/groom portraits',
                'Select candid family moments',
                'Select ritual shots',
                'Select couple shoot shots',
                'Select decor/detail shots',
                'Select album photos',
                'Prepare 15-30 second premium reels',
                'Prepare before/after editing examples',
                'Build premium gallery layout',
                'Prepare 15-20 premium reels',
                'Prepare 10 carousel posts',
                'Prepare 5 testimonials',
                'Prepare 20 story posts/templates',
                'Prepare 10 BTS clips',
                'Prepare 5 package/explainer reels',
                'Prepare 5 couple story posts',
                'Prepare 5 album delivery posts',
            ]),
            'Instagram Launch' => $this->tasksFromTitles([
                'Secure Instagram handle',
                'Write premium Instagram bio',
                'Set profile picture',
                'Create highlights',
                'Prepare 9-post launch grid',
                'Prepare 10-15 reels',
                'Set pinned posts',
                'Create story templates',
                'Create DM auto-reply',
                'Add WhatsApp link',
                'Create link-in-bio page',
            ]),
            'CRM & Lead Tracking' => $this->tasksFromTitles([
                'Website enquiry form',
                'WhatsApp inquiry flow',
                'Lead source tracking',
                'Budget field',
                'Event date field',
                'City field',
                'Number of events field',
                'Follow-up stages',
                'Lost reason tracking',
            ]),
            'Legal & Operations' => $this->tasksFromTitles([
                'Booking terms',
                'Cancellation policy',
                'Refund policy',
                'Travel policy',
                'Delivery timeline policy',
                'Copyright/image usage policy',
                'Client consent for portfolio usage',
                'Payment terms',
                'Contract/agreement PDF',
                'Photographer team list',
                'Videographer team list',
                'Editor list',
                'Backup camera plan',
                'Travel/stay process',
                'Shoot checklist',
                'Event-day timeline template',
                'File backup process',
                'Editing workflow',
                'Album design process',
                'Delivery workflow',
            ]),
        ];
    }

    private function uaeRealtorProjects(): array
    {
        return [
            'Eid MVP Deployment' => [
                $this->task('Freeze MVP scope for Eid pilot', 'No big integrations unless already stable.', 'urgent'),
                $this->task('Confirm app name, logo, theme, and domain', null, 'high'),
                $this->task('Configure production environment', 'Supabase/Lovable production settings, auth URLs, app URLs.', 'high'),
                $this->task('Deploy production build', null, 'urgent'),
                $this->task('Enable PWA/installable setup if available', null, 'medium'),
                $this->task('Final smoke test after deployment', null, 'urgent'),
            ],
            'Product QA' => $this->tasksFromTitles([
                'QA login/signup and profile creation',
                'QA role access: admin, manager, agent',
                'QA leads list, detail, create, CSV import, public web form',
                'QA properties list, create/edit, photos, sale/rent status',
                'QA lead-property matchmaker',
                'QA pipeline stages and deal detail',
                'QA viewings scheduling and calendar/list',
                'QA conversations and messages',
                'QA AI lead scoring and smart reply',
                'QA AI listing description writer',
                'QA social post generator',
                'QA viewing feedback summarizer',
                'QA saved searches and alerts toggle',
                'QA reports dashboard',
                'QA notifications and settings',
                'QA mobile responsiveness',
            ], 'high'),
            'Pilot User Onboarding' => [
                $this->task('Prepare 5-10 pilot user list', 'Dubai agents or known contacts.', 'high'),
                $this->task('Prepare onboarding message', null, 'high'),
                $this->task('Create short Loom/demo video', null, 'medium'),
                $this->task('Prepare pilot feedback form', null, 'medium'),
                $this->task('Invite pilot users', null, 'urgent'),
                $this->task('Track user feedback', null, 'high'),
                $this->task('Capture bugs and improvement requests', null, 'high'),
            ],
            'Lead Channels MVP' => [
                $this->task('Keep public web form working', null, 'medium'),
                $this->task('Create WhatsApp click-to-chat flow', 'Do this before full WhatsApp Business Cloud API.', 'high'),
                $this->task('Prepare Meta lead ads webhook plan', 'Full Meta integration can be Sprint 6 after pilot.', 'medium'),
                $this->task('Prepare Bayut / Property Finder email parser plan', null, 'medium'),
                $this->task('Prepare Resend/custom email setup plan', null, 'medium'),
            ],
            'Pilot Success Criteria' => [
                $this->task('Define success metrics', '5-10 users onboarded, 20+ leads added, 10+ properties added, 5+ viewings scheduled.', 'high'),
                $this->task('Define feedback categories', 'usability, missing features, lead capture, mobile issues, AI usefulness.', 'medium'),
                $this->task('Review pilot feedback after Eid', null, 'high'),
                $this->task('Decide Sprint 6 implementation order', 'WhatsApp Business Cloud API, Meta Lead Ads, Bayut/PF parser, Resend.', 'high'),
            ],
        ];
    }

    private function tasksFromTitles(array $titles, string $defaultPriority = 'medium'): array
    {
        $tasks = [];

        foreach ($titles as $title => $priority) {
            if (is_int($title)) {
                $title = $priority;
                $priority = $defaultPriority;
            }

            $tasks[] = $this->task($title, null, $priority);
        }

        return $tasks;
    }

    private function task(string $title, ?string $notes = null, ?string $priority = null, ?string $status = null): array
    {
        return array_filter([
            'title' => $title,
            'notes' => $notes,
            'priority' => $priority,
            'status' => $status,
        ], fn ($value) => $value !== null);
    }

    private function description(?string $notes): string
    {
        return self::MARKER.($notes ? "\n\nNotes:\n".$notes : '');
    }

    private function statusFor(string $title, string $notes): string
    {
        $text = strtolower($title.' '.$notes);

        if (str_contains($text, 'completed') || str_contains($text, 'done')) {
            return 'completed';
        }

        if (str_contains($text, 'wip') || str_contains($text, 'working') || str_contains($text, 'under investigation')) {
            return 'in_progress';
        }

        return 'todo';
    }

    private function priorityFor(string $title, string $notes): string
    {
        $text = strtolower($title.' '.$notes);

        if (Str::contains($text, ['launch', 'blocker', 'issue', 'critical', 'deploy', 'smoke test'])) {
            return 'urgent';
        }

        if (Str::contains($text, ['important', 'active', 'pilot', 'qa', 'dashboard', 'production'])) {
            return 'high';
        }

        return 'medium';
    }

    private function validUserId($candidate, int $fallbackUserId): int
    {
        if ($candidate && DB::table('users')->where('id', $candidate)->exists()) {
            return (int) $candidate;
        }

        return $fallbackUserId;
    }

    private function firstOrCreate(string $table, array $where, array $values): int
    {
        $where = $this->filterColumns($table, $where);

        if ($where !== []) {
            $existing = DB::table($table)->where($where)->first();

            if ($existing) {
                DB::table($table)->where('id', $existing->id)->update($this->filterColumns($table, [
                    ...$values,
                    'updated_at' => now(),
                ]));

                return (int) $existing->id;
            }
        }

        return $this->insertFiltered($table, $values);
    }

    private function updateOrCreate(string $table, array $where, array $values): int
    {
        return $this->firstOrCreate($table, $where, $values);
    }

    private function insertFiltered(string $table, array $values): int
    {
        $values = $this->filterColumns($table, $values);

        return (int) DB::table($table)->insertGetId($values);
    }

    private function filterColumns(string $table, array $values): array
    {
        return collect($values)
            ->filter(fn ($value, $key) => Schema::hasColumn($table, $key))
            ->all();
    }
}
