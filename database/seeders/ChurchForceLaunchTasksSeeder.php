<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ChurchForceLaunchTasksSeeder extends Seeder
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

        $portfolioId = $this->firstOrCreate('portfolios', ['name' => 'ChurchForce'], [
            'workspace_id' => $workspaceId,
            'area_id' => $areaId,
            'name' => 'ChurchForce',
            'slug' => 'churchforce',
            'description' => 'ChurchForce launch readiness plan for June 15 go-live.',
            'status' => 'active',
            'position' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->deletePortfolioLaunchData($portfolioId);

        $tasks = [
            [
                'project' => 'Scope Lock',
                'title' => 'Confirm June 15 live scope',
                'description' => 'Checklist ID: 1
Definition of done: Freeze this build as single-church production launch, not SaaS v1.
Dependency: Current codebase
Output / Link: Approved launch scope
Notes: Avoid scope creep.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-24',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Scope Lock',
                'title' => 'Confirm church name/domain',
                'description' => 'Checklist ID: 2
Definition of done: Confirm production church name, logo usage, app URL, and public website URL.
Dependency: Scope lock
Output / Link: Domain/app URLs
Notes: Needed for emails, receipts, links.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-25',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Scope Lock',
                'title' => 'Define admin roles matrix',
                'description' => 'Checklist ID: 3
Definition of done: Lock roles: Admin, Pastor, Area Leader, Prayer Warrior, Youth Secretary, Sunday School, Women’s Secretary, Member.
Dependency: Scope lock
Output / Link: Role access matrix
Notes: Keep simple for first live.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-25',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Scope Lock',
                'title' => 'Freeze launch feature list',
                'description' => 'Checklist ID: 4
Definition of done: Confirm modules going live: Members, Family, Requests, Offerings, Bookings, Announcements, Calendar, Bible/Hymns, Notifications.
Dependency: Scope lock
Output / Link: Final feature checklist
Notes: Everything else goes to post-launch backlog.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-25',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product Stabilization',
                'title' => 'Run full app route audit',
                'description' => 'Checklist ID: 5
Definition of done: Check all admin/member/pastor/role routes and missing route names.
Dependency: Codebase
Output / Link: Route audit notes
Notes: Use php artisan route:list.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product Stabilization',
                'title' => 'Fix broken navigation/menu order',
                'description' => 'Checklist ID: 6
Definition of done: Ensure menu order: CSIWCM, Home, Bible, Family, Requests, Announcements, Logout; name/profile right aligned.
Dependency: Route audit
Output / Link: Updated app layout
Notes: As per earlier preferred flow.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-27',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product Stabilization',
                'title' => 'Mobile responsiveness pass',
                'description' => 'Checklist ID: 7
Definition of done: Check member/admin screens on phone width and fix layout breaks.
Dependency: Core pages
Output / Link: Mobile-ready UI
Notes: Indian users may mostly use phones.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-25',
                'due_date' => '2026-05-30',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product Stabilization',
                'title' => 'UI polish pass',
                'description' => 'Checklist ID: 8
Definition of done: Apply consistent colors, cards, buttons, spacing, table styling, and empty states.
Dependency: Core screens
Output / Link: Polished UI
Notes: Aim clean, church-friendly, premium.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-25',
                'due_date' => '2026-06-02',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product Stabilization',
                'title' => 'Error page and fallback cleanup',
                'description' => 'Checklist ID: 9
Definition of done: Add friendly 404/500 pages and prevent debug exposure.
Dependency: Production config
Output / Link: Friendly error pages
Notes: APP_DEBUG=false before launch.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-01',
                'due_date' => '2026-06-05',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Member & Family',
                'title' => 'Finalize member import template',
                'description' => 'Checklist ID: 10
Definition of done: Prepare Excel/CSV columns for family number, serial number, name, DOB, phone, WhatsApp, email, gender, address.
Dependency: Feature list
Output / Link: Member import sheet
Notes: Must match DB columns.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-25',
                'due_date' => '2026-05-27',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Member & Family',
                'title' => 'Seed/import member dummy data',
                'description' => 'Checklist ID: 11
Definition of done: Load realistic families/members for UAT.
Dependency: Import template
Output / Link: Demo data loaded
Notes: Useful for visual testing.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-27',
                'due_date' => '2026-05-29',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Member & Family',
                'title' => 'Member list table QA',
                'description' => 'Checklist ID: 12
Definition of done: Check search, filters, pagination, family number, serial number, and profile link.
Dependency: Dummy data
Output / Link: QA notes
Notes: Admin view.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-29',
                'due_date' => '2026-06-01',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Member & Family',
                'title' => 'Family tab QA',
                'description' => 'Checklist ID: 13
Definition of done: Ensure family members show profile image, name, DOB, age, contact number, no role column.
Dependency: Dummy data
Output / Link: Family tab approved
Notes: Member view.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-29',
                'due_date' => '2026-06-01',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Member & Family',
                'title' => 'Profile view QA',
                'description' => 'Checklist ID: 14
Definition of done: Click family card/name to open full profile details.
Dependency: Family tab
Output / Link: Profile QA notes
Notes: Admin/member access rules checked.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-30',
                'due_date' => '2026-06-02',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Member & Family',
                'title' => 'Member detail update request flow',
                'description' => 'Checklist ID: 15
Definition of done: Member can request changes; admin approves/rejects; status reflects in family page.
Dependency: Request module
Output / Link: Working update request flow
Notes: Statuses: awaiting, rejected, up-to-date, approved.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-30',
                'due_date' => '2026-06-04',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Requests',
                'title' => 'Prayer request member submission',
                'description' => 'Checklist ID: 16
Definition of done: Member can submit prayer request with title/content and see status.
Dependency: Member login
Output / Link: Prayer request form
Notes: Core church feature.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-26',
                'due_date' => '2026-05-30',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Requests',
                'title' => 'Prayer response text/audio',
                'description' => 'Checklist ID: 17
Definition of done: Pastor/prayer warrior/admin can respond via text/audio; member sees response received.
Dependency: Prayer request
Output / Link: Prayer response flow
Notes: Audio upload/storage needs testing.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-30',
                'due_date' => '2026-06-04',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Requests',
                'title' => 'Custom request ticket flow',
                'description' => 'Checklist ID: 18
Definition of done: Member can raise custom request/complaint; admin updates status like ticketing system.
Dependency: Requests module
Output / Link: Ticket workflow
Notes: Jira-like simple statuses.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-28',
                'due_date' => '2026-06-03',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Requests',
                'title' => 'Request dashboard counters',
                'description' => 'Checklist ID: 19
Definition of done: Admin dashboard shows pending/approved/rejected/request type counts.
Dependency: Request data
Output / Link: Request dashboard
Notes: Useful for admin operations.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-01',
                'due_date' => '2026-06-05',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Bookings',
                'title' => 'Finalize property booking rules',
                'description' => 'Checklist ID: 20
Definition of done: Church: no Sundays; Parish Halls: Sunday evening only; slots morning/evening.
Dependency: Scope lock
Output / Link: Booking rules doc
Notes: Needed before test.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-25',
                'due_date' => '2026-05-26',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Bookings',
                'title' => 'Booking availability view QA',
                'description' => 'Checklist ID: 21
Definition of done: Member can view property availability before request.
Dependency: Booking rules
Output / Link: Availability QA
Notes: Avoid double bookings.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-01',
                'due_date' => '2026-06-04',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Bookings',
                'title' => 'Booking request submission QA',
                'description' => 'Checklist ID: 22
Definition of done: Member selects property/date/slot and submits request.
Dependency: Availability view
Output / Link: Booking request QA
Notes: Booking not confirmed until admin approval.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-02',
                'due_date' => '2026-06-05',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Bookings',
                'title' => 'Admin approve/reject booking',
                'description' => 'Checklist ID: 23
Definition of done: Admin can approve/reject and block internal slots.
Dependency: Booking request
Output / Link: Admin booking controls
Notes: Calendar/table view.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-03',
                'due_date' => '2026-06-06',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Bookings',
                'title' => 'Booking calendar QA',
                'description' => 'Checklist ID: 24
Definition of done: Admin can view approved bookings and blocked slots in calendar/table.
Dependency: Admin booking controls
Output / Link: Booking calendar approved
Notes: Check conflict handling.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-05',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Offerings & Payments',
                'title' => 'Offering entry QA',
                'description' => 'Checklist ID: 25
Definition of done: Admin/member offering submission records amount, type, contributor, date.
Dependency: Offerings module
Output / Link: Offering QA notes
Notes: Cash/manual flow first.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-28',
                'due_date' => '2026-06-02',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Offerings & Payments',
                'title' => 'Receipt PDF generation QA',
                'description' => 'Checklist ID: 26
Definition of done: Every offering generates receipt PDF with correct numbering and details.
Dependency: Offering entry
Output / Link: Receipt sample PDFs
Notes: Check public storage path.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-01',
                'due_date' => '2026-06-04',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Offerings & Payments',
                'title' => 'WhatsApp receipt sharing QA',
                'description' => 'Checklist ID: 27
Definition of done: Admin can manually resend receipt over WhatsApp where enabled.
Dependency: Receipt PDFs
Output / Link: WA receipt test
Notes: Avoid invalid media URL issue.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-03',
                'due_date' => '2026-06-07',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Offerings & Payments',
                'title' => 'Monthly subscription family tracking',
                'description' => 'Checklist ID: 28
Definition of done: Subscription tracked by family; admin can report unpaid families.
Dependency: Family data
Output / Link: Subscription report
Notes: Reminder automation can be post-launch if needed.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-02',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Offerings & Payments',
                'title' => 'Payment gateway decision',
                'description' => 'Checklist ID: 29
Definition of done: Decide whether online payment gateway is launch v1 or post-launch.
Dependency: Offerings scope
Output / Link: Payment decision
Notes: Do not delay launch for gateway.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-27',
                'due_date' => '2026-05-29',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Announcements & Calendar',
                'title' => 'Announcement CRUD QA',
                'description' => 'Checklist ID: 30
Definition of done: Admin creates, edits, publishes announcements; member can view.
Dependency: Announcements module
Output / Link: Announcement QA
Notes: Search should work.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-29',
                'due_date' => '2026-06-02',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Announcements & Calendar',
                'title' => 'Scheduled announcement QA',
                'description' => 'Checklist ID: 31
Definition of done: Admin schedules announcements for future publishing.
Dependency: Announcement CRUD
Output / Link: Schedule test
Notes: Requires scheduler/cron.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-02',
                'due_date' => '2026-06-05',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Announcements & Calendar',
                'title' => 'Event calendar QA',
                'description' => 'Checklist ID: 32
Definition of done: Admin creates events; members view only.
Dependency: Calendar module
Output / Link: Calendar QA
Notes: Mobile view important.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-02',
                'due_date' => '2026-06-06',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Announcements & Calendar',
                'title' => 'Event RSVP QA',
                'description' => 'Checklist ID: 33
Definition of done: Member RSVP yes/no; admin sees counts.
Dependency: Events
Output / Link: RSVP QA
Notes: Can move post-launch if tight.',
                'owner' => 'Sam',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => '2026-06-04',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Bible & Hymns',
                'title' => 'Bible module QA',
                'description' => 'Checklist ID: 34
Definition of done: English KJV/Telugu switch works; default John 1 works.
Dependency: Bible module
Output / Link: Bible QA notes
Notes: Keep simple for v1.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-30',
                'due_date' => '2026-06-04',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Bible & Hymns',
                'title' => 'Verse of the day QA',
                'description' => 'Checklist ID: 35
Definition of done: Verse of the Day pulls from promise verses table.
Dependency: Verse table
Output / Link: Verse QA
Notes: Check no blank verse.',
                'owner' => 'Sam',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => '2026-06-03',
                'due_date' => '2026-06-06',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Bible & Hymns',
                'title' => 'AKK hymn upload/admin QA',
                'description' => 'Checklist ID: 36
Definition of done: Admin can upload/edit Telugu + transliterated English hymns.
Dependency: Hymn module
Output / Link: Hymn admin QA
Notes: Search by number/title.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-03',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Bible & Hymns',
                'title' => 'Hymn member search QA',
                'description' => 'Checklist ID: 37
Definition of done: Member can search hymn by number or title.
Dependency: Hymn data
Output / Link: Hymn search QA
Notes: Mobile-friendly.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-05',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Notifications & Messaging',
                'title' => 'In-app notification QA',
                'description' => 'Checklist ID: 38
Definition of done: Members/admins receive relevant notifications for requests, announcements, bookings.
Dependency: Core modules
Output / Link: Notification QA
Notes: Avoid duplicate notifications.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-04',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Notifications & Messaging',
                'title' => 'Email sending config',
                'description' => 'Checklist ID: 39
Definition of done: Configure SMTP/domain email and test password reset/announcement email.
Dependency: Domain/email
Output / Link: Email test pass
Notes: Required for production.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-04',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Notifications & Messaging',
                'title' => 'Email marketing v1 QA',
                'description' => 'Checklist ID: 40
Definition of done: Admin can create template/campaign category and send to selected members.
Dependency: Email config
Output / Link: Email marketing QA
Notes: Optional if launch gets tight.',
                'owner' => 'Sam',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => '2026-06-06',
                'due_date' => '2026-06-10',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Notifications & Messaging',
                'title' => 'WhatsApp notification decision',
                'description' => 'Checklist ID: 41
Definition of done: Decide WhatsApp scope for launch: receipts only, announcements, or request alerts.
Dependency: Messaging scope
Output / Link: WA scope decision
Notes: Keep conservative.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-29',
                'due_date' => '2026-06-01',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Roles & Access',
                'title' => 'Admin access QA',
                'description' => 'Checklist ID: 42
Definition of done: Admin can read/edit/archive all modules, no delete unless explicitly required.
Dependency: Roles matrix
Output / Link: Admin QA notes
Notes: No accidental delete.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-01',
                'due_date' => '2026-06-05',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Roles & Access',
                'title' => 'Pastor/prayer warrior access QA',
                'description' => 'Checklist ID: 43
Definition of done: Pastor/prayer warrior can handle prayer requests only as defined.
Dependency: Roles matrix
Output / Link: Prayer role QA
Notes: Privacy sensitive.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-03',
                'due_date' => '2026-06-07',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Roles & Access',
                'title' => 'Area/youth/women/sunday school role QA',
                'description' => 'Checklist ID: 44
Definition of done: Each role sees only assigned/allowed modules.
Dependency: Roles matrix
Output / Link: Role QA notes
Notes: Simplify if too complex.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-04',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Roles & Access',
                'title' => 'Member access QA',
                'description' => 'Checklist ID: 45
Definition of done: Member sees own profile/family/requests/announcements/calendar/bible/hymns only.
Dependency: Member login
Output / Link: Member QA notes
Notes: Must pass before launch.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-04',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Security & Data',
                'title' => 'Production .env review',
                'description' => 'Checklist ID: 46
Definition of done: APP_ENV, APP_DEBUG, APP_URL, mail, DB, storage, queue, cache configured correctly.
Dependency: Hosting decision
Output / Link: Production env checklist
Notes: No secrets in git.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-06',
                'due_date' => '2026-06-09',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Security & Data',
                'title' => 'Password reset and login security QA',
                'description' => 'Checklist ID: 47
Definition of done: Test login, logout, reset password, session, invalid login handling.
Dependency: Email config
Output / Link: Auth QA
Notes: Basic launch trust.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-06',
                'due_date' => '2026-06-09',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Security & Data',
                'title' => 'Seeder/default password cleanup',
                'description' => 'Checklist ID: 48
Definition of done: Remove/rotate known default passwords from production seeders.
Dependency: Production DB
Output / Link: Safe seeders
Notes: Launch blocker.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-06',
                'due_date' => '2026-06-09',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Security & Data',
                'title' => 'Backup and rollback plan',
                'description' => 'Checklist ID: 49
Definition of done: Take schema/data backup and define rollback steps before launch.
Dependency: Production DB
Output / Link: Backup files + rollback notes
Notes: Must do before UAT signoff.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-09',
                'due_date' => '2026-06-11',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Security & Data',
                'title' => 'Privacy-sensitive data review',
                'description' => 'Checklist ID: 50
Definition of done: Ensure member phone/email/DOB/prayer requests are not exposed incorrectly.
Dependency: Role QA
Output / Link: Privacy QA notes
Notes: Prayer requests especially.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-07',
                'due_date' => '2026-06-10',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Deployment',
                'title' => 'Select hosting path',
                'description' => 'Checklist ID: 51
Definition of done: Decide Azure/shared/VPS deployment for first church instance.
Dependency: Domain/app URL
Output / Link: Hosting decision
Notes: Keep reliable and low-cost.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-05-25',
                'due_date' => '2026-05-27',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Deployment',
                'title' => 'Prepare production server/app',
                'description' => 'Checklist ID: 52
Definition of done: Set PHP, composer, web root, database, storage permissions, cron/queue.
Dependency: Hosting path
Output / Link: Production app ready
Notes: Do not wait until final day.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-01',
                'due_date' => '2026-06-07',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Deployment',
                'title' => 'Build assets and verify Vite manifest',
                'description' => 'Checklist ID: 53
Definition of done: Run npm build and ensure production CSS/JS load.
Dependency: Production server
Output / Link: Assets ready
Notes: Avoid blank login page.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-06',
                'due_date' => '2026-06-09',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Deployment',
                'title' => 'Storage link and file access QA',
                'description' => 'Checklist ID: 54
Definition of done: Profile photos, audio, receipts, uploads load publicly/private as intended.
Dependency: Production app
Output / Link: Storage QA
Notes: Common failure area.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-07',
                'due_date' => '2026-06-10',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Deployment',
                'title' => 'Queue and scheduler setup',
                'description' => 'Checklist ID: 55
Definition of done: Queue worker and cron/scheduler running for emails, scheduled announcements, notifications.
Dependency: Production app
Output / Link: Queue/scheduler proof
Notes: Capture command/status.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-08',
                'due_date' => '2026-06-10',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Deployment',
                'title' => 'SSL and domain QA',
                'description' => 'Checklist ID: 56
Definition of done: App loads on HTTPS, redirects correctly, no mixed-content errors.
Dependency: Domain/server
Output / Link: HTTPS verified
Notes: Required for trust.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-09',
                'due_date' => '2026-06-11',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website & Trust',
                'title' => 'Public landing/home page',
                'description' => 'Checklist ID: 57
Definition of done: ChurchForce public page explains product/app and gives login/contact links.
Dependency: Brand assets
Output / Link: Website page
Notes: Can be simple v1.',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-01',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website & Trust',
                'title' => 'Privacy policy page',
                'description' => 'Checklist ID: 58
Definition of done: Publish privacy policy covering member data, communications, payments, and storage.
Dependency: Website
Output / Link: Privacy URL
Notes: Must before real users.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-03',
                'due_date' => '2026-06-08',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website & Trust',
                'title' => 'Terms/service page',
                'description' => 'Checklist ID: 59
Definition of done: Publish basic service terms for church use.
Dependency: Website
Output / Link: Terms URL
Notes: Simple v1 okay.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-03',
                'due_date' => '2026-06-09',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Website & Trust',
                'title' => 'Support/contact page',
                'description' => 'Checklist ID: 60
Definition of done: Add support contact, escalation path, and issue reporting process.
Dependency: Website
Output / Link: Support page
Notes: Useful for church rollout.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-05',
                'due_date' => '2026-06-09',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content & Onboarding',
                'title' => 'Admin quick-start guide',
                'description' => 'Checklist ID: 61
Definition of done: One-page guide: login, add member, approve request, create announcement, booking, offering.
Dependency: UAT findings
Output / Link: Admin guide PDF/doc
Notes: Needed for handover.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-08',
                'due_date' => '2026-06-11',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content & Onboarding',
                'title' => 'Member quick-start guide',
                'description' => 'Checklist ID: 62
Definition of done: Simple guide/screenshots for login, family, requests, announcements, Bible/hymns.
Dependency: Member UAT
Output / Link: Member guide
Notes: Can be WhatsApp-friendly.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-08',
                'due_date' => '2026-06-11',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content & Onboarding',
                'title' => 'Pastor/prayer warrior guide',
                'description' => 'Checklist ID: 63
Definition of done: Guide for reading/responding to prayer requests safely.
Dependency: Prayer QA
Output / Link: Prayer guide
Notes: Include privacy expectation.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-09',
                'due_date' => '2026-06-12',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content & Onboarding',
                'title' => 'Launch announcement draft',
                'description' => 'Checklist ID: 64
Definition of done: Prepare message to church leadership/members announcing the app.
Dependency: Guides
Output / Link: Launch message
Notes: WhatsApp/email copy.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-10',
                'due_date' => '2026-06-12',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Content & Onboarding',
                'title' => 'Training session plan',
                'description' => 'Checklist ID: 65
Definition of done: Prepare 30-45 min walkthrough agenda for admin and church leaders.
Dependency: Guides
Output / Link: Training agenda
Notes: Record issues live.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-10',
                'due_date' => '2026-06-13',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Admin full journey UAT',
                'description' => 'Checklist ID: 66
Definition of done: Admin login, dashboard, members, requests, announcements, calendar, offerings, reports.
Dependency: Production app
Output / Link: Admin UAT signoff
Notes: No critical blocker.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-09',
                'due_date' => '2026-06-11',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Member full journey UAT',
                'description' => 'Checklist ID: 67
Definition of done: Member login, family, profile, request, booking, announcement, Bible/hymn view.
Dependency: Production app
Output / Link: Member UAT signoff
Notes: Phone test mandatory.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-09',
                'due_date' => '2026-06-11',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Role-specific UAT',
                'description' => 'Checklist ID: 68
Definition of done: Pastor/prayer warrior/area/youth/women/sunday school role access tested.
Dependency: Roles setup
Output / Link: Role UAT signoff
Notes: Privacy check.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-10',
                'due_date' => '2026-06-12',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Integration smoke test',
                'description' => 'Checklist ID: 69
Definition of done: Email, WhatsApp scope, PDFs, uploads, queue, scheduler tested.
Dependency: Deployment
Output / Link: Integration signoff
Notes: Must pass before launch.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-10',
                'due_date' => '2026-06-12',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Bug triage and fix window',
                'description' => 'Checklist ID: 70
Definition of done: Fix critical/high bugs found during UAT.
Dependency: UAT results
Output / Link: Resolved blocker list
Notes: Medium bugs can move post-launch.',
                'owner' => 'Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-11',
                'due_date' => '2026-06-13',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'UAT',
                'title' => 'Final production smoke test',
                'description' => 'Checklist ID: 71
Definition of done: Fresh login and core flows after final deployment/cache clear.
Dependency: Bug fixes
Output / Link: Final smoke pass
Notes: Launch gate.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-13',
                'due_date' => '2026-06-14',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Launch',
                'title' => 'Go/no-go review',
                'description' => 'Checklist ID: 72
Definition of done: Confirm launch readiness: app, data, backups, guides, support, leadership approval.
Dependency: Final smoke test
Output / Link: Go/no-go decision
Notes: Do not launch with critical blocker.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-14',
                'due_date' => '2026-06-14',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Launch',
                'title' => 'Production data load',
                'description' => 'Checklist ID: 73
Definition of done: Import/verify final member/family data for first church.
Dependency: Backup plan
Output / Link: Final data loaded
Notes: Take backup after import.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-14',
                'due_date' => '2026-06-14',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Launch',
                'title' => 'Launch to church leadership',
                'description' => 'Checklist ID: 74
Definition of done: Share URL/login details with leadership and admins.
Dependency: Go decision
Output / Link: Leadership launch
Notes: Start controlled rollout.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-15',
                'due_date' => '2026-06-15',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Launch',
                'title' => 'Launch to members',
                'description' => 'Checklist ID: 75
Definition of done: Share member announcement, login steps, support contact.
Dependency: Leadership launch
Output / Link: Member launch
Notes: Recommended staged rollout if large.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-15',
                'due_date' => '2026-06-15',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Launch',
                'title' => 'Day-1 monitoring',
                'description' => 'Checklist ID: 76
Definition of done: Monitor errors, login issues, requests, emails/notifications, and user feedback.
Dependency: Launch
Output / Link: Day-1 notes
Notes: Keep hotfix window open.',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-15',
                'due_date' => '2026-06-15',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Post Launch',
                'title' => 'First week support log',
                'description' => 'Checklist ID: 77
Definition of done: Track issues daily and classify bug/enhancement/training.
Dependency: Launch
Output / Link: Support tracker
Notes: Do not mix with launch blocker list.',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-16',
                'due_date' => '2026-06-22',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Post Launch',
                'title' => 'Post-launch backlog grooming',
                'description' => 'Checklist ID: 78
Definition of done: Move non-critical ideas to SaaS/post-launch backlog.
Dependency: Support log
Output / Link: Backlog list
Notes: Separate single church vs SaaS.',
                'owner' => 'Sam',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => '2026-06-16',
                'due_date' => '2026-06-22',
                'section' => 'Launch Checklist',
            ],
            [
                'project' => 'Product UAT - Auth',
                'title' => 'Admin login/logout',
                'description' => 'Test ID: UAT-001
Journey: Auth
Steps: Login as admin, logout, retry invalid login
Expected Result: Admin auth works and invalid login fails',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Auth',
                'title' => 'Member login/profile',
                'description' => 'Test ID: UAT-002
Journey: Auth
Steps: Login as member and open profile
Expected Result: Member sees own profile only',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Members',
                'title' => 'Admin member list',
                'description' => 'Test ID: UAT-003
Journey: Members
Steps: Open member index, search, filter, click member
Expected Result: Table and profile load correctly',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Family',
                'title' => 'Family tab',
                'description' => 'Test ID: UAT-004
Journey: Family
Steps: Open member family tab
Expected Result: Family members display image/name/DOB/age/contact, no role',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Requests',
                'title' => 'Member detail update',
                'description' => 'Test ID: UAT-005
Journey: Requests
Steps: Submit profile update request
Expected Result: Admin receives request and status changes correctly',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Prayer',
                'title' => 'Prayer request submit/respond',
                'description' => 'Test ID: UAT-006
Journey: Prayer
Steps: Submit request, respond as pastor/prayer warrior
Expected Result: Member sees response received',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Prayer',
                'title' => 'Audio prayer response',
                'description' => 'Test ID: UAT-007
Journey: Prayer
Steps: Upload/send audio response
Expected Result: Audio stores and plays correctly',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Bookings',
                'title' => 'Property rule validation',
                'description' => 'Test ID: UAT-008
Journey: Bookings
Steps: Try Sunday church booking and Sunday parish hall morning
Expected Result: Invalid slots blocked',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Bookings',
                'title' => 'Booking approval',
                'description' => 'Test ID: UAT-009
Journey: Bookings
Steps: Submit booking and approve as admin
Expected Result: Booking confirmed only after approval',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Offerings',
                'title' => 'Offering + receipt',
                'description' => 'Test ID: UAT-010
Journey: Offerings
Steps: Create offering and download receipt
Expected Result: Receipt generated with correct data',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Offerings',
                'title' => 'Receipt WhatsApp resend',
                'description' => 'Test ID: UAT-011
Journey: Offerings
Steps: Resend receipt via WhatsApp/manual share
Expected Result: Receipt link works',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Announcements',
                'title' => 'Create scheduled announcement',
                'description' => 'Test ID: UAT-012
Journey: Announcements
Steps: Schedule a future announcement
Expected Result: Publishes correctly via scheduler',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Calendar',
                'title' => 'Event and RSVP',
                'description' => 'Test ID: UAT-013
Journey: Calendar
Steps: Create event and RSVP as member
Expected Result: Admin sees counts',
                'owner' => 'Sam',
                'priority' => 'medium',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Bible',
                'title' => 'Bible switch',
                'description' => 'Test ID: UAT-014
Journey: Bible
Steps: Open Bible and switch English/Telugu
Expected Result: Correct text loads',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Hymns',
                'title' => 'Hymn search',
                'description' => 'Test ID: UAT-015
Journey: Hymns
Steps: Search by number and title
Expected Result: Correct hymn loads in Telugu/transliteration',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Email',
                'title' => 'SMTP/password reset',
                'description' => 'Test ID: UAT-016
Journey: Email
Steps: Trigger password reset/test email
Expected Result: Email delivered',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Notifications',
                'title' => 'In-app notification',
                'description' => 'Test ID: UAT-017
Journey: Notifications
Steps: Trigger request/announcement/booking notifications
Expected Result: Right users receive one notification',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Roles',
                'title' => 'Role access control',
                'description' => 'Test ID: UAT-018
Journey: Roles
Steps: Login as each role
Expected Result: Each role sees allowed screens only',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Deployment',
                'title' => 'Assets/storage',
                'description' => 'Test ID: UAT-019
Journey: Deployment
Steps: Open production pages, upload/view files
Expected Result: No blank pages; uploads accessible',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Product UAT - Mobile',
                'title' => 'Phone test',
                'description' => 'Test ID: UAT-020
Journey: Mobile
Steps: Test core flows on phone
Expected Result: UI usable and buttons visible',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
                'section' => 'Product UAT',
            ],
            [
                'project' => 'Launch Timeline',
                'title' => 'Timeline milestone: Scope Lock',
                'description' => 'Duration days: 2
Critical items: 4
Milestone / Expected Output: Single-church launch scope frozen',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-05-25',
                'section' => 'Timeline',
            ],
            [
                'project' => 'Launch Timeline',
                'title' => 'Timeline milestone: Core Product Stabilization',
                'description' => 'Duration days: 13
Critical items: 15
Milestone / Expected Output: Core pages, navigation, UI, member/family/request base stable',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-24',
                'due_date' => '2026-06-05',
                'section' => 'Timeline',
            ],
            [
                'project' => 'Launch Timeline',
                'title' => 'Timeline milestone: Church Modules QA',
                'description' => 'Duration days: 14
Critical items: 24
Milestone / Expected Output: Requests, bookings, offerings, announcements, Bible/hymns tested',
                'owner' => 'Sam/Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-05-26',
                'due_date' => '2026-06-08',
                'section' => 'Timeline',
            ],
            [
                'project' => 'Launch Timeline',
                'title' => 'Timeline milestone: Roles + Security',
                'description' => 'Duration days: 10
Critical items: 9
Milestone / Expected Output: Access, privacy, auth, seeders, backup ready',
                'owner' => 'Sam/Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-01',
                'due_date' => '2026-06-10',
                'section' => 'Timeline',
            ],
            [
                'project' => 'Launch Timeline',
                'title' => 'Timeline milestone: Deployment',
                'description' => 'Duration days: 11
Critical items: 6
Milestone / Expected Output: Production server, domain, SSL, assets, storage, queue ready',
                'owner' => 'Developer',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-01',
                'due_date' => '2026-06-11',
                'section' => 'Timeline',
            ],
            [
                'project' => 'Launch Timeline',
                'title' => 'Timeline milestone: Guides + Onboarding',
                'description' => 'Duration days: 6
Critical items: 5
Milestone / Expected Output: Admin/member guides and launch communication ready',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-08',
                'due_date' => '2026-06-13',
                'section' => 'Timeline',
            ],
            [
                'project' => 'Launch Timeline',
                'title' => 'Timeline milestone: Full UAT + Fixes',
                'description' => 'Duration days: 6
Critical items: 6
Milestone / Expected Output: No critical blocker before launch',
                'owner' => 'Sam/Developer',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-09',
                'due_date' => '2026-06-14',
                'section' => 'Timeline',
            ],
            [
                'project' => 'Launch Timeline',
                'title' => 'Timeline milestone: Launch Day',
                'description' => 'Duration days: 1
Critical items: 3
Milestone / Expected Output: ChurchForce live for first church',
                'owner' => 'Sam',
                'priority' => 'urgent',
                'status' => 'todo',
                'start_date' => '2026-06-15',
                'due_date' => '2026-06-15',
                'section' => 'Timeline',
            ],
            [
                'project' => 'Launch Timeline',
                'title' => 'Timeline milestone: First Week Support',
                'description' => 'Duration days: 7
Critical items: 2
Milestone / Expected Output: Support log and post-launch backlog',
                'owner' => 'Sam',
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => '2026-06-16',
                'due_date' => '2026-06-22',
                'section' => 'Timeline',
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
                'slug' => Str::slug('churchforce-'.$projectName),
                'description' => 'Imported from ChurchForce Launch Readiness Plan for June 15.',
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

        $this->command?->info('Deleted old ChurchForce launch data and imported '.count($tasks).' ChurchForce launch tasks into Ventures > ChurchForce.');
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
