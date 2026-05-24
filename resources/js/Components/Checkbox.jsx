export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-slate-300 text-emerald-600 shadow-sm focus:ring-emerald-500/40 ' +
                className
            }
        />
    );
}
