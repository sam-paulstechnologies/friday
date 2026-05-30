import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge, EmptyState, inputClass, primaryButton, secondaryButton, SectionTabs, PageSection } from '@/Components/Ui';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const roleTone = {
    owner: 'bg-rose-50 text-rose-700 ring-rose-100',
    admin: 'bg-amber-50 text-amber-700 ring-amber-100',
    member: 'bg-sky-50 text-sky-700 ring-sky-100',
    viewer: 'bg-slate-100 text-slate-700 ring-slate-200',
};

function Field({ label, error, children }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</span>
            <div className="mt-1">{children}</div>
            {error && <span className="mt-1 block text-sm text-rose-600">{error}</span>}
        </label>
    );
}

export default function Edit({ workspace, members = [], auditLogs = [], roles = [], canManageMembers = false, canManageRoles = false }) {
    const [tab, setTab] = useState('general');
    const settings = useForm({ name: workspace.name });
    const memberForm = useForm({ email: '', role: 'member' });

    const tabs = [
        { value: 'general', label: 'General' },
        { value: 'members', label: 'Members' },
        { value: 'roles', label: 'Roles' },
        { value: 'audit', label: 'Audit Log' },
    ];

    const updateRole = (member, role) => {
        router.patch(route('settings.workspace.members.update', member.id), { role, workspace_id: workspace.id }, { preserveScroll: true });
    };

    const removeMember = (member) => {
        router.delete(route('settings.workspace.members.destroy', member.id), {
            data: { workspace_id: workspace.id },
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout title="Workspace Settings" subtitle="Manage workspace access, roles, and recent administrative activity.">
            <div className="space-y-4">
                <SectionTabs items={tabs} value={tab} onChange={setTab} />

                {tab === 'general' && (
                    <PageSection title="General" description="Basic workspace details and ownership.">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                settings.patch(route('settings.workspace.update'), { data: { workspace_id: workspace.id }, preserveScroll: true });
                            }}
                            className="grid gap-4 p-4 md:grid-cols-[minmax(0,1fr)_220px]"
                        >
                            <Field label="Workspace name" error={settings.errors.name}>
                                <input value={settings.data.name} onChange={(event) => settings.setData('name', event.target.value)} className={`${inputClass} w-full`} />
                            </Field>
                            <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Owner</div>
                                <div className="mt-1 text-sm font-semibold text-slate-900">{workspace.owner?.name ?? 'No owner'}</div>
                                <div className="text-xs text-slate-500">{workspace.owner?.email}</div>
                            </div>
                            <div className="md:col-span-2">
                                <button type="submit" disabled={settings.processing} className={primaryButton}>Save workspace</button>
                            </div>
                        </form>
                    </PageSection>
                )}

                {tab === 'members' && (
                    <PageSection title="Members" description="Add existing users and review workspace membership.">
                        {canManageMembers && (
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    memberForm.post(route('settings.workspace.members.store'), {
                                        data: { ...memberForm.data, workspace_id: workspace.id },
                                        preserveScroll: true,
                                        onSuccess: () => memberForm.reset(),
                                    });
                                }}
                                className="grid gap-3 border-b border-slate-200 p-4 md:grid-cols-[minmax(0,1fr)_160px_auto]"
                            >
                                <input type="email" value={memberForm.data.email} onChange={(event) => memberForm.setData('email', event.target.value)} placeholder="member@example.com" className={inputClass} />
                                <select value={memberForm.data.role} onChange={(event) => memberForm.setData('role', event.target.value)} className={inputClass}>
                                    {roles.map((role) => <option key={role} value={role}>{role}</option>)}
                                </select>
                                <button type="submit" disabled={memberForm.processing} className={primaryButton}>Add member</button>
                                {(memberForm.errors.email || memberForm.errors.role) && <div className="text-sm text-rose-600 md:col-span-3">{memberForm.errors.email ?? memberForm.errors.role}</div>}
                            </form>
                        )}

                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        {['Member', 'Role', 'Joined', 'Actions'].map((column) => <th key={column} className="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{column}</th>)}
                                    </tr>
                                </thead>
                                <tbody>
                                    {members.map((member) => (
                                        <tr key={member.id}>
                                            <td className="border-b border-slate-100 px-4 py-3">
                                                <div className="font-semibold text-slate-900">{member.name}</div>
                                                <div className="text-xs text-slate-500">{member.email}</div>
                                            </td>
                                            <td className="border-b border-slate-100 px-4 py-3"><Badge tone={roleTone[member.role]}>{member.role}</Badge></td>
                                            <td className="border-b border-slate-100 px-4 py-3 text-slate-600">{member.joined_at ?? 'Unknown'}</td>
                                            <td className="border-b border-slate-100 px-4 py-3">
                                                <div className="flex flex-wrap gap-2">
                                                    <select disabled={!canManageRoles} value={member.role} onChange={(event) => updateRole(member, event.target.value)} className={`${inputClass} py-1 text-xs`}>
                                                        {roles.map((role) => <option key={role} value={role}>{role}</option>)}
                                                    </select>
                                                    <button type="button" disabled={!canManageMembers} onClick={() => removeMember(member)} className={`${secondaryButton} py-1 text-xs`}>Remove</button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </PageSection>
                )}

                {tab === 'roles' && (
                    <PageSection title="Roles" description="Workspace roles define who can administer, edit, or only view work.">
                        <div className="grid gap-3 p-4 md:grid-cols-2">
                            {[
                                ['owner', 'Full access, workspace settings, members, roles, reports, and all work.'],
                                ['admin', 'Manage projects, tasks, goals, portfolios, members, and reports.'],
                                ['member', 'Create and update accessible work; comment and complete assigned work.'],
                                ['viewer', 'Read-only access to allowed workspace and project data.'],
                            ].map(([role, description]) => (
                                <div key={role} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <Badge tone={roleTone[role]}>{role}</Badge>
                                    <p className="mt-2 text-sm text-slate-600">{description}</p>
                                </div>
                            ))}
                        </div>
                    </PageSection>
                )}

                {tab === 'audit' && (
                    <PageSection title="Audit Log" description="Recent administrative and security-relevant workspace actions.">
                        {auditLogs.length === 0 ? (
                            <EmptyState title="No audit activity yet" />
                        ) : (
                            <div className="divide-y divide-slate-100">
                                {auditLogs.map((log) => (
                                    <div key={log.id} className="grid gap-2 px-4 py-3 text-sm md:grid-cols-[220px_minmax(0,1fr)_180px]">
                                        <div className="font-semibold text-slate-900">{log.action.replaceAll('_', ' ')}</div>
                                        <div className="truncate text-slate-600">{log.actor?.name ?? 'System'}</div>
                                        <div className="text-xs text-slate-500 md:text-right">{log.created_at}</div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </PageSection>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
