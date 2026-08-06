import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const toneClasses = {
    purple: {
        node: 'border-violet-400/70 bg-violet-500/15 text-violet-50 shadow-[0_0_28px_rgba(168,85,247,0.20)]',
        chip: 'border-violet-400/40 bg-violet-500/15 text-violet-100',
        dot: 'bg-violet-400',
        edge: '#a78bfa',
    },
    blue: {
        node: 'border-blue-400/70 bg-blue-500/15 text-blue-50 shadow-[0_0_18px_rgba(59,130,246,0.16)]',
        chip: 'border-blue-400/40 bg-blue-500/15 text-blue-100',
        dot: 'bg-blue-400',
        edge: '#60a5fa',
    },
    amber: {
        node: 'border-amber-400/80 bg-amber-500/15 text-amber-50 shadow-[0_0_18px_rgba(245,158,11,0.18)]',
        chip: 'border-amber-400/40 bg-amber-500/15 text-amber-100',
        dot: 'bg-amber-400',
        edge: '#f59e0b',
    },
    yellow: {
        node: 'border-yellow-400/70 bg-yellow-500/15 text-yellow-50 shadow-[0_0_18px_rgba(234,179,8,0.16)]',
        chip: 'border-yellow-400/40 bg-yellow-500/15 text-yellow-100',
        dot: 'bg-yellow-400',
        edge: '#eab308',
    },
    cyan: {
        node: 'border-cyan-300/70 bg-cyan-400/15 text-cyan-50 shadow-[0_0_18px_rgba(34,211,238,0.14)]',
        chip: 'border-cyan-300/40 bg-cyan-400/15 text-cyan-100',
        dot: 'bg-cyan-300',
        edge: '#22d3ee',
    },
    green: {
        node: 'border-lime-300/80 bg-lime-400/15 text-lime-50 shadow-[0_0_24px_rgba(132,204,22,0.18)]',
        chip: 'border-lime-300/40 bg-lime-400/15 text-lime-100',
        dot: 'bg-lime-300',
        edge: '#a3e635',
    },
    red: {
        node: 'border-red-400/80 bg-red-500/15 text-red-50 shadow-[0_0_20px_rgba(248,113,113,0.18)]',
        chip: 'border-red-400/40 bg-red-500/15 text-red-100',
        dot: 'bg-red-400',
        edge: '#f87171',
    },
    slate: {
        node: 'border-slate-500/70 bg-slate-700/25 text-slate-100',
        chip: 'border-slate-500/50 bg-slate-800 text-slate-200',
        dot: 'bg-slate-500',
        edge: '#64748b',
    },
};

const cx = (...classes) => classes.filter(Boolean).join(' ');
const endpoint = (template, values) => Object.entries(values).reduce((url, [key, value]) => url.replace(`__${key.toUpperCase()}__`, encodeURIComponent(value)), template || '');
const genericViewport = { x: 80, y: 40, scale: 0.82 };
const baseLayout = { version: 2, nodes: {}, hidden: [], customEdges: [], removedEdges: [], history: [] };

function defaultViewportFor(graph) {
    if (graph?.layout?.orientation === 'top-to-bottom') {
        return { x: -300, y: 34, scale: 0.82 };
    }

    return genericViewport;
}

function Icon({ type = 'circle', className = 'h-4 w-4' }) {
    const common = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' };
    const paths = {
        capture: <path d="M12 3a3 3 0 0 0-3 3v5a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3Zm-7 8a7 7 0 0 0 14 0M12 18v3M8 21h8" {...common} />,
        process: <path d="M4 7h16M4 12h16M4 17h10" {...common} />,
        decision: <path d="m12 3 9 9-9 9-9-9 9-9Z" {...common} />,
        record: <path d="M5 4h14v16H5V4Zm4 5h6M9 13h6M9 17h4" {...common} />,
        review: <path d="M5 12h4l2 7 4-14 2 7h2" {...common} />,
        action: <path d="M12 3v12m0-12 4 4m-4-4-4 4M5 15v4h14v-4" {...common} />,
        end: <path d="m5 12 4 4L19 6" {...common} />,
        root: <path d="M12 3v18M3 12h18M5 5l14 14M19 5 5 19" {...common} />,
        page: <path d="M5 3h10l4 4v14H5V3Zm10 0v5h5" {...common} />,
        route: <path d="M4 12h14m0 0-4-4m4 4-4 4" {...common} />,
        controller: <path d="M7 7h10v10H7V7Zm-3 3h3m10 0h3M4 14h3m10 0h3M10 4v3m4-3v3m-4 10v3m4-3v3" {...common} />,
        service: <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0-12v2m0 14v2m7.8-11-1.8 1m-12 0-1.8-1m13.6 8-1.8-1m-12 0-1.8 1" {...common} />,
        model: <path d="M4 7c0-2 16-2 16 0v10c0 2-16 2-16 0V7Zm0 0c0 2 16 2 16 0M4 12c0 2 16 2 16 0" {...common} />,
        table: <path d="M4 5h16v14H4V5Zm0 5h16M9 5v14M15 5v14" {...common} />,
        search: <path d="m21 21-4.3-4.3M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" {...common} />,
        filter: <path d="M4 5h16l-6 7v5l-4 2v-7L4 5Z" {...common} />,
        fullscreen: <path d="M8 3H3v5M16 3h5v5M8 21H3v-5M21 16v5h-5" {...common} />,
        exit: <path d="M9 3v5H4M15 3v5h5M9 21v-5H4M15 21v-5h5" {...common} />,
        reset: <path d="M7 7h7a5 5 0 1 1-4.6 7M7 7V3M7 7h4" {...common} />,
        edit: <path d="m4 16-.5 4.5L8 20l11-11-4-4L4 16Zm9-9 4 4" {...common} />,
        save: <path d="M5 3h12l2 2v16H5V3Zm4 0v6h6V3M8 17h8" {...common} />,
        undo: <path d="M7 7h7a5 5 0 1 1-4.7 6.7M7 7V3M7 7h4" {...common} />,
        connect: <path d="M7 7h.01M17 17h.01M8.8 8.8l6.4 6.4M5 5a3 3 0 1 0 4 4 3 3 0 0 0-4-4Zm10 10a3 3 0 1 0 4 4 3 3 0 0 0-4-4Z" {...common} />,
        disconnect: <path d="M9 9 5 5m14 14-4-4M8.5 15.5 7 17a3 3 0 0 1-4-4l2-2m10.5-2.5L17 7a3 3 0 0 1 4 4l-2 2M8 12h8" {...common} />,
        hide: <path d="M3 12s3.5-6 9-6 9 6 9 6a16 16 0 0 1-3 3.4M9.9 9.9A3 3 0 0 1 14.1 14.1M3 3l18 18" {...common} />,
        trash: <path d="M4 7h16M9 7V5h6v2m-8 0 1 14h8l1-14" {...common} />,
    };

    return <svg viewBox="0 0 24 24" className={className} aria-hidden="true">{paths[type] ?? <circle cx="12" cy="12" r="8" {...common} />}</svg>;
}

function readLayout(key, fallbackViewport = genericViewport) {
    const fallback = { ...baseLayout, viewport: fallbackViewport };

    if (!key || typeof window === 'undefined') {
        return fallback;
    }

    try {
        return { ...fallback, ...(JSON.parse(window.localStorage.getItem(key)) ?? {}) };
    } catch {
        return fallback;
    }
}

function writeLayout(key, layout) {
    if (!key || typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(key, JSON.stringify({ ...layout, saved_at: new Date().toISOString() }));
}

function removeLayout(key) {
    if (key && typeof window !== 'undefined') {
        window.localStorage.removeItem(key);
    }
}

function normalizeStatus(status = '') {
    return String(status).replaceAll('_', ' ');
}

function statusColor(status) {
    if (['failed', 'blocked'].includes(status)) {
        return 'bg-red-400';
    }

    if (['needs_review', 'Not Connected', 'Planned'].includes(status)) {
        return 'bg-amber-400';
    }

    if (['Implemented', 'completed', 'active'].includes(status)) {
        return 'bg-lime-400';
    }

    return 'bg-slate-400';
}

function nodeSize(size) {
    return {
        compact: { width: 170, height: 72 },
        standard: { width: 260, height: 118 },
        expanded: { width: 340, height: 240 },
    }[size] ?? { width: 260, height: 118 };
}

function nodeFrame(node, layout) {
    const override = layout.nodes?.[node.id] ?? {};
    const size = override.size ?? node.display_size ?? 'standard';
    const dimensions = nodeSize(size);

    return {
        position: override.position ?? node.position ?? { x: 0, y: 0 },
        width: override.width ?? node.width ?? dimensions.width,
        height: override.height ?? node.height ?? dimensions.height,
        size,
        title: override.title ?? node.title,
        subtitle: override.subtitle ?? node.subtitle,
        status: override.status ?? node.status,
        tone: override.tone ?? node.tone,
        icon: override.icon ?? node.category,
    };
}

function centerFor(node, layout) {
    const frame = nodeFrame(node, layout);
    return {
        x: frame.position.x + frame.width / 2,
        y: frame.position.y + frame.height / 2,
        frame,
    };
}

function edgePath(sourceNode, targetNode, layout, orientation = 'left-to-right') {
    const source = centerFor(sourceNode, layout);
    const target = centerFor(targetNode, layout);

    if (orientation === 'top-to-bottom') {
        const fromY = source.frame.position.y + source.frame.height;
        const toY = target.frame.position.y;
        const fromX = source.x;
        const toX = target.x;
        const midY = fromY + Math.max(50, (toY - fromY) / 2);

        return `M ${fromX} ${fromY} C ${fromX} ${midY}, ${toX} ${midY}, ${toX} ${toY}`;
    }

    const fromX = source.frame.position.x + source.frame.width;
    const toX = target.frame.position.x;
    const midX = fromX + Math.max(60, (toX - fromX) / 2);

    return `M ${fromX} ${source.y} C ${midX} ${source.y}, ${midX} ${target.y}, ${toX} ${target.y}`;
}

function graphBounds(nodes, layout) {
    if (nodes.length === 0) {
        return null;
    }

    const frames = nodes.map((node) => ({ node, frame: nodeFrame(node, layout) }));
    const xs = frames.map(({ frame }) => frame.position.x);
    const ys = frames.map(({ frame }) => frame.position.y);
    const rights = frames.map(({ frame }) => frame.position.x + frame.width);
    const bottoms = frames.map(({ frame }) => frame.position.y + frame.height);
    const x = Math.min(...xs);
    const y = Math.min(...ys);

    return {
        x,
        y,
        width: Math.max(...rights) - x,
        height: Math.max(...bottoms) - y,
    };
}

function withQuery(url, expanded) {
    if (!expanded?.size) {
        return url;
    }

    const params = new URLSearchParams();
    params.set('expanded', [...expanded].join(','));

    return `${url}?${params.toString()}`;
}

export default function OperationsGraph({
    initialGraph,
    graphEndpoint,
    detailsEndpoint,
    tabs = [],
    activeView,
    onViewChange,
    actionPanel,
    selectedOutputPanel,
    onSelectionChange,
    className = '',
}) {
    const [graph, setGraph] = useState(initialGraph);
    const storageKey = `${graph?.localStorageKey ?? 'miriam.operations.graph'}.layout`;
    const [savedLayout, setSavedLayout] = useState(() => readLayout(storageKey, defaultViewportFor(initialGraph)));
    const [draftLayout, setDraftLayout] = useState(() => readLayout(storageKey, defaultViewportFor(initialGraph)));
    const [dirty, setDirty] = useState(false);
    const [history, setHistory] = useState([]);
    const [mode, setMode] = useState('view');
    const [expandedBranches, setExpandedBranches] = useState(() => new Set(initialGraph?.layout?.expanded ?? []));
    const [focusedBranch, setFocusedBranch] = useState(null);
    const [loadingGraph, setLoadingGraph] = useState(false);
    const [search, setSearch] = useState('');
    const [filters, setFilters] = useState(() => Object.fromEntries((initialGraph?.filters ?? []).map((filter) => [filter.key, filter.default !== false])));
    const [selectedId, setSelectedId] = useState(initialGraph?.selectedId ?? initialGraph?.rootId);
    const [selectedEdgeId, setSelectedEdgeId] = useState(null);
    const [details, setDetails] = useState(null);
    const [loadingDetails, setLoadingDetails] = useState(false);
    const [detailsExpanded, setDetailsExpanded] = useState(false);
    const [mobileDetailsOpen, setMobileDetailsOpen] = useState(false);
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [transform, setTransform] = useState(defaultViewportFor(initialGraph));
    const [drag, setDrag] = useState(null);
    const [resize, setResize] = useState(null);
    const [pan, setPan] = useState(null);
    const [connecting, setConnecting] = useState(null);
    const [connectionModal, setConnectionModal] = useState(null);
    const [miniMapOpen, setMiniMapOpen] = useState(true);
    const [activeDetailsTab, setActiveDetailsTab] = useState('Overview');
    const shellRef = useRef(null);
    const canvasRef = useRef(null);

    const orientation = graph?.layout?.orientation ?? 'left-to-right';
    const canvasSize = graph?.canvas ?? { width: 2400, height: 1200 };
    const canEditGraph = graph?.view !== 'technical-map' || graph?.permissions?.technical_map_editing === true;
    const editMode = mode === 'edit' && canEditGraph;

    useEffect(() => {
        const nextSaved = readLayout(storageKey, defaultViewportFor(graph));
        setSavedLayout(nextSaved);
        setDraftLayout(nextSaved);
        setHistory([]);
        setDirty(false);
        setTransform(nextSaved.viewport ?? defaultViewportFor(graph));
    }, [storageKey]);

    useEffect(() => {
        setGraph(initialGraph);
        setSelectedId(initialGraph?.selectedId ?? initialGraph?.rootId);
        setSelectedEdgeId(null);
        setExpandedBranches(new Set(initialGraph?.layout?.expanded ?? []));
        setFilters(Object.fromEntries((initialGraph?.filters ?? []).map((filter) => [filter.key, filter.default !== false])));
        setFocusedBranch(null);
    }, [initialGraph]);

    const loadGraph = (view, expanded = expandedBranches) => {
        if (!graphEndpoint) {
            return;
        }

        setLoadingGraph(true);
        fetch(withQuery(endpoint(graphEndpoint, { view }), expanded), { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Graph load failed (${response.status})`);
                }

                return response.json();
            })
            .then((nextGraph) => {
                setGraph(nextGraph);
                setSelectedId(nextGraph.selectedId ?? nextGraph.rootId);
                setSelectedEdgeId(null);
                setFilters(Object.fromEntries((nextGraph.filters ?? []).map((filter) => [filter.key, filter.default !== false])));
            })
            .catch(() => {
                setGraph({
                    ...graph,
                    title: 'Graph unavailable',
                    subtitle: 'This view could not be loaded with your current permissions.',
                    nodes: [],
                    edges: [],
                });
            })
            .finally(() => setLoadingGraph(false));
    };

    useEffect(() => {
        if (!activeView || activeView === graph?.view) {
            return;
        }

        const empty = new Set();
        setExpandedBranches(empty);
        loadGraph(activeView, empty);
    }, [activeView]);

    const hidden = useMemo(() => new Set(draftLayout.hidden ?? []), [draftLayout.hidden]);
    const customEdges = draftLayout.customEdges ?? [];
    const removedEdges = useMemo(() => new Set(draftLayout.removedEdges ?? []), [draftLayout.removedEdges]);

    const allEdges = useMemo(
        () => [...(graph?.edges ?? []), ...customEdges].filter((edge) => !removedEdges.has(edge.id)),
        [customEdges, graph?.edges, removedEdges],
    );

    const allNodesById = useMemo(() => Object.fromEntries((graph?.nodes ?? []).map((node) => [node.id, node])), [graph?.nodes]);

    const focusIds = useMemo(() => {
        if (!focusedBranch) {
            return null;
        }

        const ids = new Set([focusedBranch, graph?.rootId]);
        let changed = true;

        while (changed) {
            changed = false;
            (graph?.nodes ?? []).forEach((node) => {
                if (ids.has(node.parent_id) || ids.has(node.id) || node.id === graph?.rootId || node.trunk) {
                    if (node.id === focusedBranch || node.parent_id === focusedBranch || node.trunk) {
                        if (!ids.has(node.id)) {
                            ids.add(node.id);
                            changed = true;
                        }
                    }
                }
            });
        }

        return ids;
    }, [focusedBranch, graph?.nodes, graph?.rootId]);

    const visibleNodes = useMemo(() => {
        const query = search.trim().toLowerCase();

        return (graph?.nodes ?? []).filter((node) => {
            if (hidden.has(node.id)) {
                return false;
            }

            if (focusIds && !focusIds.has(node.id)) {
                return false;
            }

            const filter = (graph?.filters ?? []).find((candidate) => candidate.values?.includes(node.status));
            if (filter && filters[filter.key] === false) {
                return false;
            }

            if (!query) {
                return true;
            }

            return [node.title, node.subtitle, node.status, node.category]
                .filter(Boolean)
                .some((value) => String(value).toLowerCase().includes(query));
        });
    }, [filters, focusIds, graph?.filters, graph?.nodes, hidden, search]);

    const visibleNodeIds = useMemo(() => new Set(visibleNodes.map((node) => node.id)), [visibleNodes]);
    const visibleEdges = useMemo(() => allEdges.filter((edge) => visibleNodeIds.has(edge.source) && visibleNodeIds.has(edge.target)), [allEdges, visibleNodeIds]);
    const selectedNode = useMemo(() => (graph?.nodes ?? []).find((node) => node.id === selectedId) ?? visibleNodes[0] ?? graph?.nodes?.[0], [graph?.nodes, selectedId, visibleNodes]);
    const selectedEdge = useMemo(() => visibleEdges.find((edge) => edge.id === selectedEdgeId), [selectedEdgeId, visibleEdges]);

    useEffect(() => {
        if (!selectedNode) {
            return;
        }

        onSelectionChange?.(selectedNode, graph);
        setMobileDetailsOpen(false);

        const template = detailsEndpoint || graph?.endpoints?.details;
        if (!template) {
            setDetails(null);
            return;
        }

        setLoadingDetails(true);
        fetch(endpoint(template, { view: graph.view, node: selectedNode.id }), { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => setDetails(payload))
            .finally(() => setLoadingDetails(false));
    }, [detailsEndpoint, graph, onSelectionChange, selectedNode]);

    useEffect(() => {
        const onFullscreenChange = () => setIsFullscreen(Boolean(document.fullscreenElement));
        const onKeyDown = (event) => {
            if (event.key === 'Escape' && document.fullscreenElement) {
                document.exitFullscreen();
            }
        };

        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('fullscreenchange', onFullscreenChange);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, []);

    useEffect(() => {
        const beforeUnload = (event) => {
            if (!dirty) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', beforeUnload);

        return () => window.removeEventListener('beforeunload', beforeUnload);
    }, [dirty]);

    const pushHistory = (label, before = draftLayout) => {
        setHistory((current) => [...current.slice(-15), { label, layout: before, at: new Date().toISOString() }]);
    };

    const markChanged = (updater, label) => {
        setDraftLayout((current) => {
            pushHistory(label, current);
            const next = updater(current);
            return { ...next, history: [...(next.history ?? []), { label, at: new Date().toISOString(), actor: 'You' }].slice(-30) };
        });
        setDirty(true);
    };

    const updateNodeDraft = (nodeId, updates, label = 'Updated node presentation') => {
        markChanged((current) => ({
            ...current,
            nodes: {
                ...(current.nodes ?? {}),
                [nodeId]: {
                    ...(current.nodes?.[nodeId] ?? {}),
                    ...updates,
                },
            },
        }), label);
    };

    const onNodeSelect = (node) => {
        if (editMode && connecting?.source && connecting.source !== node.id) {
            setConnectionModal({
                source: connecting.source,
                target: node.id,
                type: 'related',
                label: '',
                direction: 'source-target',
            });
            setConnecting(null);
            return;
        }

        setSelectedId(node.id);
        setSelectedEdgeId(null);
        setMobileDetailsOpen(true);
    };

    const onNodePointerDown = (event, node) => {
        if (!editMode) {
            return;
        }

        event.stopPropagation();
        const frame = nodeFrame(node, draftLayout);
        setDrag({
            id: node.id,
            before: draftLayout,
            startX: event.clientX,
            startY: event.clientY,
            originalX: frame.position.x,
            originalY: frame.position.y,
            moved: false,
        });
    };

    const onResizePointerDown = (event, node) => {
        if (!editMode) {
            return;
        }

        event.stopPropagation();
        const frame = nodeFrame(node, draftLayout);
        setResize({
            id: node.id,
            before: draftLayout,
            startX: event.clientX,
            startY: event.clientY,
            originalWidth: frame.width,
            originalHeight: frame.height,
        });
    };

    const onHandlePointerDown = (event, node) => {
        if (!editMode) {
            return;
        }

        event.stopPropagation();
        setConnecting({ source: node.id, startX: event.clientX, startY: event.clientY });
    };

    const onCanvasPointerDown = (event) => {
        if (event.button !== 0 || resize || drag) {
            return;
        }

        setPan({
            startX: event.clientX,
            startY: event.clientY,
            originalX: transform.x,
            originalY: transform.y,
        });
    };

    const onPointerMove = (event) => {
        if (drag) {
            const dx = (event.clientX - drag.startX) / transform.scale;
            const dy = (event.clientY - drag.startY) / transform.scale;

            setDraftLayout((current) => ({
                ...current,
                nodes: {
                    ...(current.nodes ?? {}),
                    [drag.id]: {
                        ...(current.nodes?.[drag.id] ?? {}),
                        position: {
                            x: Math.round(drag.originalX + dx),
                            y: Math.round(drag.originalY + dy),
                        },
                    },
                },
            }));
            setDrag((current) => current ? { ...current, moved: true } : current);
            setDirty(true);
        }

        if (resize) {
            const dx = (event.clientX - resize.startX) / transform.scale;
            const dy = (event.clientY - resize.startY) / transform.scale;

            setDraftLayout((current) => ({
                ...current,
                nodes: {
                    ...(current.nodes ?? {}),
                    [resize.id]: {
                        ...(current.nodes?.[resize.id] ?? {}),
                        width: Math.max(150, Math.round(resize.originalWidth + dx)),
                        height: Math.max(70, Math.round(resize.originalHeight + dy)),
                    },
                },
            }));
            setDirty(true);
        }

        if (pan) {
            setTransform((current) => ({
                ...current,
                x: pan.originalX + event.clientX - pan.startX,
                y: pan.originalY + event.clientY - pan.startY,
            }));
        }
    };

    const onPointerUp = (event) => {
        if (drag?.moved) {
            pushHistory('Moved node', drag.before);
        }

        if (resize) {
            pushHistory('Resized node', resize.before);
        }

        if (connecting?.source) {
            const element = document.elementFromPoint(event.clientX, event.clientY)?.closest?.('[data-node-id]');
            const target = element?.getAttribute('data-node-id');
            if (target && target !== connecting.source) {
                setConnectionModal({
                    source: connecting.source,
                    target,
                    type: 'related',
                    label: '',
                    direction: 'source-target',
                });
            }
        }

        setDrag(null);
        setResize(null);
        setPan(null);
        setConnecting(null);
    };

    const onWheel = (event) => {
        event.preventDefault();
        const nextScale = Math.max(0.25, Math.min(1.8, transform.scale - event.deltaY * 0.001));
        setTransform((current) => ({ ...current, scale: Number(nextScale.toFixed(2)) }));
    };

    const fit = () => {
        const bounds = graphBounds(visibleNodes, draftLayout);
        const canvas = canvasRef.current?.getBoundingClientRect();

        if (!canvas || !bounds) {
            setTransform(defaultViewportFor(graph));
            return;
        }

        const scale = Math.max(0.28, Math.min(1.15, Math.min((canvas.width - 120) / bounds.width, (canvas.height - 120) / bounds.height)));
        setTransform({
            x: Math.round(60 - bounds.x * scale),
            y: Math.round(60 - bounds.y * scale),
            scale: Number(scale.toFixed(2)),
        });
    };

    const resetViewport = () => setTransform(savedLayout.viewport ?? defaultViewportFor(graph));

    const saveLayout = () => {
        const next = { ...draftLayout, viewport: transform, history: draftLayout.history ?? [] };
        writeLayout(storageKey, next);
        setSavedLayout(next);
        setDraftLayout(next);
        setHistory([]);
        setDirty(false);
    };

    const undoLastChange = () => {
        const last = history[history.length - 1];
        if (!last) {
            return;
        }

        setDraftLayout(last.layout);
        setHistory((current) => current.slice(0, -1));
        setDirty(history.length > 1);
    };

    const discardChanges = () => {
        setDraftLayout(savedLayout);
        setHistory([]);
        setDirty(false);
    };

    const restoreSavedLayout = () => {
        const next = readLayout(storageKey, defaultViewportFor(graph));
        setSavedLayout(next);
        setDraftLayout(next);
        setTransform(next.viewport ?? defaultViewportFor(graph));
        setHistory([]);
        setDirty(false);
    };

    const restoreDefaultLayout = () => {
        removeLayout(storageKey);
        const next = { ...baseLayout, viewport: defaultViewportFor(graph) };
        setSavedLayout(next);
        setDraftLayout(next);
        setTransform(next.viewport);
        setHistory([]);
        setDirty(false);
    };

    const toggleFullscreen = () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();
            return;
        }

        shellRef.current?.requestFullscreen?.();
    };

    const expandNode = (nodeId) => {
        const next = new Set(expandedBranches);
        next.add(nodeId);
        setExpandedBranches(next);
        loadGraph(graph.view, next);
    };

    const collapseNode = (nodeId) => {
        const next = new Set(expandedBranches);
        next.delete(nodeId);
        next.delete('*');
        setExpandedBranches(next);
        loadGraph(graph.view, next);
    };

    const expandAllChildren = (nodeId) => {
        const next = new Set(expandedBranches);
        next.add(nodeId);
        setExpandedBranches(next);
        loadGraph(graph.view, next);
    };

    const collapseAllChildren = () => {
        const next = new Set();
        setExpandedBranches(next);
        setFocusedBranch(null);
        loadGraph(graph.view, next);
    };

    const focusBranch = (nodeId) => setFocusedBranch(nodeId);
    const fullJourney = () => setFocusedBranch(null);

    const hideNode = (node) => {
        markChanged((current) => ({
            ...current,
            hidden: [...new Set([...(current.hidden ?? []), node.id])],
        }), 'Hid node from personal map');
    };

    const removeCustomNode = (node) => {
        if (!node.custom) {
            hideNode(node);
            return;
        }

        if (!window.confirm('Remove this custom node from the Operations Center map?')) {
            return;
        }

        markChanged((current) => ({
            ...current,
            hidden: [...new Set([...(current.hidden ?? []), node.id])],
        }), 'Removed custom map node');
    };

    const restoreHiddenNodes = () => {
        markChanged((current) => ({ ...current, hidden: [] }), 'Restored hidden nodes');
    };

    const saveConnection = (connection) => {
        const source = connection.direction === 'reverse' ? connection.target : connection.source;
        const target = connection.direction === 'reverse' ? connection.source : connection.target;
        const edge = {
            id: `custom-${Date.now()}`,
            source,
            target,
            label: connection.label || connection.type,
            type: connection.type,
            tone: connection.type === 'yes' ? 'green' : connection.type === 'no' ? 'red' : 'cyan',
            custom: true,
        };

        markChanged((current) => ({
            ...current,
            customEdges: [...(current.customEdges ?? []), edge],
        }), 'Connected map nodes');
        setConnectionModal(null);
    };

    const disconnectEdge = (edge) => {
        if (!edge) {
            return;
        }

        if (!window.confirm('Disconnect this visual relationship? Underlying Miriam records are unchanged.')) {
            return;
        }

        markChanged((current) => ({
            ...current,
            customEdges: (current.customEdges ?? []).filter((candidate) => candidate.id !== edge.id),
            removedEdges: edge.custom ? (current.removedEdges ?? []) : [...new Set([...(current.removedEdges ?? []), edge.id])],
        }), 'Disconnected visual relationship');
        setSelectedEdgeId(null);
    };

    const changeNodeSize = (node, size) => {
        const dimensions = nodeSize(size);
        updateNodeDraft(node.id, { size, width: dimensions.width, height: dimensions.height }, `Changed node size to ${size}`);
    };

    const applyLayout = (kind) => {
        const before = draftLayout;
        const primary = visibleNodes.filter((node) => node.trunk || graph?.layout?.primary_trunk?.includes(node.id));
        const nextNodes = { ...(draftLayout.nodes ?? {}) };

        if (kind === 'top-to-bottom') {
            primary.forEach((node, index) => {
                nextNodes[node.id] = {
                    ...(nextNodes[node.id] ?? {}),
                    position: { x: 700, y: 80 + index * 220 },
                };
            });
        }

        if (kind === 'left-to-right') {
            visibleNodes.forEach((node, index) => {
                nextNodes[node.id] = {
                    ...(nextNodes[node.id] ?? {}),
                    position: { x: 80 + index * 250, y: node.position?.y ?? 140 },
                };
            });
        }

        if (kind === 'space-evenly') {
            visibleNodes.forEach((node, index) => {
                const frame = nodeFrame(node, draftLayout);
                nextNodes[node.id] = {
                    ...(nextNodes[node.id] ?? {}),
                    position: { x: frame.position.x, y: 80 + index * 150 },
                };
            });
        }

        if (kind === 'align-selected' && selectedNode) {
            const selectedFrame = nodeFrame(selectedNode, draftLayout);
            visibleNodes.filter((node) => node.parent_id === selectedNode.parent_id).forEach((node) => {
                const frame = nodeFrame(node, draftLayout);
                nextNodes[node.id] = {
                    ...(nextNodes[node.id] ?? {}),
                    position: { x: selectedFrame.position.x, y: frame.position.y },
                };
            });
        }

        setDraftLayout((current) => ({ ...current, nodes: nextNodes }));
        pushHistory(`Applied ${kind} layout`, before);
        setDirty(true);
    };

    const selectedOutputBlock = selectedOutputPanel;
    const actionBlock = actionPanel;

    const detailsPanel = (
        <DetailsPanel
            graph={graph}
            node={selectedNode}
            edge={selectedEdge}
            nodesById={allNodesById}
            edges={visibleEdges}
            details={details}
            loading={loadingDetails}
            expanded={detailsExpanded}
            setExpanded={setDetailsExpanded}
            mode={mode}
            canEdit={editMode}
            draftLayout={draftLayout}
            activeTab={activeDetailsTab}
            setActiveTab={setActiveDetailsTab}
            onSizeChange={changeNodeSize}
            onUpdateNode={updateNodeDraft}
            onOpenConnect={(node) => setConnecting({ source: node.id })}
            onDisconnect={disconnectEdge}
            onHide={hideNode}
            onRemove={removeCustomNode}
            actionPanel={actionBlock}
            selectedOutputPanel={selectedOutputBlock}
        />
    );

    return (
        <section
            ref={shellRef}
            data-testid="operations-graph-renderer"
            className={cx('overflow-hidden rounded-xl border border-slate-800 bg-[#030712] text-slate-100 shadow-2xl shadow-slate-950/30', isFullscreen && 'h-screen rounded-none border-0', className)}
        >
            <div
                className={cx(
                    'grid min-h-[780px] grid-cols-1',
                    isFullscreen && (detailsExpanded ? 'h-screen xl:grid-cols-[minmax(0,1fr)_520px]' : 'h-screen xl:grid-cols-[minmax(0,1fr)_380px]'),
                    !isFullscreen && (detailsExpanded ? 'xl:grid-cols-[minmax(0,1fr)_520px]' : 'xl:grid-cols-[minmax(0,1fr)_360px]'),
                )}
            >
                <div className="flex min-w-0 flex-col">
                    <GraphHeader
                        graph={graph}
                        tabs={tabs}
                        activeView={activeView ?? graph?.view}
                        onViewChange={(view) => {
                            if (dirty && !window.confirm('Leave this graph view and discard unsaved canvas changes?')) {
                                return;
                            }
                            onViewChange?.(view);
                        }}
                        search={search}
                        setSearch={setSearch}
                        filters={filters}
                        setFilters={setFilters}
                        mode={mode}
                        setMode={setMode}
                        canEdit={canEditGraph}
                        onFit={fit}
                        onReset={resetViewport}
                        onFullscreen={toggleFullscreen}
                        isFullscreen={isFullscreen}
                        onLayout={applyLayout}
                        onRestoreSaved={restoreSavedLayout}
                        onRestoreDefault={restoreDefaultLayout}
                        onRestoreHidden={restoreHiddenNodes}
                        hiddenCount={draftLayout.hidden?.length ?? 0}
                    />

                    {dirty && (
                        <div data-testid="operations-unsaved-bar" className="sticky top-0 z-40 flex flex-wrap items-center gap-2 border-b border-amber-300/30 bg-amber-500/15 px-4 py-2 text-sm text-amber-100 backdrop-blur">
                            <span className="font-semibold">Unsaved canvas changes</span>
                            <button type="button" onClick={saveLayout} className="rounded-md border border-lime-300/40 bg-lime-400/15 px-3 py-1 text-xs font-semibold text-lime-100">Save changes</button>
                            <button type="button" onClick={undoLastChange} className="rounded-md border border-slate-600 px-3 py-1 text-xs font-semibold text-slate-200">Undo last change</button>
                            <button type="button" onClick={discardChanges} className="rounded-md border border-red-300/40 bg-red-400/10 px-3 py-1 text-xs font-semibold text-red-100">Discard changes</button>
                        </div>
                    )}

                    {editMode && (
                        <div className="border-b border-violet-400/20 bg-violet-500/10 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-violet-100">
                            Editing layout - changes are personal until saved. Business records are not modified.
                        </div>
                    )}

                    {focusedBranch && (
                        <div className="flex items-center justify-between gap-3 border-b border-blue-400/20 bg-blue-500/10 px-4 py-2 text-xs text-blue-100">
                            <span>Focused branch: {allNodesById[focusedBranch]?.title ?? focusedBranch}</span>
                            <button type="button" onClick={fullJourney} className="rounded border border-blue-300/30 px-2 py-1 font-semibold">Return to full journey</button>
                        </div>
                    )}

                    <div
                        ref={canvasRef}
                        className={cx('relative flex-1 overflow-hidden bg-[#030712]', editMode ? 'cursor-crosshair' : 'cursor-grab active:cursor-grabbing')}
                        onPointerDown={onCanvasPointerDown}
                        onPointerMove={onPointerMove}
                        onPointerUp={onPointerUp}
                        onPointerLeave={onPointerUp}
                        onWheel={onWheel}
                    >
                        <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_8%,rgba(124,58,237,0.20),transparent_30%),radial-gradient(circle_at_80%_60%,rgba(14,165,233,0.10),transparent_34%)]" />
                        <div className="absolute inset-0 opacity-45 [background-image:radial-gradient(circle_at_1px_1px,rgba(148,163,184,0.24)_1px,transparent_0)] [background-size:28px_28px]" />
                        {loadingGraph && <div className="absolute inset-x-0 top-0 z-30 h-1 overflow-hidden bg-slate-900"><div className="h-full w-1/2 animate-pulse bg-violet-400" /></div>}

                        <div
                            className="absolute left-0 top-0 origin-top-left"
                            style={{
                                width: canvasSize.width,
                                height: canvasSize.height,
                                transform: `translate(${transform.x}px, ${transform.y}px) scale(${transform.scale})`,
                            }}
                        >
                            <Edges
                                nodes={visibleNodes}
                                edges={visibleEdges}
                                layout={draftLayout}
                                orientation={orientation}
                                editMode={editMode}
                                selectedEdgeId={selectedEdgeId}
                                onSelectEdge={(edge) => {
                                    setSelectedEdgeId(edge.id);
                                    setSelectedId(edge.source);
                                    setActiveDetailsTab('Connections');
                                }}
                            />
                            {visibleNodes.map((node) => (
                                <GraphNode
                                    key={node.id}
                                    node={node}
                                    frame={nodeFrame(node, draftLayout)}
                                    selected={selectedNode?.id === node.id}
                                    expanded={expandedBranches.has(node.id) || expandedBranches.has('*')}
                                    mode={mode}
                                    editMode={editMode}
                                    isConnecting={connecting?.source === node.id}
                                    onSelect={() => onNodeSelect(node)}
                                    onPointerDown={(event) => onNodePointerDown(event, node)}
                                    onResizePointerDown={(event) => onResizePointerDown(event, node)}
                                    onHandlePointerDown={(event) => onHandlePointerDown(event, node)}
                                    onExpand={() => expandNode(node.id)}
                                    onCollapse={() => collapseNode(node.id)}
                                    onExpandAll={() => expandAllChildren(node.id)}
                                    onCollapseAll={collapseAllChildren}
                                    onFocus={() => focusBranch(node.id)}
                                    onOpenEdit={() => {
                                        setSelectedId(node.id);
                                        setActiveDetailsTab('Edit');
                                    }}
                                    onOpenConnect={() => setConnecting({ source: node.id })}
                                    onHide={() => hideNode(node)}
                                    onRemove={() => removeCustomNode(node)}
                                    onSizeChange={(size) => changeNodeSize(node, size)}
                                />
                            ))}
                        </div>

                        <Legend graph={graph} />
                        <MiniMap
                            nodes={visibleNodes}
                            edges={visibleEdges}
                            selectedId={selectedNode?.id}
                            layout={draftLayout}
                            setTransform={setTransform}
                            open={miniMapOpen}
                            setOpen={setMiniMapOpen}
                            defaultViewport={defaultViewportFor(graph)}
                        />
                        <button type="button" onClick={() => setMobileDetailsOpen(true)} className="absolute bottom-4 right-4 z-30 rounded-lg border border-violet-400/40 bg-violet-500/20 px-3 py-2 text-sm font-semibold text-violet-100 shadow-lg shadow-slate-950/40 xl:hidden">
                            Node details
                        </button>
                    </div>
                </div>

                <div className="hidden min-h-0 border-l border-slate-800 bg-[#07101d] xl:block">
                    {detailsPanel}
                </div>
            </div>

            {connectionModal && (
                <ConnectionModal
                    connection={connectionModal}
                    nodesById={allNodesById}
                    onChange={setConnectionModal}
                    onSave={saveConnection}
                    onCancel={() => setConnectionModal(null)}
                />
            )}

            {mobileDetailsOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/70 p-3 xl:hidden">
                    <div className="ml-auto h-full max-w-md overflow-hidden rounded-xl border border-slate-800 bg-[#07101d] shadow-2xl">
                        <button type="button" onClick={() => setMobileDetailsOpen(false)} className="w-full border-b border-slate-800 px-4 py-3 text-left text-sm font-semibold text-slate-200">Close details</button>
                        {detailsPanel}
                    </div>
                </div>
            )}
        </section>
    );
}

function GraphHeader({
    graph,
    tabs,
    activeView,
    onViewChange,
    search,
    setSearch,
    filters,
    setFilters,
    mode,
    setMode,
    canEdit,
    onFit,
    onReset,
    onFullscreen,
    isFullscreen,
    onLayout,
    onRestoreSaved,
    onRestoreDefault,
    onRestoreHidden,
    hiddenCount,
}) {
    return (
        <div className="border-b border-slate-800 bg-[#07101d]/95 p-3 backdrop-blur">
            <div className="flex flex-col gap-3 2xl:flex-row 2xl:items-center">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <h2 className="truncate text-lg font-semibold text-white">{graph?.title}</h2>
                        <span className="rounded border border-violet-400/40 bg-violet-500/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-100">Graph</span>
                        {graph?.layout?.orientation && (
                            <span className="rounded border border-slate-700 bg-slate-900 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-300">{graph.layout.orientation}</span>
                        )}
                    </div>
                    <p className="mt-1 truncate text-xs text-slate-400">{graph?.subtitle}</p>
                </div>

                {tabs.length > 0 && (
                    <div className="flex flex-wrap gap-1 rounded-lg border border-slate-800 bg-slate-950/80 p-1">
                        {tabs.map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => onViewChange?.(tab.key)}
                                className={cx(
                                    'rounded-md px-3 py-1.5 text-xs font-semibold transition',
                                    activeView === tab.key ? 'bg-gradient-to-r from-violet-500 to-indigo-500 text-white shadow-lg shadow-violet-950/30' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100',
                                )}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                )}

                <div className="ml-auto flex flex-wrap items-center gap-2">
                    <div data-testid="operations-mode-switch" className="flex rounded-lg border border-slate-700 bg-slate-950/80 p-1">
                        {['view', 'edit'].map((option) => (
                            <button
                                key={option}
                                type="button"
                                disabled={option === 'edit' && !canEdit}
                                onClick={() => setMode(option)}
                                className={cx(
                                    'rounded-md px-3 py-1.5 text-xs font-semibold capitalize',
                                    mode === option ? 'bg-violet-500 text-white' : 'text-slate-400 hover:bg-slate-800',
                                    option === 'edit' && !canEdit && 'cursor-not-allowed opacity-40',
                                )}
                            >
                                {option}
                            </button>
                        ))}
                    </div>
                    <label className="flex h-9 min-w-[220px] items-center gap-2 rounded-lg border border-slate-700 bg-slate-950/80 px-3 text-slate-400">
                        <Icon type="search" className="h-4 w-4" />
                        <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search nodes..." className="min-w-0 flex-1 border-0 bg-transparent p-0 text-xs text-slate-100 placeholder:text-slate-500 focus:ring-0" />
                    </label>
                    <FilterMenu graph={graph} filters={filters} setFilters={setFilters} />
                    <LayoutMenu
                        onLayout={onLayout}
                        onRestoreSaved={onRestoreSaved}
                        onRestoreDefault={onRestoreDefault}
                        onRestoreHidden={onRestoreHidden}
                        hiddenCount={hiddenCount}
                    />
                    <ToolbarButton label="Fit visible nodes" onClick={onFit}>Fit</ToolbarButton>
                    <ToolbarButton label="Reset viewport" onClick={onReset}><Icon type="reset" className="h-4 w-4" /></ToolbarButton>
                    <ToolbarButton label={isFullscreen ? 'Exit fullscreen' : 'Fullscreen'} onClick={onFullscreen}>
                        <Icon type={isFullscreen ? 'exit' : 'fullscreen'} className="h-4 w-4" />
                    </ToolbarButton>
                </div>
            </div>

            {graph?.summary?.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                    {graph.summary.map((item) => (
                        <span key={item.label} className={cx('rounded-lg border px-3 py-1.5 text-xs font-semibold', toneClasses[item.tone]?.chip ?? toneClasses.slate.chip)}>
                            {item.label}: {item.value}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

function FilterMenu({ graph, filters, setFilters }) {
    return (
        <div className="group relative">
            <button type="button" className="flex h-9 items-center gap-2 rounded-lg border border-slate-700 bg-slate-950/80 px-3 text-xs font-semibold text-slate-300 hover:bg-slate-900">
                <Icon type="filter" className="h-4 w-4" />
                Filters
            </button>
            <div className="invisible absolute right-0 top-10 z-40 w-56 rounded-lg border border-slate-700 bg-slate-950 p-2 opacity-0 shadow-xl shadow-slate-950/60 transition group-hover:visible group-hover:opacity-100">
                {(graph?.filters ?? []).map((filter) => (
                    <label key={filter.key} className="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs text-slate-300 hover:bg-slate-800">
                        <input
                            type="checkbox"
                            checked={filters[filter.key] !== false}
                            onChange={(event) => setFilters((current) => ({ ...current, [filter.key]: event.target.checked }))}
                            className="rounded border-slate-600 bg-slate-900 text-violet-500 focus:ring-violet-500/40"
                        />
                        {filter.label}
                    </label>
                ))}
            </div>
        </div>
    );
}

function LayoutMenu({ onLayout, onFit, onRestoreSaved, onRestoreDefault, onRestoreHidden, hiddenCount }) {
    const items = [
        ['Top to bottom', () => onLayout('top-to-bottom')],
        ['Left to right', () => onLayout('left-to-right')],
        ['Re-layout current branch', () => onLayout('align-selected')],
        ['Space nodes evenly', () => onLayout('space-evenly')],
        ['Align selected nodes', () => onLayout('align-selected')],
        ['Centre primary flow', () => onLayout('top-to-bottom')],
        ['Fit visible nodes', onFit],
    ];

    return (
        <div className="group relative">
            <button type="button" className="flex h-9 items-center gap-2 rounded-lg border border-slate-700 bg-slate-950/80 px-3 text-xs font-semibold text-slate-300 hover:bg-slate-900">
                Layout
            </button>
            <div className="invisible absolute right-0 top-10 z-40 w-64 rounded-lg border border-slate-700 bg-slate-950 p-2 opacity-0 shadow-xl shadow-slate-950/60 transition group-hover:visible group-hover:opacity-100">
                {items.map(([label, handler]) => (
                    <button key={label} type="button" onClick={handler ?? undefined} className="block w-full rounded-md px-2 py-1.5 text-left text-xs text-slate-300 hover:bg-slate-800">
                        {label}
                    </button>
                ))}
                <div className="my-2 border-t border-slate-800" />
                <button type="button" onClick={onRestoreSaved} className="block w-full rounded-md px-2 py-1.5 text-left text-xs text-slate-300 hover:bg-slate-800">Restore my saved layout</button>
                <button type="button" onClick={onRestoreDefault} className="block w-full rounded-md px-2 py-1.5 text-left text-xs text-slate-300 hover:bg-slate-800">Restore default layout</button>
                {hiddenCount > 0 && <button type="button" onClick={onRestoreHidden} className="block w-full rounded-md px-2 py-1.5 text-left text-xs text-slate-300 hover:bg-slate-800">Restore hidden nodes ({hiddenCount})</button>}
            </div>
        </div>
    );
}

function ToolbarButton({ label, onClick, children }) {
    return (
        <button type="button" title={label} onClick={onClick} className="flex h-9 min-w-9 items-center justify-center rounded-lg border border-slate-700 bg-slate-950/80 px-2 text-xs font-semibold text-slate-300 hover:bg-slate-900 hover:text-white">
            {children}
        </button>
    );
}

function Edges({ nodes, edges, layout, orientation, editMode, selectedEdgeId, onSelectEdge }) {
    const byId = Object.fromEntries(nodes.map((node) => [node.id, node]));

    return (
        <svg className="absolute left-0 top-0 h-full w-full overflow-visible" aria-hidden="true">
            <defs>
                <marker id="operations-arrow" markerWidth="10" markerHeight="10" refX="7" refY="3" orient="auto" markerUnits="strokeWidth">
                    <path d="M0,0 L0,6 L8,3 z" fill="#94a3b8" />
                </marker>
            </defs>
            {edges.map((edge) => {
                const source = byId[edge.source];
                const target = byId[edge.target];
                if (!source || !target) {
                    return null;
                }

                const from = centerFor(source, layout);
                const to = centerFor(target, layout);
                const color = toneClasses[edge.tone]?.edge ?? '#64748b';
                const path = edgePath(source, target, layout, orientation);
                const primary = source.trunk && target.trunk;

                return (
                    <g key={edge.id} className={editMode ? 'cursor-pointer' : ''} onClick={editMode ? () => onSelectEdge(edge) : undefined}>
                        <path d={path} stroke="transparent" strokeWidth="14" fill="none" />
                        <path
                            d={path}
                            stroke={selectedEdgeId === edge.id ? '#facc15' : color}
                            strokeWidth={primary ? 4 : 2}
                            fill="none"
                            opacity={primary ? 0.9 : 0.5}
                            markerEnd="url(#operations-arrow)"
                        />
                        {edge.label && (
                            <text x={(from.x + to.x) / 2} y={(from.y + to.y) / 2 - 8} fill="#cbd5e1" fontSize="12" textAnchor="middle">
                                {edge.label}
                            </text>
                        )}
                    </g>
                );
            })}
        </svg>
    );
}

function GraphNode({
    node,
    frame,
    selected,
    expanded,
    mode,
    editMode,
    isConnecting,
    onSelect,
    onPointerDown,
    onResizePointerDown,
    onHandlePointerDown,
    onExpand,
    onCollapse,
    onExpandAll,
    onCollapseAll,
    onFocus,
    onOpenEdit,
    onOpenConnect,
    onHide,
    onRemove,
    onSizeChange,
}) {
    const tone = toneClasses[frame.tone] ?? toneClasses.slate;
    const statusTone = statusColor(frame.status);
    const compact = frame.size === 'compact';
    const expandedSize = frame.size === 'expanded';
    const hasChildren = Number(node.child_count ?? 0) > 0;

    return (
        <div
            data-node-id={node.id}
            data-testid={`operations-node-${node.id}`}
            className={cx(
                'absolute z-10 rounded-xl border backdrop-blur transition',
                tone.node,
                selected && 'ring-2 ring-white/80',
                isConnecting && 'ring-2 ring-cyan-300',
            )}
            style={{ left: frame.position.x, top: frame.position.y, width: frame.width, height: frame.height }}
        >
            <button
                type="button"
                onClick={onSelect}
                onPointerDown={onPointerDown}
                className={cx('flex h-full w-full flex-col items-start justify-center rounded-xl p-3 text-left', editMode && 'cursor-move')}
            >
                <div className="flex w-full items-center gap-2">
                    <span className={cx('flex shrink-0 items-center justify-center rounded-lg border', compact ? 'h-8 w-8' : 'h-10 w-10', tone.chip)}>
                        <Icon type={frame.icon} className={compact ? 'h-4 w-4' : 'h-5 w-5'} />
                    </span>
                    <span className="min-w-0">
                        <span className={cx('block truncate font-semibold text-white', compact ? 'text-xs' : 'text-sm')}>{frame.title}</span>
                        {!compact && <span className="block truncate text-[11px] text-slate-300">{frame.subtitle}</span>}
                    </span>
                </div>
                {!compact && (
                    <div className="mt-3 flex flex-wrap items-center gap-1.5">
                        <span className={cx('inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide', tone.chip)}>
                            <span className={cx('h-1.5 w-1.5 rounded-full', statusTone)} />
                            {normalizeStatus(frame.status)}
                        </span>
                        {hasChildren && (
                            <span className="rounded-md border border-slate-600 bg-slate-950/50 px-1.5 py-0.5 text-[10px] font-semibold text-slate-300">
                                {node.children_label ?? `${node.child_count} children`}
                            </span>
                        )}
                    </div>
                )}
                {expandedSize && (
                    <p className="mt-3 line-clamp-4 text-xs leading-5 text-slate-200">
                        {node.description ?? node.summary ?? 'No expanded description configured.'}
                    </p>
                )}
            </button>

            {hasChildren && (
                <div className="absolute -right-3 top-3 flex flex-col gap-1">
                    <button type="button" title={expanded ? 'Collapse node' : 'Expand node'} onClick={expanded ? onCollapse : onExpand} className="flex h-7 w-7 items-center justify-center rounded-full border border-violet-300/40 bg-slate-950 text-sm font-bold text-violet-100">
                        {expanded ? '-' : '+'}
                    </button>
                </div>
            )}

            {editMode && (
                <>
                    <button type="button" title="Connection handle" onPointerDown={onHandlePointerDown} className="absolute -right-2 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full border border-cyan-200 bg-cyan-400 shadow-lg shadow-cyan-950/40" />
                    <button type="button" title="Resize" onPointerDown={onResizePointerDown} className="absolute bottom-1 right-1 h-4 w-4 rounded-br-lg border-b-2 border-r-2 border-violet-200" />
                    {selected && (
                        <NodeActionMenu
                            node={node}
                            expanded={expanded}
                            onExpand={onExpand}
                            onCollapse={onCollapse}
                            onExpandAll={onExpandAll}
                            onCollapseAll={onCollapseAll}
                            onFocus={onFocus}
                            onOpenEdit={onOpenEdit}
                            onOpenConnect={onOpenConnect}
                            onHide={onHide}
                            onRemove={onRemove}
                            onSizeChange={onSizeChange}
                        />
                    )}
                </>
            )}

            {mode === 'view' && selected && hasChildren && (
                <div className="absolute left-3 top-full mt-2 flex gap-1 rounded-lg border border-slate-700 bg-slate-950/90 p-1 text-xs shadow-xl">
                    <button type="button" onClick={expanded ? onCollapse : onExpand} className="rounded px-2 py-1 font-semibold text-slate-200 hover:bg-slate-800">{expanded ? 'Collapse branch' : 'Expand branch'}</button>
                    <button type="button" onClick={onFocus} className="rounded px-2 py-1 font-semibold text-slate-200 hover:bg-slate-800">Focus on this branch</button>
                </div>
            )}
        </div>
    );
}

function NodeActionMenu({ node, expanded, onExpand, onCollapse, onExpandAll, onCollapseAll, onFocus, onOpenEdit, onOpenConnect, onHide, onRemove, onSizeChange }) {
    return (
        <div data-testid="operations-node-action-menu" className="absolute left-0 top-full z-30 mt-2 flex max-w-[360px] flex-wrap gap-1 rounded-lg border border-slate-700 bg-slate-950/95 p-1 shadow-xl shadow-slate-950/60">
            {node.route && <Link href={node.route} title="Open" className="rounded p-1.5 text-slate-300 hover:bg-slate-800 hover:text-white"><Icon type="route" /></Link>}
            <button type="button" title={expanded ? 'Collapse' : 'Expand'} onClick={expanded ? onCollapse : onExpand} className="rounded p-1.5 text-slate-300 hover:bg-slate-800 hover:text-white">{expanded ? '-' : '+'}</button>
            <button type="button" title="Expand all children" onClick={onExpandAll} className="rounded px-2 py-1 text-xs font-semibold text-slate-300 hover:bg-slate-800 hover:text-white">All</button>
            <button type="button" title="Collapse all children" onClick={onCollapseAll} className="rounded px-2 py-1 text-xs font-semibold text-slate-300 hover:bg-slate-800 hover:text-white">None</button>
            <button type="button" title="Focus on this branch" onClick={onFocus} className="rounded px-2 py-1 text-xs font-semibold text-slate-300 hover:bg-slate-800 hover:text-white">Focus</button>
            <button type="button" title="Edit" onClick={onOpenEdit} className="rounded p-1.5 text-slate-300 hover:bg-slate-800 hover:text-white"><Icon type="edit" /></button>
            <button type="button" title="Connect" onClick={onOpenConnect} className="rounded p-1.5 text-slate-300 hover:bg-slate-800 hover:text-white"><Icon type="connect" /></button>
            <span title="Move" className="rounded p-1.5 text-slate-300"><Icon type="process" /></span>
            <span title="Resize" className="rounded p-1.5 text-slate-300"><Icon type="fullscreen" /></span>
            <button type="button" title="Compact" onClick={() => onSizeChange('compact')} className="rounded px-2 py-1 text-xs font-semibold text-slate-300 hover:bg-slate-800 hover:text-white">C</button>
            <button type="button" title="Standard" onClick={() => onSizeChange('standard')} className="rounded px-2 py-1 text-xs font-semibold text-slate-300 hover:bg-slate-800 hover:text-white">S</button>
            <button type="button" title="Expanded" onClick={() => onSizeChange('expanded')} className="rounded px-2 py-1 text-xs font-semibold text-slate-300 hover:bg-slate-800 hover:text-white">E</button>
            <button type="button" title="Hide from map" onClick={onHide} className="rounded p-1.5 text-amber-200 hover:bg-amber-500/10"><Icon type="hide" /></button>
            {node.custom ? (
                <button type="button" title="Delete map node" onClick={onRemove} className="rounded p-1.5 text-red-200 hover:bg-red-500/10"><Icon type="trash" /></button>
            ) : (
                <span title="Delete map node is unavailable for real Miriam entities. Use Hide from map." className="rounded p-1.5 text-slate-600"><Icon type="trash" /></span>
            )}
        </div>
    );
}

function Legend({ graph }) {
    return (
        <div className="absolute left-4 top-4 z-20 hidden max-w-[230px] rounded-xl border border-slate-800 bg-slate-950/75 p-3 text-xs text-slate-300 shadow-xl shadow-slate-950/30 backdrop-blur lg:block">
            <div className="mb-2 font-semibold uppercase tracking-wide text-slate-500">Legend</div>
            <div className="space-y-1.5">
                {(graph?.legend ?? []).map((item) => (
                    <div key={item.label} className="flex items-center gap-2">
                        <span className={cx('h-2.5 w-2.5 rounded-sm', toneClasses[item.tone]?.dot ?? toneClasses.slate.dot)} />
                        <span>{item.label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function MiniMap({ nodes, edges, selectedId, layout, setTransform, open, setOpen, defaultViewport }) {
    const bounds = graphBounds(nodes, layout) ?? { x: 0, y: 0, width: 1, height: 1 };
    const width = 190;
    const height = 130;
    const scale = Math.min((width - 16) / bounds.width, (height - 16) / bounds.height);

    return (
        <div data-testid="operations-minimap" className="absolute bottom-4 left-4 z-20 hidden rounded-xl border border-slate-800 bg-slate-950/75 shadow-xl shadow-slate-950/30 backdrop-blur md:block">
            <button type="button" onClick={() => setOpen(!open)} className="flex w-full items-center justify-between gap-3 px-3 py-2 text-xs font-semibold text-slate-300">
                Minimap
                <span>{open ? '-' : '+'}</span>
            </button>
            {open && (
                <button type="button" onClick={() => setTransform(defaultViewport)} className="block px-2 pb-2">
                    <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} aria-hidden="true">
                        {edges.map((edge) => {
                            const source = nodes.find((node) => node.id === edge.source);
                            const target = nodes.find((node) => node.id === edge.target);
                            if (!source || !target) {
                                return null;
                            }
                            const from = centerFor(source, layout);
                            const to = centerFor(target, layout);
                            return <line key={edge.id} x1={(from.x - bounds.x) * scale + 8} y1={(from.y - bounds.y) * scale + 8} x2={(to.x - bounds.x) * scale + 8} y2={(to.y - bounds.y) * scale + 8} stroke="#475569" strokeWidth="1" />;
                        })}
                        {nodes.map((node) => {
                            const frame = nodeFrame(node, layout);
                            return (
                                <rect
                                    key={node.id}
                                    x={(frame.position.x - bounds.x) * scale + 8}
                                    y={(frame.position.y - bounds.y) * scale + 8}
                                    width={Math.max(8, frame.width * scale)}
                                    height={Math.max(6, frame.height * scale)}
                                    rx="3"
                                    fill={node.id === selectedId ? '#a78bfa' : '#334155'}
                                />
                            );
                        })}
                    </svg>
                </button>
            )}
        </div>
    );
}

function DetailsPanel({
    graph,
    node,
    edge,
    nodesById,
    edges,
    details,
    loading,
    expanded,
    setExpanded,
    mode,
    canEdit,
    draftLayout,
    activeTab,
    setActiveTab,
    onSizeChange,
    onUpdateNode,
    onOpenConnect,
    onDisconnect,
    onHide,
    onRemove,
    actionPanel,
    selectedOutputPanel,
}) {
    const tabs = ['Overview', 'Actions', 'Connections', 'Edit', 'History'];
    const frame = node ? nodeFrame(node, draftLayout) : null;
    const incoming = node ? edges.filter((candidate) => candidate.target === node.id) : [];
    const outgoing = node ? edges.filter((candidate) => candidate.source === node.id) : [];

    return (
        <aside data-testid="operations-details-panel" className="flex h-full min-h-0 flex-col">
            <div className="flex items-center justify-between border-b border-slate-800 px-4 py-3">
                <div>
                    <div className="text-sm font-semibold text-white">Node Details</div>
                    <div className="text-[11px] text-slate-500">{graph?.view}</div>
                </div>
                <button type="button" onClick={() => setExpanded(!expanded)} className="rounded-md border border-slate-700 px-2 py-1 text-xs font-semibold text-slate-300">
                    {expanded ? 'Collapse' : 'Expand'}
                </button>
            </div>

            <div className="flex gap-1 overflow-x-auto border-b border-slate-800 px-3 py-2">
                {tabs.map((tab) => (
                    <button key={tab} type="button" onClick={() => setActiveTab(tab)} className={cx('rounded-md px-2 py-1 text-xs font-semibold', activeTab === tab ? 'bg-violet-500 text-white' : 'text-slate-400 hover:bg-slate-800')}>
                        {tab}
                    </button>
                ))}
            </div>

            <div className={cx('premium-scrollbar min-h-0 flex-1 space-y-3 overflow-y-auto p-4', expanded && 'text-sm')}>
                {!node ? (
                    <div className="rounded-lg border border-slate-800 bg-slate-950/45 p-4 text-sm text-slate-400">No node selected.</div>
                ) : (
                    <>
                        <section className="rounded-lg border border-slate-800 bg-slate-950/45 p-4">
                            <div className="flex items-start gap-3">
                                <span className={cx('flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border', (toneClasses[frame.tone] ?? toneClasses.slate).chip)}>
                                    <Icon type={frame.icon} className="h-6 w-6" />
                                </span>
                                <div className="min-w-0">
                                    <div className="text-lg font-semibold text-white">{frame.title}</div>
                                    <div className="mt-1 text-sm text-slate-400">{frame.subtitle}</div>
                                    <span className={cx('mt-3 inline-flex rounded-md border px-2 py-1 text-xs font-semibold', (toneClasses[frame.tone] ?? toneClasses.slate).chip)}>
                                        {normalizeStatus(frame.status)}
                                    </span>
                                </div>
                            </div>
                        </section>

                        {activeTab === 'Overview' && (
                            <>
                                <Detail title="Meaning">
                                    <p>{loading ? 'Loading details...' : (details?.description ?? node.description ?? node.summary ?? 'No additional detail loaded yet.')}</p>
                                </Detail>
                                <Detail title="Purpose">
                                    <div className="space-y-2 text-xs">
                                        <div>Status: {normalizeStatus(frame.status)}</div>
                                        <div>Owner: {node.owner ?? 'You / Miriam workspace'}</div>
                                        <div>Input: {node.input ?? 'Sanitized summary input only.'}</div>
                                        <div>Output: {node.output ?? 'A safe next state in Miriam.'}</div>
                                        <div>Next step: {node.next_step ?? 'Continue the journey.'}</div>
                                    </div>
                                </Detail>
                                <Detail title="Criteria">
                                    <div className="space-y-2 text-xs">
                                        <div>Entry: {node.entry_criteria ?? 'Not specified.'}</div>
                                        <div>Exit: {node.exit_criteria ?? 'Not specified.'}</div>
                                    </div>
                                </Detail>
                                <Detail title="Associated page">
                                    {node.route ? <Link href={node.route} className="inline-flex rounded-md border border-blue-400/40 bg-blue-500/15 px-2 py-1 text-xs font-semibold text-blue-100">Open page</Link> : <span className="inline-flex rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-400">No verified route</span>}
                                </Detail>
                            </>
                        )}

                        {activeTab === 'Actions' && (
                            <>
                                {selectedOutputPanel}
                                {actionPanel}
                                <Detail title="Map actions">
                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                        {node.route && <Link href={node.route} className="rounded-md border border-blue-400/30 bg-blue-500/10 px-2 py-1.5 font-semibold text-blue-100">Open</Link>}
                                        <button type="button" onClick={() => onSizeChange(node, 'expanded')} className="rounded-md border border-violet-400/30 bg-violet-500/10 px-2 py-1.5 font-semibold text-violet-100">Expand cube</button>
                                        {canEdit && <button type="button" onClick={() => onOpenConnect(node)} className="rounded-md border border-cyan-400/30 bg-cyan-500/10 px-2 py-1.5 font-semibold text-cyan-100">Connect</button>}
                                        {canEdit && <button type="button" onClick={() => onHide(node)} className="rounded-md border border-amber-400/30 bg-amber-500/10 px-2 py-1.5 font-semibold text-amber-100">Hide from map</button>}
                                        {canEdit && node.custom && <button type="button" onClick={() => onRemove(node)} className="rounded-md border border-red-400/30 bg-red-500/10 px-2 py-1.5 font-semibold text-red-100">Delete map node</button>}
                                    </div>
                                    {mode === 'view' && <p className="mt-3 text-xs text-slate-400">Destructive map actions are hidden in View Mode.</p>}
                                </Detail>
                            </>
                        )}

                        {activeTab === 'Connections' && (
                            <>
                                {edge && (
                                    <Detail title="Selected connection">
                                        <div className="space-y-2 text-xs">
                                            <div>{nodesById[edge.source]?.title}{' -> '}{nodesById[edge.target]?.title}</div>
                                            <div>Type: {edge.type ?? 'next step'}</div>
                                            <div>Label: {edge.label ?? 'None'}</div>
                                            {canEdit && <button type="button" onClick={() => onDisconnect(edge)} className="rounded-md border border-red-400/30 bg-red-500/10 px-2 py-1.5 font-semibold text-red-100">Disconnect</button>}
                                        </div>
                                    </Detail>
                                )}
                                <ConnectionList title="Incoming connections" edges={incoming} nodesById={nodesById} />
                                <ConnectionList title="Outgoing connections" edges={outgoing} nodesById={nodesById} />
                                {canEdit && (
                                    <Detail title="Disconnect safety">
                                        <p>Disconnect removes only the visual/catalogued relationship. It does not delete tasks, projects, captures, agent runs, routes, services, models, or tables.</p>
                                    </Detail>
                                )}
                            </>
                        )}

                        {activeTab === 'Edit' && (
                            <EditNodePanel graph={graph} node={node} frame={frame} canEdit={canEdit} onUpdateNode={onUpdateNode} onSizeChange={onSizeChange} />
                        )}

                        {activeTab === 'History' && (
                            <Detail title="Layout history">
                                {(draftLayout.history ?? []).length === 0 ? (
                                    <p>No layout/catalogue changes saved in this browser-local draft.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {(draftLayout.history ?? []).slice().reverse().map((item, index) => (
                                            <div key={`${item.at}-${index}`} className="rounded-md border border-slate-800 bg-slate-950/60 p-2 text-xs">
                                                <div className="font-semibold text-slate-200">{item.label}</div>
                                                <div className="mt-1 text-slate-500">{item.actor ?? 'You'} / {item.at}</div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </Detail>
                        )}

                        <Detail title="Privacy">
                            <p>{details?.privacy_note ?? 'This panel avoids raw private records and loads sanitized details only.'}</p>
                        </Detail>
                    </>
                )}
            </div>
        </aside>
    );
}

function ConnectionList({ title, edges, nodesById }) {
    return (
        <Detail title={title}>
            {edges.length === 0 ? (
                <p>No visible connections.</p>
            ) : (
                <div className="space-y-2 text-xs">
                    {edges.map((edge) => (
                        <div key={edge.id} className="rounded-md border border-slate-800 bg-slate-950/60 p-2">
                            <div>{nodesById[edge.source]?.title}{' -> '}{nodesById[edge.target]?.title}</div>
                            <div className="mt-1 text-slate-500">{edge.label ?? edge.type ?? 'next step'}</div>
                        </div>
                    ))}
                </div>
            )}
        </Detail>
    );
}

function EditNodePanel({ graph, node, frame, canEdit, onUpdateNode, onSizeChange }) {
    const [form, setForm] = useState({
        title: frame.title,
        subtitle: frame.subtitle ?? '',
        status: frame.status ?? '',
        tone: frame.tone ?? node.tone ?? 'slate',
        icon: frame.icon ?? node.category ?? 'process',
        branch_position: node.branch_direction ?? 'auto',
        display_order: node.display_order ?? '',
        purpose: node.description ?? '',
        input: node.input ?? '',
        output: node.output ?? '',
        next_step: node.next_step ?? '',
        access_roles: node.access_roles ?? 'Current authorised workspace user',
    });

    useEffect(() => {
        setForm({
            title: frame.title,
            subtitle: frame.subtitle ?? '',
            status: frame.status ?? '',
            tone: frame.tone ?? node.tone ?? 'slate',
            icon: frame.icon ?? node.category ?? 'process',
            branch_position: node.branch_direction ?? 'auto',
            display_order: node.display_order ?? '',
            purpose: node.description ?? '',
            input: node.input ?? '',
            output: node.output ?? '',
            next_step: node.next_step ?? '',
            access_roles: node.access_roles ?? 'Current authorised workspace user',
        });
    }, [frame.title, frame.subtitle, frame.status, frame.tone, frame.icon, node]);

    if (!canEdit) {
        return (
            <Detail title="Edit">
                <p>This map is read-only for your current permissions. Technical traces and verified source facts are not editable from the canvas.</p>
            </Detail>
        );
    }

    const readOnlyTrace = graph?.layout?.read_only_trace === true;

    return (
        <Detail title="Edit presentation">
            {readOnlyTrace && <p className="mb-3 rounded-md border border-amber-400/30 bg-amber-500/10 p-2 text-xs text-amber-100">Verified route, controller, service, model, and table facts remain read-only. Only personal presentation fields are saved locally.</p>}
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    onUpdateNode(node.id, {
                        title: form.title,
                        subtitle: form.subtitle,
                        status: form.status,
                        tone: form.tone,
                        icon: form.icon,
                        branch_position: form.branch_position,
                        display_order: form.display_order,
                        purpose: form.purpose,
                        input: form.input,
                        output: form.output,
                        next_step: form.next_step,
                        access_roles: form.access_roles,
                    }, 'Edited node presentation');
                }}
            >
                <TextField label="Display title" value={form.title} onChange={(value) => setForm({ ...form, title: value })} />
                <TextField label="Short description" value={form.subtitle} onChange={(value) => setForm({ ...form, subtitle: value })} />
                <TextField label="Display status" value={form.status} onChange={(value) => setForm({ ...form, status: value })} />
                <TextField label="Visual type / icon" value={form.icon} onChange={(value) => setForm({ ...form, icon: value })} />
                <TextField label="Branch position" value={form.branch_position} onChange={(value) => setForm({ ...form, branch_position: value })} />
                <TextField label="Display order" value={form.display_order} onChange={(value) => setForm({ ...form, display_order: value })} />
                <TextField label="Purpose" value={form.purpose} onChange={(value) => setForm({ ...form, purpose: value })} />
                <TextField label="Input" value={form.input} onChange={(value) => setForm({ ...form, input: value })} />
                <TextField label="Output" value={form.output} onChange={(value) => setForm({ ...form, output: value })} />
                <TextField label="Next step" value={form.next_step} onChange={(value) => setForm({ ...form, next_step: value })} />
                <TextField label="Access roles" value={form.access_roles} onChange={(value) => setForm({ ...form, access_roles: value })} />
                <div className="flex flex-wrap gap-2">
                    {['compact', 'standard', 'expanded'].map((size) => (
                        <button key={size} type="button" onClick={() => onSizeChange(node, size)} className="rounded-md border border-slate-700 px-2 py-1 text-xs font-semibold capitalize text-slate-200 hover:bg-slate-800">{size}</button>
                    ))}
                </div>
                <div className="flex gap-2">
                    <button type="submit" className="rounded-md border border-lime-400/30 bg-lime-500/10 px-3 py-1.5 text-xs font-semibold text-lime-100">Save</button>
                    <button type="button" onClick={() => setForm({ ...form, title: frame.title, subtitle: frame.subtitle })} className="rounded-md border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-200">Cancel</button>
                </div>
            </form>
        </Detail>
    );
}

function TextField({ label, value, onChange }) {
    return (
        <label className="block text-xs text-slate-300">
            <span className="mb-1 block font-semibold text-slate-500">{label}</span>
            <input value={value ?? ''} onChange={(event) => onChange(event.target.value)} className="w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-xs text-slate-100 focus:border-violet-400 focus:ring-violet-400/30" />
        </label>
    );
}

function ConnectionModal({ connection, nodesById, onChange, onSave, onCancel }) {
    return (
        <div data-testid="operations-connection-modal" className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4">
            <div className="w-full max-w-md rounded-xl border border-slate-700 bg-[#07101d] p-4 text-slate-100 shadow-2xl">
                <div className="text-lg font-semibold">Connection editing</div>
                <p className="mt-1 text-sm text-slate-400">Create a visual relationship only. Underlying Miriam records are unchanged.</p>
                <div className="mt-4 rounded-md border border-slate-800 bg-slate-950/60 p-3 text-sm">
                    {nodesById[connection.source]?.title}{' -> '}{nodesById[connection.target]?.title}
                </div>
                <div className="mt-4 space-y-3">
                    <label className="block text-xs font-semibold text-slate-400">
                        Connection type
                        <select value={connection.type} onChange={(event) => onChange({ ...connection, type: event.target.value })} className="mt-1 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-2 text-slate-100">
                            {['next step', 'yes', 'no', 'clarification', 'creates', 'updates', 'requires approval', 'triggers', 'related'].map((type) => <option key={type} value={type}>{type}</option>)}
                        </select>
                    </label>
                    <TextField label="Connection label" value={connection.label} onChange={(value) => onChange({ ...connection, label: value })} />
                    <label className="block text-xs font-semibold text-slate-400">
                        Direction
                        <select value={connection.direction} onChange={(event) => onChange({ ...connection, direction: event.target.value })} className="mt-1 w-full rounded-md border border-slate-700 bg-slate-950 px-2 py-2 text-slate-100">
                            <option value="source-target">Source to target</option>
                            <option value="reverse">Target to source</option>
                        </select>
                    </label>
                </div>
                <div className="mt-4 flex justify-end gap-2">
                    <button type="button" onClick={onCancel} className="rounded-md border border-slate-700 px-3 py-1.5 text-sm font-semibold text-slate-200">Cancel</button>
                    <button type="button" onClick={() => onSave(connection)} className="rounded-md border border-cyan-300/40 bg-cyan-500/15 px-3 py-1.5 text-sm font-semibold text-cyan-100">Save connection</button>
                </div>
            </div>
        </div>
    );
}

function Detail({ title, children }) {
    return (
        <section className="rounded-lg border border-slate-800 bg-slate-950/45 p-3 text-sm leading-6 text-slate-300">
            <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{title}</div>
            {children}
        </section>
    );
}
