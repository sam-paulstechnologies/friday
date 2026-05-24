import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, ...props },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    return (
        <input
            {...props}
            type={type}
            className={
                'rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm shadow-slate-200/50 transition placeholder:text-slate-400 hover:border-slate-300 focus:border-emerald-500 focus:ring-emerald-500/30 disabled:bg-slate-50 disabled:text-slate-400 ' +
                className
            }
            ref={localRef}
        />
    );
});
