import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge, EmptyState, inputClass, PageSection, primaryButton, ProgressBar, secondaryButton, SectionTabs } from '@/Components/Ui';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const statusTone = {
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    partial: 'bg-amber-50 text-amber-700 ring-amber-100',
    missed: 'bg-rose-50 text-rose-700 ring-rose-100',
    upcoming: 'bg-slate-100 text-slate-600 ring-slate-200',
};

export default function Index({ plan, summary, today, todayScripture, translations, selectedTranslationCode, days, nextUnread, journals, notes }) {
    const [tab, setTab] = useState('reading');

    return (
        <AuthenticatedLayout title="Spiritual" subtitle="90-day Bible reading tracker, journal, and study notes.">
            <Head title="Spiritual" />

            {!plan ? (
                <EmptyState title="No Bible reading plan found" description="Run the SpiritualBibleReadingPlanSeeder to create the default 90-day plan." />
            ) : (
                <div className="space-y-5">
                    <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                        <div className="grid gap-5 p-5 lg:grid-cols-[1.3fr_0.7fr]">
                            <div>
                                <Badge tone="bg-emerald-50 text-emerald-700 ring-emerald-100">{plan.plan_type} / {plan.duration_days} days</Badge>
                                <h2 className="mt-3 text-2xl font-semibold text-slate-950">{plan.name}</h2>
                                <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Read through every canonical chapter and keep your reflections connected to Miriam's personal foundation.</p>
                                <ProgressBar value={summary.percentage_complete} className="mt-5" />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <Metric label="Complete" value={`${summary.percentage_complete}%`} />
                                <Metric label="Remaining" value={summary.chapters_remaining} />
                                <Metric label="Current day" value={summary.current_day} />
                                <Metric label="Streak" value={summary.current_streak} />
                            </div>
                        </div>
                    </section>

                    <SectionTabs
                        value={tab}
                        onChange={setTab}
                        items={['reading', 'overview', 'journal', 'notes'].map((item) => ({ value: item, label: item.charAt(0).toUpperCase() + item.slice(1) }))}
                    />

                    {tab === 'reading' && <ReadingTab today={today} todayScripture={todayScripture} translations={translations} selectedTranslationCode={selectedTranslationCode} summary={summary} nextUnread={nextUnread} />}
                    {tab === 'overview' && <OverviewTab days={days} />}
                    {tab === 'journal' && <JournalTab today={today} journals={journals} />}
                    {tab === 'notes' && <NotesTab today={today} notes={notes} />}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function Metric({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50/80 px-3 py-2">
            <div className="text-[11px] font-semibold uppercase text-slate-500">{label}</div>
            <div className="mt-1 text-lg font-semibold text-slate-950">{value}</div>
        </div>
    );
}

function ReadingTab({ today, todayScripture, translations, selectedTranslationCode, summary, nextUnread }) {
    const continueReading = () => {
        const element = document.getElementById(nextUnread ? `chapter-${nextUnread.id}` : 'today-reading');
        element?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    const changeTranslation = (event) => {
        router.get(route('spiritual.index'), { translation: event.target.value }, { preserveScroll: true, preserveState: true });
    };

    return (
        <div className="space-y-6">
            <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
                <PageSection
                    title={`Today's reading: Day ${today?.day_number ?? 1}`}
                    description={`${today?.completed_chapters ?? 0} of ${today?.total_chapters ?? 0} chapters complete`}
                    action={<button type="button" onClick={continueReading} className={secondaryButton}>Continue Reading</button>}
                >
                    <div id="today-reading" className="divide-y divide-slate-100">
                        {(today?.chapters ?? []).map((chapter) => <ChapterRow key={chapter.id} chapter={chapter} />)}
                    </div>
                </PageSection>

                <PageSection title="Progress summary" description="Live progress from your checked chapters.">
                    <div className="grid gap-3 p-4">
                        <Metric label="Total chapters" value={summary.total_chapters} />
                        <Metric label="Completed" value={summary.chapters_completed} />
                        <Metric label="Behind / ahead" value={`${summary.behind_count} / ${summary.ahead_count}`} />
                        <Metric label="Longest streak" value={summary.longest_streak} />
                    </div>
                </PageSection>
            </div>

            <PageSection
                title="Bible reader"
                description="Verse text appears for imported translations. NIV and Telugu are ready for licensed imports."
                action={
                    <select value={selectedTranslationCode ?? ''} onChange={changeTranslation} className={`${inputClass} min-w-48`}>
                        {translations.map((translation) => (
                            <option key={translation.code} value={translation.code}>
                                {translation.code} / {translation.verses_count > 0 ? `${translation.verses_count} verses` : 'not imported'}
                            </option>
                        ))}
                    </select>
                }
            >
                <div className="space-y-4 p-4">
                    {todayScripture.length === 0 ? (
                        <EmptyState title="No scripture text imported yet" description="Run the Bible content seeder or import a licensed translation JSON file." />
                    ) : todayScripture.map((chapter) => (
                        <article key={`${chapter.book_name}-${chapter.chapter_number}`} className="rounded-lg border border-slate-100 bg-slate-50/70 p-4">
                            <h3 className="font-semibold text-slate-950">{chapter.book_name} {chapter.chapter_number}</h3>
                            {chapter.verses.length === 0 ? (
                                <p className="mt-3 text-sm text-slate-500">This chapter has not been imported for the selected translation.</p>
                            ) : (
                                <div className="mt-4 space-y-2 text-sm leading-7 text-slate-700">
                                    {chapter.verses.map((verse) => (
                                        <p key={verse.id}>
                                            <sup className="mr-1 font-bold text-slate-400">{verse.verse_number}</sup>
                                            {verse.text}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            </PageSection>
        </div>
    );
}

function ChapterRow({ chapter }) {
    const toggle = () => router.post(route('spiritual.readings.toggle'), { chapter_id: chapter.id }, { preserveScroll: true });

    return (
        <label id={`chapter-${chapter.id}`} className="flex cursor-pointer items-center justify-between gap-4 px-4 py-2.5 text-sm transition hover:bg-slate-50">
            <span>
                <span className="font-semibold text-slate-950">{chapter.book_name}</span>
                <span className="ml-2 text-sm text-slate-500">Chapter {chapter.chapter_number}</span>
            </span>
                    <input type="checkbox" checked={chapter.is_read} onChange={toggle} className="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
        </label>
    );
}

function OverviewTab({ days }) {
    return (
        <PageSection title="90-day overview" description="Completed, partial, missed, and upcoming reading blocks.">
            <div className="grid grid-cols-5 gap-2 p-5 sm:grid-cols-9 md:grid-cols-[repeat(15,minmax(0,1fr))] lg:grid-cols-[repeat(18,minmax(0,1fr))]">
                {days.map((day) => (
                    <div key={day.id} className={`rounded-md p-2 text-center ring-1 ${statusTone[day.status]}`}>
                        <div className="text-xs font-semibold">{day.day_number}</div>
                        <div className="mt-1 text-[10px] font-semibold uppercase">{day.status}</div>
                    </div>
                ))}
            </div>
        </PageSection>
    );
}

function JournalTab({ today, journals }) {
    const form = useForm({ entry_date: new Date().toISOString().slice(0, 10), title: '', content: '', bible_reading_plan_day_id: today?.id ?? '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('spiritual.journal.store'), { preserveScroll: true, onSuccess: () => form.reset('title', 'content') });
    };

    return <EntryPanel form={form} submit={submit} entries={journals} title="Daily journal" routeLabel="Save Journal" includeDate />;
}

function NotesTab({ today, notes }) {
    const form = useForm({ title: '', content: '', book_name: '', chapter_number: '', tags: '', bible_reading_plan_day_id: today?.id ?? '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('spiritual.notes.store'), { preserveScroll: true, onSuccess: () => form.reset('title', 'content', 'book_name', 'chapter_number', 'tags') });
    };

    return <EntryPanel form={form} submit={submit} entries={notes} title="Study notes" routeLabel="Save Note" includeChapter />;
}

function EntryPanel({ form, submit, entries, title, routeLabel, includeDate = false, includeChapter = false }) {
    return (
        <div className="grid gap-5 lg:grid-cols-[420px_1fr]">
            <PageSection title={title} description="Capture reflection without leaving your reading flow.">
                <form onSubmit={submit} className="space-y-3 p-4">
                    {includeDate && <input type="date" value={form.data.entry_date} onChange={(e) => form.setData('entry_date', e.target.value)} className={`${inputClass} w-full`} />}
                    <input type="text" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Title" className={`${inputClass} w-full`} />
                    {includeChapter && (
                        <div className="grid grid-cols-2 gap-3">
                            <input type="text" value={form.data.book_name} onChange={(e) => form.setData('book_name', e.target.value)} placeholder="Book" className={`${inputClass} w-full`} />
                            <input type="number" min="1" value={form.data.chapter_number} onChange={(e) => form.setData('chapter_number', e.target.value)} placeholder="Chapter" className={`${inputClass} w-full`} />
                        </div>
                    )}
                    <textarea value={form.data.content} onChange={(e) => form.setData('content', e.target.value)} placeholder="Write here..." rows="8" className={`${inputClass} w-full`} />
                    {includeChapter && <input type="text" value={form.data.tags} onChange={(e) => form.setData('tags', e.target.value)} placeholder="Tags, comma separated" className={`${inputClass} w-full`} />}
                    <button type="submit" disabled={form.processing} className={primaryButton}>{routeLabel}</button>
                </form>
            </PageSection>
            <PageSection title="Recent entries">
                <div className="divide-y divide-slate-100">
                    {entries.length === 0 ? <EmptyState title="No entries yet" /> : entries.map((entry) => (
                        <article key={entry.id} className="p-4">
                            <div className="font-bold text-slate-950">{entry.title || entry.entry_date || 'Untitled'}</div>
                            <p className="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">{entry.content}</p>
                        </article>
                    ))}
                </div>
            </PageSection>
        </div>
    );
}
