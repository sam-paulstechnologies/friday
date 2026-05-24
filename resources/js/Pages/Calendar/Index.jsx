import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { EmptyState, Panel, primaryButton, secondaryButton } from '@/Components/Ui';

const typeClasses = {
    task_start: 'bg-blue-50 text-blue-700 ring-blue-100',
    task_due: 'bg-amber-50 text-amber-700 ring-amber-100',
    project_start: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    project_due: 'bg-violet-50 text-violet-700 ring-violet-100',
};

function daysForMonth(month) {
    const [year, monthIndex] = month.split('-').map(Number);
    const first = new Date(year, monthIndex - 1, 1);
    const start = new Date(first);
    start.setDate(first.getDate() - first.getDay());

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(start);
        date.setDate(start.getDate() + index);
        return date;
    });
}

function formatDate(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export default function Index({ month, monthLabel, previousMonth, nextMonth, todayMonth, events }) {
    const days = daysForMonth(month);
    const eventsByDate = events.reduce((grouped, event) => ({
        ...grouped,
        [event.date]: [...(grouped[event.date] ?? []), event],
    }), {});

    return (
        <AuthenticatedLayout title="Calendar" subtitle="Internal Friday dates.">
            <Head title="Calendar" />

            <div className="space-y-5">
                <Panel className="overflow-hidden">
                    <div className="flex flex-col gap-3 bg-[radial-gradient(circle_at_top_left,_rgba(14,165,233,0.12),_transparent_30%),linear-gradient(135deg,_#ffffff,_#f8fafc_58%,_#eff6ff)] p-5 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Internal calendar</p>
                        <h2 className="mt-1 text-xl font-semibold tracking-tight text-slate-950">{monthLabel}</h2>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link className={secondaryButton} href={route('calendar.index', { month: previousMonth })}>
                            Previous
                        </Link>
                        <Link className={secondaryButton} href={route('calendar.index', { month: todayMonth })}>
                            Today
                        </Link>
                        <Link className={primaryButton} href={route('calendar.index', { month: nextMonth })}>
                            Next
                        </Link>
                    </div>
                    </div>
                </Panel>

                <section className="premium-scrollbar overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
                    <div className="min-w-[860px]">
                    <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => (
                            <div key={day} className="p-3">{day}</div>
                        ))}
                    </div>
                    <div className="grid grid-cols-7">
                        {days.map((day) => {
                            const date = formatDate(day);
                            const inMonth = date.startsWith(month);

                            return (
                                <div key={date} className={`min-h-32 border-b border-r border-slate-100 p-2 transition hover:bg-slate-50 ${inMonth ? 'bg-white' : 'bg-slate-50 text-slate-400'}`}>
                                    <div className="text-xs font-semibold">{day.getDate()}</div>
                                    <div className="mt-2 space-y-1">
                                        {(eventsByDate[date] ?? []).map((event, index) => (
                                            <Link
                                                key={`${event.type}-${event.url}-${index}`}
                                                href={event.url}
                                                className={`block rounded-xl px-2 py-1 text-xs font-semibold ring-1 transition hover:brightness-95 ${typeClasses[event.type]}`}
                                            >
                                                <span className="block">{event.label}</span>
                                                <span className="block truncate font-medium">{event.title}</span>
                                            </Link>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    </div>
                </section>
                {events.length === 0 && <Panel><EmptyState title="No scheduled work this month" description="Task and project start or due dates will appear on the calendar." /></Panel>}
            </div>
        </AuthenticatedLayout>
    );
}
