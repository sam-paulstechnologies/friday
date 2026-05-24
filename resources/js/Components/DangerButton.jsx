export default function DangerButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center justify-center gap-2 rounded-xl border border-transparent bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-rose-200 transition duration-200 hover:-translate-y-0.5 hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500/60 focus-visible:ring-offset-2 active:translate-y-0 ${
                    disabled && 'pointer-events-none opacity-50'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
