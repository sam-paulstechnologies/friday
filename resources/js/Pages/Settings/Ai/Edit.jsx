import { inputClass, primaryButton, secondaryButton } from '@/Components/Ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

function FieldError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="mt-1 text-sm font-medium text-rose-600">{message}</p>;
}

export default function Edit({ settings, modelOptions }) {
    const flash = usePage().props.flash ?? {};
    const { data, setData, patch, processing, errors, reset } = useForm({
        api_key: '',
        default_model: settings.default_model ?? 'gpt-4o-mini',
        planner_model: settings.planner_model ?? 'gpt-5.4-mini',
        max_tasks_sent: settings.max_tasks_sent ?? 30,
        max_output_tokens: settings.max_output_tokens ?? 1200,
        is_enabled: settings.is_enabled ?? false,
        test_connection: false,
    });

    const submit = (event) => {
        event.preventDefault();

        patch(route('settings.ai.update'), {
            preserveScroll: true,
            onSuccess: () => reset('api_key'),
        });
    };

    const testConnection = () => {
        router.patch(route('settings.ai.update'), { ...data, test_connection: true }, {
            preserveScroll: true,
            onSuccess: () => {
                setData('test_connection', false);
                reset('api_key');
            },
            onFinish: () => setData('test_connection', false),
        });
    };

    return (
        <AuthenticatedLayout title="AI Brain Settings" subtitle="Friday app settings for OpenAI configuration.">
            <Head title="AI Brain Settings" />

            <div className="mx-auto max-w-5xl space-y-6">
                <section className="rounded-lg border border-slate-200 bg-white shadow-sm shadow-slate-200/60">
                    <div className="border-b border-slate-200 p-5">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="text-sm font-semibold uppercase tracking-[0.14em] text-emerald-700">App Settings</p>
                                <h2 className="mt-2 text-xl font-bold text-slate-950">AI Brain Settings</h2>
                                <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                                    Store the OpenAI key for future Friday AI features. Saved keys are encrypted and only shown as a masked value.
                                </p>
                            </div>
                            <span className={`inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1 ring-inset ${
                                settings.has_api_key
                                    ? 'bg-emerald-50 text-emerald-700 ring-emerald-100'
                                    : 'bg-slate-100 text-slate-600 ring-slate-200'
                            }`}>
                                {settings.has_api_key ? 'Key saved' : 'No key saved'}
                            </span>
                        </div>
                    </div>

                    {(flash.success || flash.error) && (
                        <div className={`mx-5 mt-5 rounded-lg border px-4 py-3 text-sm font-semibold ${
                            flash.success
                                ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                : 'border-rose-100 bg-rose-50 text-rose-700'
                        }`}>
                            {flash.success || flash.error}
                        </div>
                    )}

                    <form onSubmit={submit} className="space-y-6 p-5">
                        <div className="grid gap-5 lg:grid-cols-[1fr_280px]">
                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">OpenAI API Key</span>
                                <input
                                    type="password"
                                    value={data.api_key}
                                    onChange={(event) => setData('api_key', event.target.value)}
                                    placeholder={settings.api_key_mask ?? 'sk-...'}
                                    autoComplete="new-password"
                                    className={`${inputClass} mt-2 block w-full`}
                                />
                                <p className="mt-2 text-sm text-slate-500">
                                    Leave blank to keep the saved key. The full key is never returned to this page after saving.
                                </p>
                                <FieldError message={errors.api_key} />
                            </label>

                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <div className="text-sm font-semibold text-slate-700">Stored key</div>
                                <div className="mt-2 break-all font-mono text-sm font-bold text-slate-950">
                                    {settings.api_key_mask ?? 'Blank'}
                                </div>
                                <p className="mt-2 text-xs leading-5 text-slate-500">Only a masked suffix is shown after save.</p>
                            </div>
                        </div>

                        <div className="grid gap-5 md:grid-cols-2">
                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">Default Chat Model</span>
                                <select
                                    value={data.default_model}
                                    onChange={(event) => setData('default_model', event.target.value)}
                                    className={`${inputClass} mt-2 block w-full`}
                                >
                                    {modelOptions.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                                <FieldError message={errors.default_model} />
                            </label>

                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">Planner / Priority Review Model</span>
                                <select
                                    value={data.planner_model}
                                    onChange={(event) => setData('planner_model', event.target.value)}
                                    className={`${inputClass} mt-2 block w-full`}
                                >
                                    {modelOptions.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                                <FieldError message={errors.planner_model} />
                            </label>
                        </div>

                        <div className="grid gap-5 md:grid-cols-2">
                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">Max Tasks Sent to AI</span>
                                <input
                                    type="number"
                                    min="1"
                                    max="200"
                                    value={data.max_tasks_sent}
                                    onChange={(event) => setData('max_tasks_sent', event.target.value)}
                                    className={`${inputClass} mt-2 block w-full`}
                                />
                                <FieldError message={errors.max_tasks_sent} />
                            </label>

                            <label className="block">
                                <span className="text-sm font-semibold text-slate-700">Max Output Tokens</span>
                                <input
                                    type="number"
                                    min="100"
                                    max="20000"
                                    value={data.max_output_tokens}
                                    onChange={(event) => setData('max_output_tokens', event.target.value)}
                                    className={`${inputClass} mt-2 block w-full`}
                                />
                                <FieldError message={errors.max_output_tokens} />
                            </label>
                        </div>

                        <div className="flex flex-col gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div className="text-sm font-semibold text-slate-950">AI Enabled</div>
                                <p className="mt-1 text-sm text-slate-500">AI remains disabled until an API key has been saved.</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setData('is_enabled', !data.is_enabled)}
                                className={`relative h-7 w-12 rounded-full transition ${data.is_enabled ? 'bg-emerald-600' : 'bg-slate-300'}`}
                                aria-pressed={data.is_enabled}
                            >
                                <span className={`absolute top-1 h-5 w-5 rounded-full bg-white shadow transition ${data.is_enabled ? 'left-6' : 'left-1'}`} />
                                <span className="sr-only">Toggle AI Enabled</span>
                            </button>
                        </div>

                        <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
                            <button type="button" disabled={processing} onClick={testConnection} className={secondaryButton}>
                                Test OpenAI Connection
                            </button>
                            <button type="submit" disabled={processing} className={primaryButton}>
                                Save Settings
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
