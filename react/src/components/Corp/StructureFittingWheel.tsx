import React, { useState } from 'react';

interface FittingCharge {
    itemId: number;
    typeId: number;
    typeName: string;
    quantity: number;
    locationFlag: string;
}

interface FittingItem {
    itemId: number;
    typeId: number;
    typeName: string;
    locationFlag: string;
    slotIndex?: number | null;
    quantity: number;
    charges?: FittingCharge[];
}

interface StructureFittings {
    services?: FittingItem[];
    rigs?: FittingItem[];
    high?: FittingItem[];
    medium?: FittingItem[];
    low?: FittingItem[];
    fuel?: FittingItem[];
    fighters?: FittingItem[];
    other?: FittingItem[];
}

interface StructureService {
    name: string;
    state: string;
}

interface UpwellStructure {
    id: string;
    name: string;
    typeId: number;
    typeName: string;
    solarSystemId: number;
    solarSystemName: string;
    state: string;
    fuelExpires: string | null;
    services?: StructureService[];
    reinforceHour: number | null;
    lastUpdated: string | null;
    fittings?: StructureFittings;
}

interface StructureFittingWheelProps {
    structure: UpwellStructure;
    imagePaths: {
        types: string;
        corporations: string;
        renders?: string;
    };
}

interface SlotDefinition {
    id: string;
    type: 'high' | 'medium' | 'low' | 'rig' | 'service';
    slotNumber: number;
    title: string;
    angle: number; // in degrees, 0 = top, clockwise
    color: string;
    glowColor: string;
    item?: FittingItem;
    serviceState?: string;
}

export default function StructureFittingWheel({
    structure,
    imagePaths,
}: StructureFittingWheelProps) {
    const [hoveredSlot, setHoveredSlot] = useState<SlotDefinition | null>(null);

    const getTypeIconUrl = (typeId: number) => {
        return imagePaths.types.replace('12345', typeId.toString());
    };

    const getTypeRenderUrl = (typeId: number) => {
        if (imagePaths.renders) {
            return imagePaths.renders.replace('12345', typeId.toString());
        }
        return getTypeIconUrl(typeId);
    };

    const fittings = structure.fittings || {};
    const highFittings = fittings.high || [];
    const medFittings = fittings.medium || [];
    const lowFittings = fittings.low || [];
    const rigFittings = fittings.rigs || [];
    const serviceFittings = fittings.services || [];
    const fuelFittings = fittings.fuel || [];
    const fighterFittings = fittings.fighters || [];

    // Determine slot counts (dynamic based on structure or max index)
    const numHigh = Math.max(3, highFittings.length, ...highFittings.map(f => (f.slotIndex ?? 0) + 1));
    const numMed = Math.max(3, medFittings.length, ...medFittings.map(f => (f.slotIndex ?? 0) + 1));
    const numLow = Math.max(3, lowFittings.length, ...lowFittings.map(f => (f.slotIndex ?? 0) + 1));
    const numRigs = Math.max(3, rigFittings.length);
    const numServices = Math.max(3, serviceFittings.length, ...serviceFittings.map(f => (f.slotIndex ?? 0) + 1));

    // Cap at reasonable limits
    const totalHighSlots = Math.min(8, numHigh);
    const totalMedSlots = Math.min(8, numMed);
    const totalLowSlots = Math.min(8, numLow);
    const totalRigSlots = Math.min(3, numRigs);
    const totalServiceSlots = Math.min(6, numServices);

    // Map fittings by slotIndex
    const highBySlot: Record<number, FittingItem> = {};
    highFittings.forEach((f, idx) => {
        const slot = f.slotIndex !== null && f.slotIndex !== undefined ? f.slotIndex : idx;
        highBySlot[slot] = f;
    });

    const medBySlot: Record<number, FittingItem> = {};
    medFittings.forEach((f, idx) => {
        const slot = f.slotIndex !== null && f.slotIndex !== undefined ? f.slotIndex : idx;
        medBySlot[slot] = f;
    });

    const lowBySlot: Record<number, FittingItem> = {};
    lowFittings.forEach((f, idx) => {
        const slot = f.slotIndex !== null && f.slotIndex !== undefined ? f.slotIndex : idx;
        lowBySlot[slot] = f;
    });

    const rigBySlot: Record<number, FittingItem> = {};
    rigFittings.forEach((f, idx) => {
        const slot = f.slotIndex !== null && f.slotIndex !== undefined ? f.slotIndex : idx;
        rigBySlot[slot] = f;
    });

    const serviceBySlot: Record<number, FittingItem> = {};
    serviceFittings.forEach((f, idx) => {
        const slot = f.slotIndex !== null && f.slotIndex !== undefined ? f.slotIndex : idx;
        serviceBySlot[slot] = f;
    });

    // Build all slot definitions around the wheel
    const slots: SlotDefinition[] = [];

    // Helper to generate angles evenly across an arc
    const generateArcAngles = (startAngle: number, endAngle: number, count: number): number[] => {
        if (count === 1) return [(startAngle + endAngle) / 2];
        const step = (endAngle - startAngle) / (count - 1);
        const angles: number[] = [];
        for (let i = 0; i < count; i++) {
            angles.push(startAngle + i * step);
        }
        return angles;
    };

    // 1. High Slots: Top arc (from -45° to +45° or 315° to 45°)
    const highAngles = generateArcAngles(-42, 42, totalHighSlots);
    highAngles.forEach((angle, idx) => {
        const normAngle = angle < 0 ? angle + 360 : angle;
        slots.push({
            id: `high_${idx}`,
            type: 'high',
            slotNumber: idx + 1,
            title: `High Slot ${idx + 1}`,
            angle: normAngle,
            color: '#f43f5e',
            glowColor: 'rgba(244, 63, 94, 0.4)',
            item: highBySlot[idx],
        });
    });

    // 2. Medium Slots: Right arc (from 58° to 128°)
    const medAngles = generateArcAngles(58, 128, totalMedSlots);
    medAngles.forEach((angle, idx) => {
        slots.push({
            id: `med_${idx}`,
            type: 'medium',
            slotNumber: idx + 1,
            title: `Medium Slot ${idx + 1}`,
            angle,
            color: '#00f0ff',
            glowColor: 'rgba(0, 240, 255, 0.4)',
            item: medBySlot[idx],
        });
    });

    // 3. Low Slots: Bottom arc (from 142° to 212°)
    const lowAngles = generateArcAngles(142, 212, totalLowSlots);
    lowAngles.forEach((angle, idx) => {
        slots.push({
            id: `low_${idx}`,
            type: 'low',
            slotNumber: idx + 1,
            title: `Low Slot ${idx + 1}`,
            angle,
            color: '#f59e0b',
            glowColor: 'rgba(245, 158, 11, 0.4)',
            item: lowBySlot[idx],
        });
    });

    // 4. Rig Slots: Bottom-left arc (from 224° to 254°)
    const rigAngles = generateArcAngles(224, 254, totalRigSlots);
    rigAngles.forEach((angle, idx) => {
        slots.push({
            id: `rig_${idx}`,
            type: 'rig',
            slotNumber: idx + 1,
            title: `Rig Slot ${idx + 1}`,
            angle,
            color: '#a855f7',
            glowColor: 'rgba(168, 85, 247, 0.4)',
            item: rigBySlot[idx],
        });
    });

    // 5. Service Slots: Left/Top-left arc (from 266° to 304°)
    const serviceAngles = generateArcAngles(266, 304, totalServiceSlots);
    serviceAngles.forEach((angle, idx) => {
        const item = serviceBySlot[idx];
        // Match active service status if available
        let srvState: string | undefined = undefined;
        if (item && structure.services) {
            const matchedSrv = structure.services.find(s =>
                item.typeName.toLowerCase().includes(s.name.toLowerCase()) ||
                s.name.toLowerCase().includes(item.typeName.toLowerCase())
            );
            if (matchedSrv) {
                srvState = matchedSrv.state;
            }
        }

        slots.push({
            id: `service_${idx}`,
            type: 'service',
            slotNumber: idx + 1,
            title: `Service Slot ${idx + 1}`,
            angle,
            color: '#10b981',
            glowColor: 'rgba(16, 185, 129, 0.4)',
            item,
            serviceState: srvState,
        });
    });

    // Wheel Geometry parameters
    const cx = 180;
    const cy = 180;
    const radius = 135;
    const socketRadius = 16;

    // Convert polar angle to cartesian coordinates
    const getCoordinates = (angleDeg: number, r: number) => {
        const rad = ((angleDeg - 90) * Math.PI) / 180;
        return {
            x: cx + r * Math.cos(rad),
            y: cy + r * Math.sin(rad),
        };
    };

    return (
        <div className="flex flex-col lg:flex-row items-center justify-between gap-8 p-4 bg-[#0a0e1a]/90 rounded-xl border border-white/5 relative">
            {/* Left: The Fitting Wheel Interactive Canvas/SVG */}
            <div className="relative flex-shrink-0 flex items-center justify-center select-none">
                <svg
                    width="360"
                    height="360"
                    viewBox="0 0 360 360"
                    className="overflow-visible drop-shadow-[0_0_25px_rgba(0,240,255,0.05)]"
                >
                    <defs>
                        {/* Center Background Gradient */}
                        <radialGradient id={`wheel-center-grad-${structure.id}`} cx="50%" cy="50%" r="50%">
                            <stop offset="0%" stopColor="#17233d" />
                            <stop offset="70%" stopColor="#0c1222" />
                            <stop offset="100%" stopColor="#080c18" />
                        </radialGradient>

                        {/* Outer Glow filter */}
                        <filter id="slot-glow" x="-50%" y="-50%" width="200%" height="200%">
                            <feGaussianBlur stdDeviation="3" result="blur" />
                            <feComposite in="SourceGraphic" in2="blur" operator="over" />
                        </filter>
                    </defs>

                    {/* Outer HUD Rings */}
                    <circle
                        cx={cx}
                        cy={cy}
                        r={radius + 18}
                        fill="none"
                        stroke="#ffffff"
                        strokeOpacity="0.04"
                        strokeWidth="1"
                    />
                    <circle
                        cx={cx}
                        cy={cy}
                        r={radius}
                        fill="none"
                        stroke="#ffffff"
                        strokeOpacity="0.08"
                        strokeWidth="1.5"
                        strokeDasharray="4 6"
                    />
                    <circle
                        cx={cx}
                        cy={cy}
                        r={radius - 18}
                        fill="none"
                        stroke="#ffffff"
                        strokeOpacity="0.04"
                        strokeWidth="1"
                    />

                    {/* Section Accent Arcs */}
                    {/* High Slots Arc (Red) */}
                    <path
                        d={`M ${getCoordinates(-48, radius).x} ${getCoordinates(-48, radius).y} A ${radius} ${radius} 0 0 1 ${getCoordinates(48, radius).x} ${getCoordinates(48, radius).y}`}
                        fill="none"
                        stroke="#f43f5e"
                        strokeOpacity="0.3"
                        strokeWidth="3"
                        strokeLinecap="round"
                    />
                    {/* Medium Slots Arc (Cyan) */}
                    <path
                        d={`M ${getCoordinates(54, radius).x} ${getCoordinates(54, radius).y} A ${radius} ${radius} 0 0 1 ${getCoordinates(132, radius).x} ${getCoordinates(132, radius).y}`}
                        fill="none"
                        stroke="#00f0ff"
                        strokeOpacity="0.3"
                        strokeWidth="3"
                        strokeLinecap="round"
                    />
                    {/* Low Slots Arc (Amber) */}
                    <path
                        d={`M ${getCoordinates(138, radius).x} ${getCoordinates(138, radius).y} A ${radius} ${radius} 0 0 1 ${getCoordinates(216, radius).x} ${getCoordinates(216, radius).y}`}
                        fill="none"
                        stroke="#f59e0b"
                        strokeOpacity="0.3"
                        strokeWidth="3"
                        strokeLinecap="round"
                    />
                    {/* Rig Slots Arc (Purple) */}
                    <path
                        d={`M ${getCoordinates(220, radius).x} ${getCoordinates(220, radius).y} A ${radius} ${radius} 0 0 1 ${getCoordinates(258, radius).x} ${getCoordinates(258, radius).y}`}
                        fill="none"
                        stroke="#a855f7"
                        strokeOpacity="0.3"
                        strokeWidth="3"
                        strokeLinecap="round"
                    />
                    {/* Service Slots Arc (Green) */}
                    <path
                        d={`M ${getCoordinates(262, radius).x} ${getCoordinates(262, radius).y} A ${radius} ${radius} 0 0 1 ${getCoordinates(308, radius).x} ${getCoordinates(308, radius).y}`}
                        fill="none"
                        stroke="#10b981"
                        strokeOpacity="0.3"
                        strokeWidth="3"
                        strokeLinecap="round"
                    />

                    {/* Center Structure Display */}
                    <circle
                        cx={cx}
                        cy={cy}
                        r="64"
                        fill={`url(#wheel-center-grad-${structure.id})`}
                        stroke="#00f0ff"
                        strokeOpacity="0.25"
                        strokeWidth="2"
                    />
                    <circle
                        cx={cx}
                        cy={cy}
                        r="60"
                        fill="none"
                        stroke="#ffffff"
                        strokeOpacity="0.08"
                        strokeWidth="1"
                    />

                    {/* Render all slots */}
                    {slots.map((slot) => {
                        const { x, y } = getCoordinates(slot.angle, radius);
                        const isHovered = hoveredSlot?.id === slot.id;
                        const isFitted = !!slot.item;
                        const hasCharges = slot.item?.charges && slot.item.charges.length > 0;

                        return (
                            <g
                                key={slot.id}
                                className="cursor-pointer transition-transform duration-150"
                                onMouseEnter={() => setHoveredSlot(slot)}
                                onMouseLeave={() => setHoveredSlot(null)}
                            >
                                {/* Slot Background Socket */}
                                <circle
                                    cx={x}
                                    cy={y}
                                    r={socketRadius + (isHovered ? 2 : 0)}
                                    fill="#0c101c"
                                    stroke={isHovered ? slot.color : isFitted ? slot.color : '#ffffff'}
                                    strokeOpacity={isHovered ? 1 : isFitted ? 0.7 : 0.2}
                                    strokeWidth={isHovered ? 2.5 : isFitted ? 1.5 : 1}
                                    style={{
                                        filter: isHovered ? `drop-shadow(0 0 8px ${slot.glowColor})` : undefined,
                                    }}
                                />

                                {/* Module Icon inside socket */}
                                {isFitted ? (
                                    <>
                                        <clipPath id={`clip-${structure.id}-${slot.id}`}>
                                            <circle cx={x} cy={y} r={socketRadius - 1.5} />
                                        </clipPath>
                                        <image
                                            x={x - socketRadius + 1.5}
                                            y={y - socketRadius + 1.5}
                                            width={(socketRadius - 1.5) * 2}
                                            height={(socketRadius - 1.5) * 2}
                                            href={getTypeIconUrl(slot.item!.typeId)}
                                            clipPath={`url(#clip-${structure.id}-${slot.id})`}
                                            preserveAspectRatio="xMidYMid slice"
                                        />
                                    </>
                                ) : (
                                    /* Empty slot indicator dot */
                                    <circle
                                        cx={x}
                                        cy={y}
                                        r="3"
                                        fill={slot.color}
                                        fillOpacity="0.25"
                                    />
                                )}

                                {/* Loaded Charge Indicator Badge */}
                                {hasCharges && (
                                    <circle
                                        cx={x + socketRadius - 4}
                                        cy={y + socketRadius - 4}
                                        r="4"
                                        fill="#00f0ff"
                                        stroke="#0c101c"
                                        strokeWidth="1.5"
                                        className="animate-pulse"
                                    />
                                )}
                            </g>
                        );
                    })}
                </svg>

                {/* Center Image & Info (HTML overlay inside the SVG center) */}
                <div
                    className="absolute pointer-events-none flex flex-col items-center justify-center text-center p-2"
                    style={{ width: '120px', height: '120px' }}
                >
                    <img
                        src={getTypeRenderUrl(structure.typeId)}
                        alt={structure.typeName}
                        className="w-16 h-16 object-contain drop-shadow-[0_0_12px_rgba(0,0,0,0.8)]"
                        onError={(e) => {
                            (e.target as HTMLImageElement).src = getTypeIconUrl(structure.typeId);
                        }}
                    />
                    <span className="text-[10px] font-semibold text-white/90 truncate max-w-[100px] leading-tight mt-0.5">
                        {structure.typeName}
                    </span>
                    <span className="text-[9px] text-sky-400 font-mono">
                        {structure.solarSystemName}
                    </span>
                </div>
            </div>

            {/* Right: Interactive Inspector HUD Tooltip / Details Panel */}
            <div className="flex-1 min-w-[280px] w-full flex flex-col gap-4">
                {/* Active Hover Inspector Card */}
                <div className="bg-[#111625] border border-white/10 rounded-xl p-4 shadow-eve min-h-[160px] flex flex-col justify-center transition-all duration-200">
                    {hoveredSlot ? (
                        <div>
                            {/* Slot Header */}
                            <div className="flex items-center justify-between gap-2 border-b border-white/10 pb-2 mb-3">
                                <span
                                    className="text-xs font-bold uppercase tracking-wider flex items-center gap-1.5"
                                    style={{ color: hoveredSlot.color }}
                                >
                                    <span>
                                        {hoveredSlot.type === 'high' && '🎯'}
                                        {hoveredSlot.type === 'medium' && '🛡️'}
                                        {hoveredSlot.type === 'low' && '⚡'}
                                        {hoveredSlot.type === 'rig' && '🔧'}
                                        {hoveredSlot.type === 'service' && '⚙️'}
                                    </span>
                                    <span>{hoveredSlot.title}</span>
                                </span>
                                {hoveredSlot.item ? (
                                    <span className="text-[10px] px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 font-semibold">
                                        Fitted
                                    </span>
                                ) : (
                                    <span className="text-[10px] px-2 py-0.5 rounded bg-white/5 text-eve-muted border border-white/10 font-semibold">
                                        Frei
                                    </span>
                                )}
                            </div>

                            {hoveredSlot.item ? (
                                <div className="flex flex-col gap-3">
                                    {/* Module Info */}
                                    <div className="flex items-center gap-3">
                                        <img
                                            src={getTypeIconUrl(hoveredSlot.item.typeId)}
                                            alt={hoveredSlot.item.typeName}
                                            className="w-10 h-10 rounded-lg bg-[#0c101c] border border-white/10 p-0.5 object-contain flex-shrink-0"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm font-semibold text-white truncate">
                                                {hoveredSlot.item.typeName}
                                            </div>
                                            <div className="text-xs text-eve-muted font-mono mt-0.5">
                                                Slot: {hoveredSlot.item.locationFlag}
                                                {hoveredSlot.item.quantity > 1 && ` · Menge: ${hoveredSlot.item.quantity}`}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Service Module State (if service) */}
                                    {hoveredSlot.serviceState && (
                                        <div className="flex items-center gap-2 text-xs">
                                            <span className="text-eve-muted">Dienst-Status:</span>
                                            <span className="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 font-semibold">
                                                ● {hoveredSlot.serviceState}
                                            </span>
                                        </div>
                                    )}

                                    {/* Loaded Ammo / Charges / Crystals / Scripts */}
                                    {hoveredSlot.item.charges && hoveredSlot.item.charges.length > 0 && (
                                        <div className="mt-1 pt-2 border-t border-white/5 bg-[#0c101c]/50 p-2.5 rounded-lg border">
                                            <div className="text-[11px] font-bold text-sky-400 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                                                <span>🔋</span> Geladene Ladung / Munition:
                                            </div>
                                            <div className="flex flex-col gap-1.5">
                                                {hoveredSlot.item.charges.map((charge, cIdx) => (
                                                    <div key={cIdx} className="flex items-center justify-between gap-2 text-xs text-white">
                                                        <div className="flex items-center gap-2 truncate">
                                                            <img
                                                                src={getTypeIconUrl(charge.typeId)}
                                                                alt={charge.typeName}
                                                                className="w-5 h-5 rounded object-contain"
                                                            />
                                                            <span className="truncate font-medium">{charge.typeName}</span>
                                                        </div>
                                                        <span className="font-mono text-sky-300 font-bold flex-shrink-0">
                                                            {charge.quantity.toLocaleString()}x
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="text-xs text-eve-muted italic py-4 text-center">
                                    Dieser Slot ist aktuell nicht belegt.
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="text-center py-6 text-eve-muted">
                            <div className="text-2xl mb-1.5">🔍</div>
                            <div className="text-xs font-medium text-white/80">
                                Bewege den Mauszeiger über einen Slot im Rad
                            </div>
                            <div className="text-[11px] text-eve-muted mt-0.5">
                                um Moduldetails, Ladungen und Dienste anzuzeigen.
                            </div>
                        </div>
                    )}
                </div>

                {/* Additional Inventory: Stored Fuel & Fighters */}
                {(fuelFittings.length > 0 || fighterFittings.length > 0) && (
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        {/* Stored Fuel */}
                        {fuelFittings.length > 0 && (
                            <div className="bg-[#111625] border border-white/10 rounded-xl p-3">
                                <div className="text-[11px] font-bold text-emerald-400 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                    <span>⛽</span> Eingelagerter Treibstoff
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    {fuelFittings.map((f, i) => (
                                        <div key={i} className="flex items-center justify-between gap-2 text-xs text-white">
                                            <div className="flex items-center gap-1.5 truncate">
                                                <img src={getTypeIconUrl(f.typeId)} alt="" className="w-4 h-4 rounded object-contain" />
                                                <span className="truncate">{f.typeName}</span>
                                            </div>
                                            <span className="font-mono text-emerald-400 font-bold">
                                                {(f.quantity || 0).toLocaleString()}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Fighters */}
                        {fighterFittings.length > 0 && (
                            <div className="bg-[#111625] border border-white/10 rounded-xl p-3">
                                <div className="text-[11px] font-bold text-indigo-400 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                    <span>🚀</span> Fighter Bay
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    {fighterFittings.map((f, i) => (
                                        <div key={i} className="flex items-center justify-between gap-2 text-xs text-white">
                                            <div className="flex items-center gap-1.5 truncate">
                                                <img src={getTypeIconUrl(f.typeId)} alt="" className="w-4 h-4 rounded object-contain" />
                                                <span className="truncate">{f.typeName}</span>
                                            </div>
                                            <span className="font-mono text-white/80 font-bold">
                                                {f.quantity || 1}x
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
