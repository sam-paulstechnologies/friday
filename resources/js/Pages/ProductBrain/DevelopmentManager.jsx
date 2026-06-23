import { Badge, EmptyState, PageHeader, Panel, primaryButton, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const tones = {
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    inactive: 'bg-slate-100 text-slate-700 ring-slate-200',
    paused: 'bg-slate-100 text-slate-700 ring-slate-200',
    disabled: 'bg-rose-50 text-rose-700 ring-rose-100',
    queued: 'bg-sky-50 text-sky-700 ring-sky-100',
    waiting_for_runner: 'bg-amber-50 text-amber-700 ring-amber-100',
    preparing: 'bg-indigo-50 text-indigo-700 ring-indigo-100',
    running: 'bg-violet-50 text-violet-700 ring-violet-100',
    output_received: 'bg-cyan-50 text-cyan-700 ring-cyan-100',
    waiting_for_approval: 'bg-amber-50 text-amber-700 ring-amber-100',
    waiting_for_manual_fix: 'bg-orange-50 text-orange-700 ring-orange-100',
    blocked: 'bg-rose-50 text-rose-700 ring-rose-100',
    failed: 'bg-red-50 text-red-700 ring-red-100',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    cancelled: 'bg-slate-100 text-slate-500 ring-slate-200',
    assigned: 'bg-sky-50 text-sky-700 ring-sky-100',
    passed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    open: 'bg-rose-50 text-rose-700 ring-rose-100',
    fix_requested: 'bg-amber-50 text-amber-700 ring-amber-100',
    fixing: 'bg-violet-50 text-violet-700 ring-violet-100',
    fixed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    manual_attention_required: 'bg-orange-50 text-orange-700 ring-orange-100',
    manually_fixed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    rolled_back: 'bg-slate-100 text-slate-600 ring-slate-200',
    stopped: 'bg-slate-100 text-slate-600 ring-slate-200',
    draft: 'bg-slate-100 text-slate-700 ring-slate-200',
    packaging: 'bg-indigo-50 text-indigo-700 ring-indigo-100',
    packaged: 'bg-cyan-50 text-cyan-700 ring-cyan-100',
    approval_required: 'bg-amber-50 text-amber-700 ring-amber-100',
    approved: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    rejected: 'bg-rose-50 text-rose-700 ring-rose-100',
    archived: 'bg-slate-100 text-slate-500 ring-slate-200',
    online: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    stale: 'bg-amber-50 text-amber-700 ring-amber-100',
    offline: 'bg-rose-50 text-rose-700 ring-rose-100',
    attention_required: 'bg-rose-50 text-rose-700 ring-rose-100',
    watch: 'bg-amber-50 text-amber-700 ring-amber-100',
    healthy: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
};

function Status({ value }) {
    return <Badge tone={tones[value] ?? tones.inactive}>{value}</Badge>;
}

function MonitorSummary({ monitorSummary }) {
    if (!monitorSummary) return null;

    return (
        <Panel>
            <div className="border-b border-slate-200 px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 className="text-sm font-semibold text-slate-950">Monitor Summary</h3>
                        <p className="mt-0.5 text-xs text-slate-500">Runner, job, failure, and release approval health.</p>
                    </div>
                    <Status value={monitorSummary.overall_status} />
                </div>
            </div>
            <div className="grid gap-3 p-4 text-sm sm:grid-cols-4">
                <div className="rounded-md bg-slate-50 p-3">
                    <div className="text-xs text-slate-500">Runners</div>
                    <div className="mt-1 font-semibold text-slate-950">{monitorSummary.runner_count}</div>
                </div>
                <div className="rounded-md bg-slate-50 p-3">
                    <div className="text-xs text-slate-500">Active Jobs</div>
                    <div className="mt-1 font-semibold text-slate-950">{monitorSummary.active_job_count}</div>
                </div>
                <div className="rounded-md bg-slate-50 p-3">
                    <div className="text-xs text-slate-500">Manual Actions</div>
                    <div className="mt-1 font-semibold text-slate-950">{monitorSummary.manual_action_count}</div>
                </div>
                <div className="rounded-md bg-slate-50 p-3">
                    <div className="text-xs text-slate-500">Release Approvals</div>
                    <div className="mt-1 font-semibold text-slate-950">{monitorSummary.pending_release_approval_count}</div>
                </div>
            </div>
            {monitorSummary.alerts?.length > 0 && (
                <div className="border-t border-amber-200 bg-amber-50 p-4">
                    <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-amber-900">Latest Alerts</div>
                    <div className="space-y-2">
                        {monitorSummary.alerts.slice(0, 4).map((alert) => (
                            <div key={alert.dedupe_key} className="text-sm text-amber-950">
                                <span className="font-semibold">{alert.type}</span>: {alert.title}
                                <span className="text-amber-800"> / {alert.recommended_action}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </Panel>
    );
}

function DevelopmentLedgerDashboard({ apps = [] }) {
    if (!apps.length) return null;

    return (
        <Panel>
            <div className="border-b border-slate-200 px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 className="text-sm font-semibold text-slate-950">Development Ledger</h3>
                        <p className="mt-0.5 text-xs text-slate-500">App progress against master vision, roadmap, blockers, and readiness.</p>
                    </div>
                    <Badge>{apps.length}</Badge>
                </div>
            </div>
            <div className="grid gap-3 p-4 lg:grid-cols-2">
                {apps.map((app) => (
                    <div key={app.app_slug} className="rounded-md border border-slate-200 bg-white p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h4 className="text-sm font-semibold text-slate-950">{app.app_name}</h4>
                                <p className="mt-0.5 text-xs text-slate-500">{app.master_vision ?? 'No master vision recorded'}</p>
                            </div>
                            <Status value={app.latest_status} />
                        </div>
                        <div className="mt-3 grid gap-2 text-xs text-slate-600 sm:grid-cols-2">
                            <div className="rounded-md bg-slate-50 p-2">
                                <div className="font-semibold text-slate-800">Completed</div>
                                <div>{app.completed_work?.length ? app.completed_work[0].summary : 'None recorded'}</div>
                            </div>
                            <div className="rounded-md bg-slate-50 p-2">
                                <div className="font-semibold text-slate-800">Current</div>
                                <div>{app.current_work?.length ? app.current_work[0].summary : 'No active work recorded'}</div>
                            </div>
                            <div className="rounded-md bg-slate-50 p-2">
                                <div className="font-semibold text-slate-800">Due Next</div>
                                <div>{app.due_next}</div>
                            </div>
                            <div className="rounded-md bg-slate-50 p-2">
                                <div className="font-semibold text-slate-800">Blockers</div>
                                <div>{app.blockers?.length ? app.blockers[0].summary : 'None'}</div>
                            </div>
                        </div>
                        <div className="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-3">
                            <div>Latest commit: <span className="font-medium text-slate-800">{app.latest_commit ?? 'none'}</span></div>
                            <div>Demo: <span className="font-medium text-slate-800">{app.demo_readiness}</span></div>
                            <div>Production: <span className="font-medium text-slate-800">{app.production_readiness}</span></div>
                        </div>
                        {app.roadmap_phases?.length > 0 && (
                            <div className="mt-3 flex flex-wrap gap-1">
                                {app.roadmap_phases.slice(0, 5).map((phase) => (
                                    <Badge key={phase.phase_key} tone={tones[phase.status] ?? tones.inactive}>{phase.title}</Badge>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </Panel>
    );
}

function RunnerAgents({ runnerAgents, monitorSummary }) {
    const runnerHealth = Object.fromEntries((monitorSummary?.runners ?? []).map((runner) => [runner.id, runner.health]));

    return (
        <Panel>
            <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <div>
                    <h3 className="text-sm font-semibold text-slate-950">Runner Agents</h3>
                    <p className="mt-0.5 text-xs text-slate-500">Registered local machines. Tokens are never shown after creation.</p>
                </div>
                <Badge>{runnerAgents.length}</Badge>
            </div>
            {runnerAgents.length === 0 ? (
                <EmptyState
                    title="No runners registered"
                    description="Create one with php artisan miriam:runner-agent:create. Local runner execution is a future phase."
                />
            ) : (
                <div className="divide-y divide-slate-100">
                    {runnerAgents.map((runner) => (
                        <div key={runner.id} className="p-4">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h4 className="font-semibold text-slate-950">{runner.name}</h4>
                                        <Status value={runner.status} />
                                        {runnerHealth[runner.id] && <Status value={runnerHealth[runner.id]} />}
                                    </div>
                                    <div className="mt-1 text-xs text-slate-500">{runner.slug}</div>
                                </div>
                                <div className="text-left text-xs text-slate-500 sm:text-right">
                                    <div>Last seen: {runner.last_seen_at ?? 'Never'}</div>
                                    <div>{runner.machine_name ?? 'No machine'} {runner.os ? `/ ${runner.os}` : ''}</div>
                                </div>
                            </div>
                            {runner.local_project_path && <div className="mt-2 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">{runner.local_project_path}</div>}
                        </div>
                    ))}
                </div>
            )}
        </Panel>
    );
}

function JobCard({ job }) {
    const [open, setOpen] = useState(false);
    const canCancel = ['queued', 'waiting_for_runner'].includes(job.status);
    const canPause = ['queued', 'waiting_for_runner', 'running'].includes(job.status);
    const canResume = job.status === 'paused';
    const cancel = () => router.patch(route('product-brain.development-manager.jobs.cancel', job.id), {}, { preserveScroll: true });
    const stop = () => router.patch(route('product-brain.development-manager.jobs.stop', job.id), {}, { preserveScroll: true });
    const pause = () => router.patch(route('product-brain.development-manager.jobs.pause', job.id), {}, { preserveScroll: true });
    const resume = () => router.patch(route('product-brain.development-manager.jobs.resume', job.id), {}, { preserveScroll: true });
    const createRelease = () => router.post(route('product-brain.development-manager.jobs.release-packages.store', job.id), {}, { preserveScroll: true });
    const approveRelease = (packageId) => router.post(route('product-brain.development-manager.release-packages.approve', packageId), {}, { preserveScroll: true });
    const rejectRelease = (packageId) => router.post(route('product-brain.development-manager.release-packages.reject', packageId), {}, { preserveScroll: true });
    const failureAction = (name, failureId) => {
        router.post(route(`product-brain.development-manager.failures.${name}`, failureId), {}, { preserveScroll: true });
    };

    return (
        <div className="border-b border-slate-100 p-4 last:border-b-0">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h4 className="break-words font-semibold text-slate-950">{job.title}</h4>
                        <Status value={job.status} />
                        {job.multi_phase_enabled && <Badge tone={tones.running}>controlled multi-phase</Badge>}
                    </div>
                    <div className="mt-1 text-xs text-slate-500">
                        {job.program?.name ?? 'No program'} / {job.completed_phases} of {job.total_phases} phases / Runner: {job.runner?.name ?? 'waiting'}
                    </div>
                    {job.managed_app && (
                        <div className="mt-1 text-xs text-slate-500">
                            App: {job.managed_app.name} / {job.managed_app.tech_stack ?? 'unknown'} / {job.local_project_path_snapshot ?? 'no path snapshot'}
                        </div>
                    )}
                    {job.multi_phase_enabled && (
                        <div className="mt-2 h-2 max-w-sm overflow-hidden rounded-full bg-slate-100">
                            <div className="h-full bg-emerald-500" style={{ width: `${job.total_phases ? Math.round((job.completed_phases / job.total_phases) * 100) : 0}%` }} />
                        </div>
                    )}
                    {job.error_message && <p className="mt-2 text-sm text-rose-700">{job.error_message}</p>}
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                    <button type="button" onClick={() => setOpen(!open)} className={secondaryButton}>{open ? 'Hide details' : 'View details'}</button>
                    {canCancel && <button type="button" onClick={cancel} className={secondaryButton}>Cancel</button>}
                    {canPause && <button type="button" onClick={pause} className={secondaryButton}>Pause</button>}
                    {canResume && <button type="button" onClick={resume} className={secondaryButton}>Resume</button>}
                    {job.status === 'completed' && job.managed_app && <button type="button" onClick={createRelease} className={primaryButton}>Create Release Package</button>}
                    {job.failures?.length > 0 && <button type="button" onClick={stop} className={secondaryButton}>Stop Job</button>}
                </div>
            </div>

            {open && (
                <div className="mt-4 grid gap-4 xl:grid-cols-2">
                    <div>
                        {job.failures?.length > 0 && (
                            <div className="mb-4">
                                <h5 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Active Failures</h5>
                                <div className="space-y-2">
                                    {job.failures.map((failure) => (
                                        <div key={failure.id} className="rounded-md border border-rose-200 bg-rose-50 p-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div className="text-sm font-semibold text-rose-950">{failure.title}</div>
                                                <Status value={failure.status} />
                                            </div>
                                            <div className="mt-1 text-xs text-rose-700">
                                                #{failure.id} / {failure.failure_type ?? 'unknown'} / {failure.severity} / {failure.phase?.phase_key ?? 'no phase'}
                                            </div>
                                            {failure.summary && <p className="mt-2 text-sm leading-5 text-rose-900">{failure.summary}</p>}
                                            {failure.error_excerpt && (
                                                <details className="mt-2">
                                                    <summary className="cursor-pointer text-xs font-semibold text-rose-800">Error excerpt</summary>
                                                    <pre className="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded bg-white p-2 text-xs text-slate-700">{failure.error_excerpt}</pre>
                                                </details>
                                            )}
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {failure.can_auto_fix && <button type="button" onClick={() => failureAction('apply-fix', failure.id)} className={secondaryButton}>Apply Fix</button>}
                                                <button type="button" onClick={() => failureAction('manual-fix', failure.id)} className={secondaryButton}>Manual Fix</button>
                                                <button type="button" onClick={() => failureAction('resume-after-manual-fix', failure.id)} className={secondaryButton}>Resume After Manual Fix</button>
                                                <button type="button" onClick={() => failureAction('rollback-phase', failure.id)} className={secondaryButton}>Rollback Phase</button>
                                            </div>
                                            {failure.fix_attempts?.length > 0 && (
                                                <div className="mt-3 rounded-md bg-white p-2 text-xs text-slate-600">
                                                    <div className="font-semibold text-slate-800">Fix attempts</div>
                                                    {failure.fix_attempts.map((attempt) => (
                                                        <div key={attempt.id} className="mt-1 flex flex-wrap items-center gap-2">
                                                            <span>#{attempt.attempt_number}</span>
                                                            <Status value={attempt.status} />
                                                            {attempt.error_message && <span>{attempt.error_message}</span>}
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                        <h5 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Phase Runs</h5>
                        <div className="space-y-2">
                            {job.phase_runs.map((phaseRun) => (
                                <div key={phaseRun.id} className="rounded-md border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="text-sm font-semibold text-slate-950">{phaseRun.phase?.title ?? 'No phase'}</div>
                                        <Status value={phaseRun.status} />
                                    </div>
                                    <div className="mt-1 text-xs text-slate-500">
                                        #{phaseRun.phase_order} / {phaseRun.phase?.phase_key ?? 'N/A'} / {phaseRun.saved_prompt?.title ?? 'No prompt'}
                                    </div>
                                    {phaseRun.is_dry_run && (
                                        <div className="mt-2 rounded-md border border-cyan-200 bg-cyan-50 px-3 py-2 text-xs font-medium text-cyan-800">
                                            Dry-run received. Codex execution was skipped and the job is waiting for real execution/review.
                                        </div>
                                    )}
                                    {phaseRun.is_one_phase_execution && (
                                        <div className="mt-2 rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-medium text-violet-800">
                                            One-phase execution only. Multi-phase autonomous execution is not enabled yet.
                                        </div>
                                    )}
                                    {job.multi_phase_enabled && (
                                        <div className="mt-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">
                                            Controlled multi-phase gate: backup, validation, parser clarity, safety scan, and failure checks are required before the next phase is assigned.
                                        </div>
                                    )}
                                    {phaseRun.validation?.runner_dry_run && (
                                        <div className="mt-2 text-xs text-slate-500">
                                            Runner dry-run: {phaseRun.validation.runner_dry_run} / Codex execution: {phaseRun.validation.codex_execution ?? 'skipped'}
                                        </div>
                                    )}
                                    {phaseRun.is_one_phase_execution && (
                                        <div className="mt-3 space-y-2 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                                            <div>Safety scanner: <span className="font-semibold text-slate-900">{phaseRun.safety_scanner ?? 'unknown'}</span></div>
                                            <div>Changed files: <span className="font-semibold text-slate-900">{phaseRun.files_changed?.length ?? 0}</span></div>
                                            <div>Backups: <span className="font-semibold text-slate-900">{phaseRun.backup_paths?.length ?? 0}</span></div>
                                            <div>Manifest before/after: <span className="font-semibold text-slate-900">{phaseRun.manifest_before_available ? 'yes' : 'no'} / {phaseRun.manifest_after_available ? 'yes' : 'no'}</span></div>
                                            {phaseRun.files_changed?.length > 0 && (
                                                <div className="max-h-24 overflow-auto rounded bg-white p-2">
                                                    {phaseRun.files_changed.map((file) => <div key={file}>{file}</div>)}
                                                </div>
                                            )}
                                            {phaseRun.validation && (
                                                <pre className="max-h-24 overflow-auto rounded bg-white p-2">{JSON.stringify(phaseRun.validation, null, 2)}</pre>
                                            )}
                                            {(phaseRun.codex_stdout_preview || phaseRun.codex_stderr_preview) && (
                                                <details>
                                                    <summary className="cursor-pointer font-semibold text-slate-700">Codex output preview</summary>
                                                    <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap rounded bg-slate-950 p-2 text-slate-100">{phaseRun.codex_stdout_preview || 'No stdout'}</pre>
                                                    {phaseRun.codex_stderr_preview && <pre className="mt-2 max-h-32 overflow-auto whitespace-pre-wrap rounded bg-rose-950 p-2 text-rose-100">{phaseRun.codex_stderr_preview}</pre>}
                                                </details>
                                            )}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                        {job.release_packages?.length > 0 && (
                            <div className="mt-4">
                                <h5 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Release Packages</h5>
                                <div className="space-y-2">
                                    {job.release_packages.map((pkg) => (
                                        <div key={pkg.id} className="rounded-md border border-slate-200 bg-white p-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div className="text-sm font-semibold text-slate-950">#{pkg.id} {pkg.version_label ?? pkg.title}</div>
                                                <Status value={pkg.status} />
                                            </div>
                                            <div className="mt-1 text-xs text-slate-500">Approval: {pkg.approval_status ?? 'none'} / Packaged: {pkg.packaged_at ?? 'not yet'}</div>
                                            {pkg.package_path && <div className="mt-2 break-all rounded bg-slate-50 p-2 text-xs text-slate-600">{pkg.package_path}</div>}
                                            <div className="mt-2 text-xs text-slate-500">
                                                Size: {pkg.package_size_bytes ?? 'N/A'} bytes / Included: {pkg.files_included_count ?? 0} / Excluded: {pkg.files_excluded_count ?? 0}
                                            </div>
                                            {pkg.qa_checklist?.length > 0 && (
                                                <div className="mt-3 space-y-1 rounded-md bg-slate-50 p-3 text-xs text-slate-600">
                                                    {pkg.qa_checklist.map((item) => (
                                                        <div key={item.label} className="flex flex-col gap-0.5 sm:flex-row sm:items-center sm:justify-between">
                                                            <span className="font-medium text-slate-800">{item.label}</span>
                                                            <span>{item.status} / {item.detail}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                            <div className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                                                Deployment is not automated. Approval means approved for manual deployment only.
                                            </div>
                                            {['approval_required', 'packaged'].includes(pkg.status) && (
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <button type="button" onClick={() => approveRelease(pkg.id)} className={primaryButton}>Approve Manual Deploy</button>
                                                    <button type="button" onClick={() => rejectRelease(pkg.id)} className={secondaryButton}>Reject</button>
                                                </div>
                                            )}
                                            {pkg.error_message && <p className="mt-2 text-xs text-rose-700">{pkg.error_message}</p>}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                    <div>
                        <h5 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Timeline</h5>
                        <div className="space-y-2">
                            {job.events.length === 0 && <div className="text-sm text-slate-500">No events yet.</div>}
                            {job.events.map((event) => (
                                <div key={event.id} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <div className="text-sm font-semibold text-slate-900">{event.title}</div>
                                    <div className="mt-1 text-xs text-slate-500">{event.event_type} / {event.created_at}</div>
                                    {event.is_dry_run && <Badge tone={tones.output_received}>dry run</Badge>}
                                    {event.body && <p className="mt-2 text-sm leading-5 text-slate-600">{event.body}</p>}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

function DevelopmentJobs({ jobs }) {
    return (
        <Panel>
            <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <div>
                    <h3 className="text-sm font-semibold text-slate-950">Development Jobs</h3>
                    <p className="mt-0.5 text-xs text-slate-500">Cloud-side queue and gatekeeper. The local runner must acknowledge each phase before continuing.</p>
                </div>
                <Badge>{jobs.length}</Badge>
            </div>
            {jobs.length === 0 ? (
                <EmptyState title="No jobs yet" description="Start a cloud-side job to prepare prompt phase runs for a future local runner." />
            ) : (
                <div>{jobs.map((job) => <JobCard key={job.id} job={job} />)}</div>
            )}
        </Panel>
    );
}

export default function DevelopmentManager({ program, runnerAgents, managedApps = [], jobs, monitorSummary, developmentLedgerDashboard = [] }) {
    const [starting, setStarting] = useState(false);
    const [startingMulti, setStartingMulti] = useState(false);
    const [appSlug, setAppSlug] = useState('');
    const startJob = () => {
        setStarting(true);
        router.post(route('product-brain.development-manager.jobs.store'), appSlug ? { app_slug: appSlug } : {}, {
            preserveScroll: true,
            onFinish: () => setStarting(false),
        });
    };
    const startMultiJob = () => {
        setStartingMulti(true);
        router.post(route('product-brain.development-manager.jobs.store-multi'), appSlug ? { app_slug: appSlug } : {}, {
            preserveScroll: true,
            onFinish: () => setStartingMulti(false),
        });
    };

    return (
        <AuthenticatedLayout title="Development Manager" subtitle="Cloud runner foundation for Miriam builds. Local runner execution is intentionally not implemented yet.">
            <PageHeader
                title="Miriam Development Manager"
                subtitle="Miriam Cloud prepares jobs and receives runner status. A future local Windows runner will poll this API over outbound HTTPS."
                meta={program ? `Active program: ${program.name}` : 'No active Prompt OS program'}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <select value={appSlug} onChange={(event) => setAppSlug(event.target.value)} className="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm">
                            <option value="">Prompt OS default</option>
                            {managedApps.map((app) => (
                                <option key={app.slug} value={app.slug}>{app.name}</option>
                            ))}
                        </select>
                        <button type="button" onClick={startJob} disabled={starting || (!program && !appSlug)} className={secondaryButton}>{starting ? 'Creating...' : 'Start One-Phase Job'}</button>
                        <button type="button" onClick={startMultiJob} disabled={startingMulti || (!program && !appSlug)} className={primaryButton}>{startingMulti ? 'Creating...' : 'Start Controlled Multi-Phase'}</button>
                    </div>
                )}
            />

            <div className="mb-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                <div className="font-semibold">Safety boundary</div>
                <div>Controlled multi-phase execution is enabled only when both Miriam Cloud and the local runner opt in. It stops on any failure, safety risk, unclear parser result, approval gate, or manual-fix requirement. Auto-deploy and Git-first workflows remain disabled.</div>
            </div>

            <div className="mb-4">
                <MonitorSummary monitorSummary={monitorSummary} />
            </div>

            <div className="mb-4">
                <DevelopmentLedgerDashboard apps={developmentLedgerDashboard} />
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                <RunnerAgents runnerAgents={runnerAgents} monitorSummary={monitorSummary} />
                <DevelopmentJobs jobs={jobs} />
            </div>
        </AuthenticatedLayout>
    );
}
