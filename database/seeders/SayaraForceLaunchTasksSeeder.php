<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SayaraForceLaunchTasksSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $userId = optional(DB::table('users')->orderBy('id')->first())->id;

        if (! $userId) {
            throw new \RuntimeException('No user found. Create/login with one user first, then run this seeder.');
        }

        $workspaceId = optional(DB::table('workspaces')->orderBy('id')->first())->id;

        if (! $workspaceId) {
            $workspaceId = $this->insertFiltered('workspaces', [
                'name' => 'Miriam Workspace',
                'slug' => 'miriam-workspace',
                'owner_id' => $userId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $areaId = $this->firstOrCreate('areas', ['name' => 'Ventures'], [
            'workspace_id' => $workspaceId,
            'name' => 'Ventures',
            'slug' => 'ventures',
            'description' => 'Business ventures and SaaS products.',
            'color' => '#0f766e',
            'status' => 'active',
            'position' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $portfolioId = $this->firstOrCreate('portfolios', ['name' => 'SayaraForce'], [
            'workspace_id' => $workspaceId,
            'area_id' => $areaId,
            'name' => 'SayaraForce',
            'slug' => 'sayaraforce',
            'description' => 'SayaraForce launch readiness, campaign, sales, content, and UAT plan.',
            'status' => 'active',
            'position' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->deletePortfolioLaunchData($portfolioId);

        $tasks = [
            [
                'project' => 'Brand & Offer',
                'title' => 'Freeze product name',
                'description' => 'Definition of done: Use SayaraForce consistently across website, CRM, social, brochure, and outre...
Dependency: Logo / brand kit
Output / Link: SayaraForce
Notes: Name appears fixed.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'completed',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Brand & Offer',
                'title' => 'Finalize logo',
                'description' => 'Definition of done: Lock final logo in square, horizontal, light, and dark versions.
Dependency: Product name
Output / Link: Logo folder
Notes: Needed for all pages.',
                'owner' => 'Designer',
                'priority' => 'high',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Brand & Offer',
                'title' => 'Finalize tagline',
                'description' => 'Definition of done: Use one clear tagline across all channels.
Dependency: Positioning
Output / Link: Recover missed leads. Retain more garage customers.
Notes: Approved direction.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'completed',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Brand & Offer',
                'title' => 'Finalize positioning statement',
                'description' => 'Definition of done: Clarify SayaraForce as WhatsApp-first lead and retention CRM, not garage ERP.
Dependency: Tagline
Output / Link: Positioning doc
Notes: Avoid wrong competitor comparisons.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'completed',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Brand & Offer',
                'title' => 'Finalize founder offer',
                'description' => 'Definition of done: Define discount, duration, eligible garage count, and expiry date.
Dependency: Pricing packages
Output / Link: Founder offer section
Notes: Suggested: first 10 garages, 50% off first 3 months.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Brand & Offer',
                'title' => 'Finalize pricing packages',
                'description' => 'Definition of done: Lock launch pricing, inclusions, add-ons, and WhatsApp usage model.
Dependency: Founder offer
Output / Link: Pricing sheet
Notes: Earlier AED 999 / 1499 / 1999 discussed.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Finish public landing page',
                'description' => 'Definition of done: Home page clearly explains pain, solution, features, founder offer, and CTA.
Dependency: Brand kit
Output / Link: Website URL
Notes: Must be mobile-first.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-24',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Hero section complete',
                'description' => 'Definition of done: Hero should show value proposition, CTA buttons, and 24/7 missed-lead angle.
Dependency: Landing page
Output / Link: Hero block
Notes: Add Book Demo + WhatsApp CTAs.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'in_progress',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-27',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Feature sections complete',
                'description' => 'Definition of done: Sections for lead capture, inbox, WhatsApp, retention, manager dashboard, rep...
Dependency: Landing page
Output / Link: Feature blocks
Notes: Use blue/orange UI style.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'in_progress',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-27',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Pricing section complete',
                'description' => 'Definition of done: Show clear packages and founder offer without overloading visitor.
Dependency: Pricing packages
Output / Link: Pricing section
Notes: Can start with launch offer + contact sales.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-27',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Book demo form complete',
                'description' => 'Definition of done: Capture garage name, owner name, phone, WhatsApp, email, location, service ty...
Dependency: CRM lead capture
Output / Link: Demo form
Notes: Should create CRM lead automatically.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-24',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Thank-you page',
                'description' => 'Definition of done: After form submission, show next steps and WhatsApp CTA.
Dependency: Book demo form
Output / Link: Thank-you URL
Notes: Useful for conversion tracking.',
                'owner' => 'Developer',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-31',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Privacy Policy page',
                'description' => 'Definition of done: Basic policy required for trust, Meta, Google tracking, and forms.
Dependency: Domain
Output / Link: Privacy URL
Notes: Can be simple v1.',
                'owner' => 'Legal',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-24',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Terms page',
                'description' => 'Definition of done: Basic service terms / website terms.
Dependency: Domain
Output / Link: Terms URL
Notes: Needed before payments/contracts.',
                'owner' => 'Legal',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-27',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'WhatsApp click-to-chat button',
                'description' => 'Definition of done: Connect website CTA to business WhatsApp number with prefilled message.
Dependency: WhatsApp number
Output / Link: WA link
Notes: Track clicks.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-24',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Mobile QA',
                'description' => 'Definition of done: Test every website page and CTA on mobile.
Dependency: Website pages
Output / Link: QA notes
Notes: Most garage owners will open on phone.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-24',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website',
                'title' => 'Domain and SSL check',
                'description' => 'Definition of done: Confirm public marketing domain and SSL are working.
Dependency: Domain/DNS
Output / Link: Live URL
Notes: App domain exists; marketing domain needs final check.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-17',
                'due_date' => '2026-05-24',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Tracking',
                'title' => 'Google Analytics setup',
                'description' => 'Definition of done: GA4 installed and receiving page views.
Dependency: Website live
Output / Link: GA property
Notes: Needed before campaigns.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-21',
                'due_date' => '2026-05-31',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Tracking',
                'title' => 'Meta Pixel setup',
                'description' => 'Definition of done: Pixel installed on website and firing.
Dependency: Website live
Output / Link: Pixel ID
Notes: Needed before social campaigns.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-21',
                'due_date' => '2026-05-31',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Tracking',
                'title' => 'Conversion events',
                'description' => 'Definition of done: Track demo form submit, WhatsApp click, pricing click, and book demo click.
Dependency: GA + Pixel
Output / Link: Events list
Notes: Must test before launch.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-21',
                'due_date' => '2026-05-28',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Social Setup',
                'title' => 'LinkedIn company page',
                'description' => 'Definition of done: Create page with logo, banner, bio, website, and CTA.
Dependency: Logo
Output / Link: LinkedIn URL
Notes: B2B trust.',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-19',
                'due_date' => '2026-05-29',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Social Setup',
                'title' => 'Instagram page',
                'description' => 'Definition of done: Create page with bio, highlights, logo, website/WhatsApp link.
Dependency: Logo
Output / Link: Instagram URL
Notes: Visual credibility.',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-19',
                'due_date' => '2026-05-29',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Social Setup',
                'title' => 'Facebook page',
                'description' => 'Definition of done: Create page with business details and CTA.
Dependency: Logo
Output / Link: Facebook URL
Notes: Useful for garage-owner reach.',
                'owner' => 'Marketing',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => '2026-05-19',
                'due_date' => '2026-06-02',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Social Setup',
                'title' => 'TikTok / YouTube Shorts setup',
                'description' => 'Definition of done: Reserve handles and prepare short-video publishing flow.
Dependency: Logo
Output / Link: Channel URLs
Notes: Optional but useful.',
                'owner' => 'Marketing',
                'priority' => 'low',
                'status' => 'todo',
                'start_date' => '2026-05-19',
                'due_date' => '2026-06-09',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Social Setup',
                'title' => 'Social banners and profile graphics',
                'description' => 'Definition of done: Create consistent cover images and profile display assets.
Dependency: Logo/tagline
Output / Link: Brand assets
Notes: Same look across platforms.',
                'owner' => 'Designer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-19',
                'due_date' => '2026-05-29',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Social Setup',
                'title' => 'First 9 posts ready',
                'description' => 'Definition of done: Prepare first grid so pages do not look empty at launch.
Dependency: Content calendar
Output / Link: Post assets
Notes: Minimum launch credibility.',
                'owner' => 'Marketing',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-19',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content',
                'title' => '30-day content calendar',
                'description' => 'Definition of done: Create daily post plan with topic, caption, format, platform, CTA.
Dependency: Positioning
Output / Link: Calendar tab
Notes: Do not create daily from scratch.',
                'owner' => 'Marketing',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-20',
                'due_date' => '2026-05-27',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content',
                'title' => 'Static posts prepared',
                'description' => 'Definition of done: Prepare at least 20 static posts.
Dependency: Content calendar
Output / Link: Design folder
Notes: Pain, feature, trust, offer posts.',
                'owner' => 'Designer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-20',
                'due_date' => '2026-05-30',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content',
                'title' => 'Carousel posts prepared',
                'description' => 'Definition of done: Prepare at least 8 carousel posts.
Dependency: Content calendar
Output / Link: Design folder
Notes: Educate garage owners.',
                'owner' => 'Designer',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => '2026-05-20',
                'due_date' => '2026-06-03',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content',
                'title' => 'Reel / short scripts prepared',
                'description' => 'Definition of done: Prepare at least 10 short video scripts.
Dependency: Content calendar
Output / Link: Scripts
Notes: Can record with screen + voice.',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-20',
                'due_date' => '2026-05-30',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content',
                'title' => 'Founder story post',
                'description' => 'Definition of done: Write launch founder story with why SayaraForce exists.
Dependency: Brand story
Output / Link: Post copy
Notes: Build trust.',
                'owner' => 'Sam',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => '2026-05-20',
                'due_date' => '2026-06-03',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content',
                'title' => 'Demo walkthrough video',
                'description' => 'Definition of done: Record 60–120 second product walkthrough.
Dependency: Demo data + stable app
Output / Link: Video link
Notes: Use for WhatsApp follow-up.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-20',
                'due_date' => '2026-05-27',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Campaigns',
                'title' => 'Launch announcement campaign',
                'description' => 'Definition of done: Ready-to-publish launch creatives and captions for all platforms.
Dependency: Social pages
Output / Link: Campaign pack
Notes: Publish on license day.',
                'owner' => 'Marketing',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-31',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Campaigns',
                'title' => 'Founder offer campaign',
                'description' => 'Definition of done: Creative, caption, landing section, and WhatsApp message for launch offer.
Dependency: Founder offer
Output / Link: Campaign pack
Notes: Creates urgency.',
                'owner' => 'Marketing',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-31',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Campaigns',
                'title' => 'Missed lead campaign',
                'description' => 'Definition of done: Campaign around lost WhatsApp inquiries and slow response time.
Dependency: Content bank
Output / Link: Campaign pack
Notes: Primary pain point.',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-06-03',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Campaigns',
                'title' => 'Retention campaign',
                'description' => 'Definition of done: Campaign around bringing old customers back with reminders.
Dependency: Retention module
Output / Link: Campaign pack
Notes: Differentiates from basic CRM.',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-06-03',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Campaigns',
                'title' => 'Demo booking campaign',
                'description' => 'Definition of done: Campaign focused on booking 20-minute demos.
Dependency: Demo form
Output / Link: Campaign pack
Notes: Direct conversion campaign.',
                'owner' => 'Marketing',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-31',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Admin dashboard stable',
                'description' => 'Definition of done: Admin dashboard loads, looks consistent, and shows correct numbers.
Dependency: Core modules
Output / Link: Admin URL
Notes: Needs design consistency check.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Manager dashboard stable',
                'description' => 'Definition of done: Manager dashboard matches admin UI and shows role-specific work.
Dependency: Manager routes
Output / Link: Manager URL
Notes: Active work.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Manager inbox stable',
                'description' => 'Definition of done: Manager inbox matches admin inbox design and handles conversations.
Dependency: WhatsApp logs
Output / Link: Inbox URL
Notes: Key launch feature.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Lead capture working',
                'description' => 'Definition of done: Website, manual, Google/Meta, and WhatsApp leads land in CRM correctly.
Dependency: Lead routes/webhooks
Output / Link: Lead test logs
Notes: Needs end-to-end testing.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Lead conversion working',
                'description' => 'Definition of done: Lead → client → opportunity flow works and updates lead status properly.
Dependency: Lead module
Output / Link: Test case
Notes: Known issue: lead remains New after opportunity.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Booking flow working',
                'description' => 'Definition of done: Opportunity ready for booking creates/updates correct booking status.
Dependency: Opportunity flow
Output / Link: Booking test
Notes: Check pending vs scheduled behavior.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Job flow working',
                'description' => 'Definition of done: Manager can create/view/update jobs from bookings.
Dependency: Booking flow
Output / Link: Job pages
Notes: Manager job pages under build.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Invoice flow working',
                'description' => 'Definition of done: Manager/admin can view and manage invoices consistently.
Dependency: Job flow
Output / Link: Invoice pages
Notes: Manager invoice views pending/aligned.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'WhatsApp inbound working',
                'description' => 'Definition of done: Inbound webhook receives messages, creates logs, routes to correct inbox.
Dependency: Meta setup
Output / Link: Webhook logs
Notes: Needs queue/worker testing.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'WhatsApp outbound working',
                'description' => 'Definition of done: Outbound messages send once using unified event/template flow.
Dependency: Template mapping
Output / Link: Message logs
Notes: Remove duplicate old paths.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Retention segments dashboard',
                'description' => 'Definition of done: Show enabled segments, counts, triggers, and sample messages.
Dependency: Client/job data
Output / Link: Retention URL
Notes: Core positioning feature.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'in_progress',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Response time calculator',
                'description' => 'Definition of done: Show average response time and missed lead recovery signal.
Dependency: Message logs
Output / Link: Dashboard widget
Notes: Important demo metric.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Live retention scoreboard',
                'description' => 'Definition of done: Show customers due for service/reminder and retention opportunity value.
Dependency: Retention logic
Output / Link: Dashboard widget
Notes: Revenue engine story.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Error notification system',
                'description' => 'Definition of done: Surface webhook, queue, WhatsApp, and failed job errors to admin/dev.
Dependency: Logs/jobs
Output / Link: Error screen or alert
Notes: Important before go-live.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product',
                'title' => 'Demo company data seeded',
                'description' => 'Definition of done: Create demo garage with leads, clients, bookings, jobs, invoices, chats.
Dependency: Stable app
Output / Link: Demo company
Notes: Demo must not look empty.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-16',
                'due_date' => '2026-05-23',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Admin journey test',
                'description' => 'Definition of done: Login, dashboard, leads, clients, opportunities, bookings, jobs, invoices, se...
Dependency: Product modules
Output / Link: UAT notes
Notes: End-to-end admin check.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-26',
                'due_date' => '2026-06-02',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Manager journey test',
                'description' => 'Definition of done: Login, inbox, lead follow-up, booking, job, invoice, WhatsApp actions.
Dependency: Manager modules
Output / Link: UAT notes
Notes: Demo depends on this.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-26',
                'due_date' => '2026-06-02',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Lead source test',
                'description' => 'Definition of done: Test website form, WhatsApp inbound, Google lead webhook, Meta lead webhook.
Dependency: Lead capture
Output / Link: Lead source logs
Notes: Confirm source tagging.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-26',
                'due_date' => '2026-06-02',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'WhatsApp message journey test',
                'description' => 'Definition of done: Test lead ack, booking confirmation, reminders, feedback, review prompt.
Dependency: WA inbound/outbound
Output / Link: WA test sheet
Notes: No duplicate messages.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-26',
                'due_date' => '2026-06-02',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Mobile demo test',
                'description' => 'Definition of done: Open CRM and website from phone and run demo flow.
Dependency: Stable app
Output / Link: Mobile QA notes
Notes: Owner demos may happen on mobile.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-26',
                'due_date' => '2026-06-05',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Sales',
                'title' => '1-page brochure PDF',
                'description' => 'Definition of done: Create simple brochure for WhatsApp sharing.
Dependency: Brand/offer
Output / Link: PDF link
Notes: Needed for outreach.',
                'owner' => 'Marketing',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-22',
                'due_date' => '2026-05-29',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Sales',
                'title' => 'Pricing PDF',
                'description' => 'Definition of done: Create package and add-on pricing PDF.
Dependency: Pricing final
Output / Link: PDF link
Notes: Needed after interest.',
                'owner' => 'Marketing',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-22',
                'due_date' => '2026-05-29',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Sales',
                'title' => 'Demo script',
                'description' => 'Definition of done: Create 15–20 minute demo script with flow and talking points.
Dependency: Demo data
Output / Link: Script link
Notes: Avoid freestyling.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-22',
                'due_date' => '2026-05-29',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Sales',
                'title' => 'Objection handling sheet',
                'description' => 'Definition of done: Prepare responses to price, existing WhatsApp, existing CRM, trust, timing.
Dependency: Pricing/positioning
Output / Link: Objection sheet
Notes: Helps close first clients.',
                'owner' => 'Sales',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-22',
                'due_date' => '2026-06-01',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Sales',
                'title' => 'Proposal template',
                'description' => 'Definition of done: Create proposal format for interested garages.
Dependency: Pricing PDF
Output / Link: Proposal doc
Notes: Use after demo.',
                'owner' => 'Sales',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-22',
                'due_date' => '2026-06-01',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Sales',
                'title' => 'Invoice template',
                'description' => 'Definition of done: Prepare invoice/payment request format for first customer.
Dependency: License/payment details
Output / Link: Invoice template
Notes: Required once customer says yes.',
                'owner' => 'Ops',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-22',
                'due_date' => '2026-06-01',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Sales',
                'title' => 'Basic service agreement',
                'description' => 'Definition of done: Create simple SaaS/service agreement.
Dependency: Terms/pricing
Output / Link: Agreement doc
Notes: Before taking money.',
                'owner' => 'Legal',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-22',
                'due_date' => '2026-05-29',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Outreach',
                'title' => 'Garage lead list',
                'description' => 'Definition of done: Build initial 250–300 garage prospects across UAE.
Dependency: Target audience
Output / Link: Lead list tab
Notes: Direct outreach starts day one.',
                'owner' => 'Sales',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-23',
                'due_date' => '2026-05-30',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Outreach',
                'title' => 'Warm contact list',
                'description' => 'Definition of done: Prepare first 25 personal/warm contacts.
Dependency: Contacts
Output / Link: Lead list tab
Notes: Fastest first demos.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-23',
                'due_date' => '2026-05-30',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Outreach',
                'title' => 'WhatsApp outreach templates',
                'description' => 'Definition of done: Prepare intro, follow-up, brochure send, demo reminder, post-demo follow-up.
Dependency: Offer/brochure
Output / Link: Templates
Notes: Needed for launch week.',
                'owner' => 'Sales',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-23',
                'due_date' => '2026-05-30',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Outreach',
                'title' => 'Email outreach templates',
                'description' => 'Definition of done: Prepare optional email sequence for garages with emails.
Dependency: Brochure/website
Output / Link: Templates
Notes: Secondary channel.',
                'owner' => 'Sales',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => '2026-05-23',
                'due_date' => '2026-06-06',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Launch Day',
                'title' => 'Launch day command checklist',
                'description' => 'Definition of done: Single-day list of publish, test, outreach, monitor tasks.
Dependency: All pre-launch tasks
Output / Link: Launch day tab
Notes: Use on license day.',
                'owner' => 'Ops',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-29',
                'due_date' => '2026-06-05',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Launch Day',
                'title' => 'Daily tracking sheet',
                'description' => 'Definition of done: Track leads, replies, demos booked, proposals, wins, learnings.
Dependency: Campaigns/outreach
Output / Link: Dashboard
Notes: Keeps execution controlled.',
                'owner' => 'Ops',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-29',
                'due_date' => '2026-06-05',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Post Launch',
                'title' => '14-day follow-up cadence',
                'description' => 'Definition of done: Daily posting, direct outreach, demos, follow-ups, feedback improvements.
Dependency: Launch day
Output / Link: Post-launch tracker
Notes: Most important period after launch.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-30',
                'due_date' => '2026-06-06',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Post Launch',
                'title' => 'First customer onboarding checklist',
                'description' => 'Definition of done: Steps from payment to garage setup, WhatsApp, users, templates, training.
Dependency: Sales close
Output / Link: Onboarding doc
Notes: Needed before first paid garage.',
                'owner' => 'Ops',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-30',
                'due_date' => '2026-06-06',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Founder offer teaser',
                'description' => 'Channel: LinkedIn / Instagram
Format: Static
Topic / Hook: Something new is coming for UAE garages
CTA: Join early list
Asset Needed: Teaser creative
Launch Offset: T-10',
                'owner' => 'Marketing',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-23',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Problem post',
                'description' => 'Channel: LinkedIn
Format: Text + image
Topic / Hook: A missed WhatsApp message can become lost revenue
CTA: Book demo
Asset Needed: Pain-point creative
Launch Offset: T-7',
                'owner' => 'Marketing',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-26',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Retention education',
                'description' => 'Channel: Instagram / FB
Format: Carousel
Topic / Hook: Old customers are cheaper to bring back than new ones
CTA: See how
Asset Needed: Carousel design
Launch Offset: T-5',
                'owner' => 'Marketing',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-28',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Product sneak peek',
                'description' => 'Channel: Reels / Shorts
Format: Video
Topic / Hook: Manager inbox + lead capture demo
CTA: Book demo
Asset Needed: Screen recording
Launch Offset: T-3',
                'owner' => 'Sam',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-30',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Launch announcement',
                'description' => 'Channel: All channels
Format: Static + text
Topic / Hook: SayaraForce is live for UAE garages
CTA: Book demo
Asset Needed: Launch creative
Launch Offset: Launch Day',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-02',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Founder story',
                'description' => 'Channel: LinkedIn
Format: Text post
Topic / Hook: Why I built SayaraForce
CTA: DM for demo
Asset Needed: Founder copy
Launch Offset: Launch Day',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-02',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'WhatsApp outreach',
                'description' => 'Channel: WhatsApp
Format: Message
Topic / Hook: We launched a WhatsApp-first CRM for garages
CTA: Book demo
Asset Needed: Brochure + WA copy
Launch Offset: Launch Day',
                'owner' => 'Sales',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-02',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Missed lead recovery',
                'description' => 'Channel: Instagram / FB
Format: Reel
Topic / Hook: How garages lose customers before first reply
CTA: WhatsApp us
Asset Needed: Reel script
Launch Offset: T+1',
                'owner' => 'Marketing',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-03',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Demo booking',
                'description' => 'Channel: LinkedIn / WhatsApp
Format: Post + message
Topic / Hook: 20-minute demo: see your lead flow clearly
CTA: Book demo
Asset Needed: Demo CTA creative
Launch Offset: T+2',
                'owner' => 'Sales',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-04',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'ROI angle',
                'description' => 'Channel: LinkedIn
Format: Carousel
Topic / Hook: 5 lost jobs can cost more than the CRM
CTA: Calculate ROI
Asset Needed: ROI carousel
Launch Offset: T+3',
                'owner' => 'Marketing',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-05',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Retention scoreboard',
                'description' => 'Channel: Instagram / FB
Format: Static
Topic / Hook: Know who is due for service before they forget you
CTA: Book demo
Asset Needed: Retention creative
Launch Offset: T+5',
                'owner' => 'Marketing',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-07',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Campaign Calendar',
                'title' => 'Proof / demo recap',
                'description' => 'Channel: All channels
Format: Video
Topic / Hook: Inside SayaraForce: lead to booking journey
CTA: Book demo
Asset Needed: Walkthrough video
Launch Offset: T+7',
                'owner' => 'Sam',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-09',
                'section' => 'Campaign Calendar',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'Your garage may be losing customers before the first reply',
                'description' => 'Content Bucket: Pain
Post Type: Static
Caption Direction: Explain missed WhatsApp response as lost revenue.
Platform: LinkedIn/Instagram
CTA: Book Demo
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-25',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'A missed WhatsApp message is not just a message',
                'description' => 'Content Bucket: Pain
Post Type: Reel
Caption Direction: Quick screen/voice reel showing lead waiting.
Platform: Instagram/Shorts
CTA: WhatsApp Us
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-26',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'CRM vs Garage Management Software',
                'description' => 'Content Bucket: Education
Post Type: Carousel
Caption Direction: Clarify SayaraForce does lead/retention, not ERP replacement.
Platform: LinkedIn/Instagram
CTA: Learn More
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-27',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'One inbox for garage enquiries',
                'description' => 'Content Bucket: Feature
Post Type: Static
Caption Direction: Show manager inbox concept.
Platform: Instagram/FB
CTA: Book Demo
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-28',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'From lead to booking without losing track',
                'description' => 'Content Bucket: Feature
Post Type: Carousel
Caption Direction: Show journey steps.
Platform: LinkedIn/Instagram
CTA: See Demo
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-29',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'Your old service customers are your easiest next sale',
                'description' => 'Content Bucket: Retention
Post Type: Static
Caption Direction: Retention reminder angle.
Platform: LinkedIn/Instagram
CTA: Book Demo
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-30',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'What to send customers after 3, 6, and 12 months',
                'description' => 'Content Bucket: Retention
Post Type: Carousel
Caption Direction: Practical reminder examples.
Platform: Instagram/FB
CTA: Get Template
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-31',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'Garage owners should not chase every lead manually',
                'description' => 'Content Bucket: Owner Focus
Post Type: Text
Caption Direction: Owner can monitor team response.
Platform: LinkedIn
CTA: DM for Demo
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-01',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'Founder’s offer for first 10 garages',
                'description' => 'Content Bucket: Offer
Post Type: Static
Caption Direction: Launch offer details.
Platform: All
CTA: Claim Offer
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-02',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'Built for UAE garages',
                'description' => 'Content Bucket: Trust
Post Type: Static
Caption Direction: Local context and practical workflows.
Platform: All
CTA: Book Demo
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-03',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'We already use WhatsApp. Why SayaraForce?',
                'description' => 'Content Bucket: FAQ
Post Type: Carousel
Caption Direction: Explain tracking, assignment, reminders, reports.
Platform: LinkedIn/Instagram
CTA: See Demo
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-04',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'Is SayaraForce a garage ERP?',
                'description' => 'Content Bucket: FAQ
Post Type: Static
Caption Direction: No: lead, WhatsApp, retention growth layer.
Platform: LinkedIn/FB
CTA: Learn More
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-05',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'How 5 missed jobs can cost AED 4,000+',
                'description' => 'Content Bucket: ROI
Post Type: Carousel
Caption Direction: Simple revenue loss example.
Platform: LinkedIn/Instagram
CTA: Calculate ROI
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-06',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'Lead capture to booking in 60 seconds',
                'description' => 'Content Bucket: Demo
Post Type: Video
Caption Direction: Product walkthrough.
Platform: All
CTA: Book Demo
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-07',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Content Bank',
                'title' => 'Why I am building SayaraForce',
                'description' => 'Content Bucket: Founder
Post Type: Text
Caption Direction: Founder story and vision.
Platform: LinkedIn
CTA: Connect
Asset Status: Not Started',
                'owner' => 'Marketing',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-06-08',
                'section' => 'Content Bank',
            ],
            [
                'project' => 'Sales Kit',
                'title' => '1-page brochure PDF',
                'description' => 'Purpose: WhatsApp sharing after first interest
Must Include: Pain, solution, features, founder offer, CTA',
                'owner' => 'Marketing',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-26',
                'section' => 'Sales Kit',
            ],
            [
                'project' => 'Sales Kit',
                'title' => 'Pricing PDF',
                'description' => 'Purpose: Send after demo or serious enquiry
Must Include: Packages, inclusions, add-ons, founder offer terms',
                'owner' => 'Sales',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-25',
                'section' => 'Sales Kit',
            ],
            [
                'project' => 'Sales Kit',
                'title' => 'Demo script',
                'description' => 'Purpose: Consistent demo delivery
Must Include: Opening, pain, product flow, ROI, close',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-26',
                'section' => 'Sales Kit',
            ],
            [
                'project' => 'Sales Kit',
                'title' => 'Objection handling sheet',
                'description' => 'Purpose: Handle common pushbacks
Must Include: Price, existing WhatsApp, trust, time, existing CRM',
                'owner' => 'Sales',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-27',
                'section' => 'Sales Kit',
            ],
            [
                'project' => 'Sales Kit',
                'title' => 'Proposal template',
                'description' => 'Purpose: Send formal proposal after demo
Must Include: Scope, pricing, timeline, next steps',
                'owner' => 'Sales',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-28',
                'section' => 'Sales Kit',
            ],
            [
                'project' => 'Sales Kit',
                'title' => 'Invoice template',
                'description' => 'Purpose: Collect first payment
Must Include: Business details, package, amount, VAT note if applicable',
                'owner' => 'Ops',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-29',
                'section' => 'Sales Kit',
            ],
            [
                'project' => 'Sales Kit',
                'title' => 'Basic service agreement',
                'description' => 'Purpose: Protect before paid onboarding
Must Include: Terms, cancellation, data, support, usage',
                'owner' => 'Legal',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-29',
                'section' => 'Sales Kit',
            ],
            [
                'project' => 'Sales Kit',
                'title' => 'Onboarding checklist',
                'description' => 'Purpose: Setup first paid garage
Must Include: Company, users, WhatsApp, templates, data, training',
                'owner' => 'Ops',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => '2026-05-31',
                'section' => 'Sales Kit',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Admin login and dashboard',
                'description' => 'Journey: Admin
Steps: Login as admin and open dashboard
Expected Result: Dashboard loads with correct metrics',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Manager login and dashboard',
                'description' => 'Journey: Manager
Steps: Login as manager and open dashboard
Expected Result: Manager sees assigned work only',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Website demo form creates lead',
                'description' => 'Journey: Lead Capture
Steps: Submit demo form from website
Expected Result: Lead created with correct source and notification',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Google lead webhook creates lead',
                'description' => 'Journey: Lead Capture
Steps: Post test payload to Google webhook
Expected Result: Lead created/deduped with source Google',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Inbound WhatsApp creates conversation log',
                'description' => 'Journey: WhatsApp
Steps: Send test inbound message
Expected Result: Message appears in manager inbox',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Outbound lead acknowledgement sends once',
                'description' => 'Journey: WhatsApp
Steps: Create new lead with phone
Expected Result: One correct WA acknowledgement sent/logged',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Lead to client/opportunity',
                'description' => 'Journey: Conversion
Steps: Convert lead to opportunity
Expected Result: Client/opportunity created and lead status updated',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Opportunity ready for booking',
                'description' => 'Journey: Booking
Steps: Move opportunity to ready for booking
Expected Result: Correct booking created/updated, no duplicate WA',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Booking to job',
                'description' => 'Journey: Job
Steps: Create job from scheduled booking
Expected Result: Job appears for manager and admin',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Job to invoice',
                'description' => 'Journey: Invoice
Steps: Create invoice from job/client
Expected Result: Invoice appears correctly in admin/manager',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Retention segment counts',
                'description' => 'Journey: Retention
Steps: Open retention dashboard
Expected Result: Counts match test data',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT',
                'title' => 'Mobile demo flow',
                'description' => 'Journey: Mobile
Steps: Open website and app on phone
Expected Result: Pages usable, CTAs visible',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
        ];

        $projectIds = [];
        $projectPosition = 1;

        foreach (array_values(array_unique(array_column($tasks, 'project'))) as $projectName) {
            $projectIds[$projectName] = $this->firstOrCreate('projects', [
                'portfolio_id' => $portfolioId,
                'name' => $projectName,
            ], [
                'workspace_id' => $workspaceId,
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'owner_id' => $userId,
                'name' => $projectName,
                'slug' => Str::slug('sayaraforce-'.$projectName),
                'description' => 'Imported from SayaraForce End-to-End Launch Readiness Plan.',
                'status' => 'active',
                'visibility' => 'private',
                'health' => 'on_track',
                'sort_order' => $projectPosition,
                'position' => $projectPosition,
                'color' => '#0f766e',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $projectPosition++;
        }

        foreach ($tasks as $index => $task) {
            $assigneeId = $this->findUserIdByOwner($task['owner'], $userId);

            $this->insertFiltered('tasks', [
                'workspace_id' => $workspaceId,
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'project_id' => $projectIds[$task['project']] ?? null,
                'reporter_id' => $userId,
                'assignee_id' => $assigneeId,
                'title' => $task['title'],
                'description' => $task['description'],
                'status' => $task['status'],
                'priority' => $task['priority'],
                'section' => $task['section'],
                'start_date' => $task['start_date'],
                'due_date' => $task['due_date'],
                'sort_order' => $index + 1,
                'position' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->command?->info('Deleted old SayaraForce launch data and imported '.count($tasks).' SayaraForce launch tasks into Ventures > SayaraForce.');
    }

    private function deletePortfolioLaunchData(int $portfolioId): void
    {
        $projectIds = Schema::hasTable('projects')
            ? DB::table('projects')->where('portfolio_id', $portfolioId)->pluck('id')
            : collect();

        $taskIds = Schema::hasTable('tasks')
            ? DB::table('tasks')
                ->where('portfolio_id', $portfolioId)
                ->when($projectIds->isNotEmpty(), fn ($query) => $query->orWhereIn('project_id', $projectIds))
                ->pluck('id')
            : collect();

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

    private function firstOrCreate(string $table, array $where, array $values): int
    {
        $where = $this->filterColumns($table, $where);

        if (! empty($where)) {
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

    private function findUserIdByOwner(?string $owner, int $fallbackUserId): int
    {
        if (! $owner) {
            return $fallbackUserId;
        }

        $owner = trim($owner);

        $user = DB::table('users')
            ->where('name', 'like', '%'.$owner.'%')
            ->orWhere('email', 'like', '%'.Str::slug($owner, '').'%')
            ->first();

        return (int) ($user->id ?? $fallbackUserId);
    }
}
