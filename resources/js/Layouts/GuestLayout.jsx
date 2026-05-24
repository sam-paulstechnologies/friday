import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-[radial-gradient(circle_at_top_left,_rgba(16,185,129,0.14),_transparent_30%),linear-gradient(135deg,_#ffffff,_#f8fafc_58%,_#ecfeff)] px-4 pt-6 sm:justify-center sm:pt-0">
            <div className="flex flex-col items-center text-center">
                <Link href="/">
                    <ApplicationLogo className="h-16 w-16 fill-current text-slate-700" />
                </Link>
                <div className="mt-3 text-xl font-bold tracking-tight text-slate-950">Miriam</div>
                <div className="mt-1 text-sm text-slate-500">Friday work OS</div>
            </div>

            <div className="mt-6 w-full overflow-hidden rounded-3xl border border-slate-200 bg-white/95 px-6 py-6 shadow-xl shadow-slate-200/80 backdrop-blur sm:max-w-md">
                {children}
            </div>
        </div>
    );
}
