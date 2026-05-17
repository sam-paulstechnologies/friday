export default function ApplicationLogo(props) {
    return (
        <div
            {...props}
            className={`flex h-9 w-9 items-center justify-center rounded-lg bg-slate-950 text-sm font-bold text-white ${
                props.className ?? ''
            }`}
        >
            F
        </div>
    );
}
