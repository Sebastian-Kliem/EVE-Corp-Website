import React, { useState, useMemo } from 'react';

interface Character {
    id: number;
    name: string;
}

interface Snapshot {
    characterId: number;
    date: string; // YYYY-MM-DD
    wallet: number;
    assets: number;
    total: number;
}

interface CurrentValue {
    characterId: number;
    wallet: number;
    assets: number;
    total: number;
}

interface ValueHistoryProps {
    characters: Character[];
    snapshots: Snapshot[];
    currentValues: CurrentValue[];
    omegaAccountCount: number;
}

const OMEGA_COST_ISK = 2500000000; // 2.5 Billion ISK

export default function ValueHistory({ characters, snapshots, currentValues, omegaAccountCount }: ValueHistoryProps) {
    const [selectedCharacterId, setSelectedCharacterId] = useState<number | 'all'>('all');
    const [hoveredPoint, setHoveredPoint] = useState<{ x: number; y: number; date: string; total: number } | null>(null);

    // Get current date string in local timezone (YYYY-MM-DD)
    const todayStr = useMemo(() => {
        const d = new Date();
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }, []);

    // 1. Process and aggregate snapshots
    const processedData = useMemo(() => {
        const dailyTotals: Record<string, { wallet: number; assets: number; total: number }> = {};

        // Process snapshots
        snapshots.forEach(s => {
            if (selectedCharacterId !== 'all' && s.characterId !== selectedCharacterId) {
                return;
            }
            if (!dailyTotals[s.date]) {
                dailyTotals[s.date] = { wallet: 0, assets: 0, total: 0 };
            }
            dailyTotals[s.date].wallet += s.wallet;
            dailyTotals[s.date].assets += s.assets;
            dailyTotals[s.date].total += s.total;
        });

        // Add current live values as the latest data point for today (to have real-time stats)
        let liveWallet = 0;
        let liveAssets = 0;
        currentValues.forEach(cv => {
            if (selectedCharacterId !== 'all' && cv.characterId !== selectedCharacterId) {
                return;
            }
            liveWallet += cv.wallet;
            liveAssets += cv.assets;
        });
        const liveTotal = liveWallet + liveAssets;

        // Overlay or add today's live data
        dailyTotals[todayStr] = { wallet: liveWallet, assets: liveAssets, total: liveTotal };

        // Sort dates chronologically
        const sortedDates = Object.keys(dailyTotals).sort();

        // Build list with profit/loss calculations
        const historyList: Array<{
            date: string;
            wallet: number;
            assets: number;
            total: number;
            change: number;
            hasPrev: boolean;
        }> = [];

        let prevTotal: number | null = null;
        sortedDates.forEach(d => {
            const data = dailyTotals[d];
            const change = prevTotal !== null ? data.total - prevTotal : 0;
            const hasPrev = prevTotal !== null;

            historyList.push({
                date: d,
                wallet: data.wallet,
                assets: data.assets,
                total: data.total,
                change: change,
                hasPrev: hasPrev
            });

            prevTotal = data.total;
        });

        // 2. Calculate summary statistics
        const currentWallet = liveWallet;
        const currentAssets = liveAssets;
        const currentTotal = liveTotal;

        // Earnings today so far
        let todayEarned = 0;
        if (historyList.length > 1) {
            const lastEntry = historyList[historyList.length - 1];
            if (lastEntry.date === todayStr) {
                todayEarned = lastEntry.change;
            }
        }

        return {
            historyList,
            currentWallet,
            currentAssets,
            currentTotal,
            todayEarned
        };
    }, [snapshots, currentValues, selectedCharacterId, todayStr]);

    const formatIsk = (val: number): string => {
        return val.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ISK';
    };

    const formatIskShort = (val: number): string => {
        const absVal = Math.abs(val);
        let formatted = '';
        if (absVal >= 1e12) {
            formatted = (val / 1e12).toFixed(2) + 'T';
        } else if (absVal >= 1e9) {
            formatted = (val / 1e9).toFixed(2) + 'B';
        } else if (absVal >= 1e6) {
            formatted = (val / 1e6).toFixed(2) + 'M';
        } else {
            formatted = val.toLocaleString('de-DE', { maximumFractionDigits: 0 });
        }
        return formatted + ' ISK';
    };

    const formatDateGerman = (dateStr: string): string => {
        const [year, month, day] = dateStr.split('-');
        return `${day}.${month}.${year}`;
    };

    // Calculate SVG Chart dimensions and coordinates
    const chartSvg = useMemo(() => {
        const list = processedData.historyList;
        if (list.length < 2) return null;

        const width = 850;
        const height = 280;
        const paddingLeft = 100;
        const paddingRight = 20;
        const paddingTop = 20;
        const paddingBottom = 40;

        const chartWidth = width - paddingLeft - paddingRight;
        const chartHeight = height - paddingTop - paddingBottom;

        const totals = list.map(h => h.total);
        const maxVal = Math.max(...totals);
        const minVal = Math.min(...totals);
        
        // Add 10% padding to range so line doesn't touch edges
        const paddingRange = (maxVal - minVal) * 0.1 || 1000000;
        const chartMax = maxVal + paddingRange;
        const chartMin = Math.max(0, minVal - paddingRange);
        const range = chartMax - chartMin;

        const points = list.map((h, index) => {
            const x = paddingLeft + (index / (list.length - 1)) * chartWidth;
            const y = paddingTop + chartHeight - ((h.total - chartMin) / range) * chartHeight;
            return { x, y, data: h };
        });

        const polylinePoints = points.map(p => `${p.x},${p.y}`).join(' ');
        const areaPath = `M ${points[0].x},${paddingTop + chartHeight} ` + 
                         points.map(p => `L ${p.x},${p.y}`).join(' ') + 
                         ` L ${points[points.length - 1].x},${paddingTop + chartHeight} Z`;

        // Y-axis grid lines (4 lines)
        const yGridLines = Array.from({ length: 4 }).map((_, i) => {
            const val = chartMin + (i / 3) * range;
            const y = paddingTop + chartHeight - (i / 3) * chartHeight;
            return { y, value: val };
        });

        // X-axis labels (up to 5 labels)
        const xLabels: Array<{ x: number; label: string }> = [];
        const labelInterval = Math.max(1, Math.floor(list.length / 5));
        list.forEach((h, index) => {
            if (index === 0 || index === list.length - 1 || index % labelInterval === 0) {
                xLabels.push({
                    x: points[index].x,
                    label: formatDateGerman(h.date)
                });
            }
        });

        return {
            width,
            height,
            points,
            polylinePoints,
            areaPath,
            yGridLines,
            xLabels
        };
    }, [processedData.historyList]);

    return (
        <div className="w-full">
            <div className="flex justify-between items-center flex-wrap gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-eve-primary" style={{ textShadow: '0 0 10px rgba(0, 240, 255, 0.3)' }}>
                        📈 Vermögensverlauf
                    </h1>
                </div>
                <div className="flex items-center ml-auto">
                    <div className="flex gap-2 items-center">
                        <span className="text-xs text-eve-muted">Charakter filtern:</span>
                        <div className="relative">
                            <select
                                value={selectedCharacterId}
                                onChange={(e) => {
                                    const val = e.target.value;
                                    setSelectedCharacterId(val === 'all' ? 'all' : parseInt(val, 10));
                                }}
                                className="rounded px-2.5 py-1 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300"
                            >
                                <option value="all" style={{ background: '#101525' }}>Alle Charaktere (Kombiniert)</option>
                                {characters.map(char => (
                                    <option key={char.id} value={char.id} style={{ background: '#101525' }}>{char.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {/* Statistics Cards */}
            <div className="flex flex-wrap gap-6 mb-6">
                <div className="flex-1 min-w-[280px]">
                    <div className="bg-eve-card border border-eve-border shadow-eve p-5 rounded-lg relative overflow-hidden">
                        <div className="absolute top-0 left-0 right-0 h-[3px] bg-eve-primary"></div>
                        <p className="text-xs text-eve-muted mb-2 uppercase tracking-wider">Gesamtvermögen</p>
                        <p className="text-xl font-bold mb-1 text-white">
                            {formatIskShort(processedData.currentTotal)}
                        </p>
                        <div className="text-xs text-eve-muted flex justify-between">
                            <span>Liquid: {formatIskShort(processedData.currentWallet)}</span>
                            <span>Assets: {formatIskShort(processedData.currentAssets)}</span>
                        </div>
                    </div>
                </div>
                
                <div className="flex-1 min-w-[280px]">
                    <div className="bg-eve-card border border-eve-border shadow-eve p-5 rounded-lg relative overflow-hidden">
                        <div 
                            className="absolute top-0 left-0 right-0 h-[3px]"
                            style={{ 
                                background: processedData.todayEarned > 0 ? '#00ffaa' : processedData.todayEarned < 0 ? '#f14668' : 'var(--theme-text-muted)' 
                            }}
                        ></div>
                        <p className="text-xs text-eve-muted mb-2 uppercase tracking-wider">Gewinn heute (Bisher)</p>
                        <p className={`text-xl font-bold mb-1 ${processedData.todayEarned > 0 ? 'text-emerald-400' : processedData.todayEarned < 0 ? 'text-rose-400' : 'text-white'}`}>
                            {processedData.todayEarned > 0 ? '+' : ''}{formatIsk(processedData.todayEarned)}
                        </p>
                        <p className="text-xs text-eve-muted">Vergleich mit dem Stand von gestern</p>
                    </div>
                </div>
            </div>

            {/* Daily History (Directly visible) */}
            <div>
                {/* SVG Area Chart */}
                <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6 overflow-x-auto" style={{ background: 'rgba(10, 15, 28, 0.8)' }}>
                    <h3 className="text-sm font-semibold text-eve-muted mb-3">Nettovermögen Entwicklung</h3>
                    {chartSvg ? (
                        <div className="relative w-full min-w-[850px]">
                            <svg viewBox={`0 0 ${chartSvg.width} ${chartSvg.height}`} width="100%" height={chartSvg.height}>
                                <defs>
                                    <linearGradient id="networthGlow" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stopColor="var(--theme-primary)" stopOpacity="0.35" />
                                        <stop offset="100%" stopColor="var(--theme-primary)" stopOpacity="0.0" />
                                    </linearGradient>
                                </defs>

                                {/* Grid Lines */}
                                {chartSvg.yGridLines.map((line, i) => (
                                    <g key={i}>
                                        <line 
                                            x1="100" y1={line.y} x2={chartSvg.width - 20} y2={line.y} 
                                            stroke="rgba(0, 240, 255, 0.08)" strokeDasharray="3,3" 
                                        />
                                        <text 
                                            x="90" y={line.y + 4} 
                                            fill="var(--theme-text-muted)" fontSize="10" textAnchor="end"
                                        >
                                            {formatIskShort(line.value)}
                                        </text>
                                    </g>
                                ))}

                                {/* Area Fill */}
                                <path d={chartSvg.areaPath} fill="url(#networthGlow)" />

                                {/* Line */}
                                <polyline 
                                    points={chartSvg.polylinePoints} 
                                    fill="none" stroke="var(--theme-primary)" strokeWidth="2.5" 
                                />

                                {/* Dots & Interactivity */}
                                {chartSvg.points.map((p, index) => (
                                    <g key={index}>
                                        <circle 
                                            cx={p.x} cy={p.y} r="5" 
                                            fill="var(--theme-bg)" stroke="var(--theme-primary)" strokeWidth="2"
                                            style={{ cursor: 'pointer' }}
                                            onMouseEnter={() => setHoveredPoint({ x: p.x, y: p.y, date: p.data.date, total: p.data.total })}
                                            onMouseLeave={() => setHoveredPoint(null)}
                                        />
                                        {/* Glow circle on hover */}
                                        {hoveredPoint?.date === p.data.date && (
                                            <circle 
                                                cx={p.x} cy={p.y} r="8" 
                                                fill="var(--theme-primary)" fillOpacity="0.3"
                                            />
                                        )}
                                    </g>
                                ))}

                                {/* X Axis Labels */}
                                {chartSvg.xLabels.map((lbl, i) => (
                                    <text 
                                        key={i} x={lbl.x} y={chartSvg.height - 15} 
                                        fill="var(--theme-text-muted)" fontSize="10" textAnchor="middle"
                                    >
                                        {lbl.label}
                                    </text>
                                ))}
                            </svg>

                            {/* Tooltip HTML */}
                            {hoveredPoint && (
                                <div style={{
                                    position: 'absolute',
                                    left: `${hoveredPoint.x}px`,
                                    top: `${hoveredPoint.y - 65}px`,
                                    transform: 'translateX(-50%)',
                                    backgroundColor: 'rgba(6, 9, 17, 0.95)',
                                    border: '1px solid var(--theme-primary)',
                                    borderRadius: '4px',
                                    padding: '6px 10px',
                                    pointerEvents: 'none',
                                    zIndex: 10,
                                    boxShadow: '0 4px 15px rgba(0,0,0,0.5)',
                                    minWidth: '160px',
                                    textAlign: 'center'
                                }}>
                                    <p style={{ fontSize: '0.75rem', color: 'var(--theme-text-muted)', margin: 0 }}>
                                        {formatDateGerman(hoveredPoint.date)}
                                    </p>
                                    <p style={{ fontSize: '0.85rem', fontWeight: 'bold', color: '#fff', margin: '2px 0 0 0' }}>
                                        {formatIskShort(hoveredPoint.total)}
                                    </p>
                                </div>
                            )}
                        </div>
                    ) : (
                        <p className="text-xs text-eve-muted text-center py-5">
                            Mindestens 2 Tage mit Daten sind erforderlich, um das Diagramm zu zeichnen.
                        </p>
                    )}
                </div>

                {/* Daily History Table */}
                <div className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6">
                    <h3 className="text-sm font-semibold text-eve-muted mb-3">Tägliche Aufzeichnungen</h3>
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-xs bg-transparent text-eve-text">
                            <thead>
                                <tr className="border-b border-eve-border">
                                    <th className="text-left font-semibold text-eve-muted p-2">Datum</th>
                                    <th className="text-right font-semibold text-eve-muted p-2">Liquid (Wallet)</th>
                                    <th className="text-right font-semibold text-eve-muted p-2">Assets (Gegenstände)</th>
                                    <th className="text-right font-semibold text-eve-muted p-2">Gesamtwert</th>
                                    <th className="text-right font-semibold text-eve-muted p-2">Veränderung</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5">
                                {processedData.historyList.slice().reverse().map((entry, index) => (
                                    <tr key={index} className="hover:bg-white/2">
                                        <td className="p-2 font-medium">
                                            {formatDateGerman(entry.date)} {entry.date === todayStr ? ' (Heute)' : ''}
                                        </td>
                                        <td className="p-2 text-right font-mono">{formatIsk(entry.wallet)}</td>
                                        <td className="p-2 text-right font-mono">{formatIsk(entry.assets)}</td>
                                        <td className="p-2 text-right font-bold font-mono">{formatIsk(entry.total)}</td>
                                        <td style={{ 
                                            textAlign: 'right', 
                                            fontWeight: 'bold', 
                                            color: !entry.hasPrev ? 'var(--theme-text-muted)' : entry.change > 0 ? '#00ffaa' : entry.change < 0 ? '#f14668' : 'var(--theme-text)'
                                        }} className="p-2 font-mono">
                                            {!entry.hasPrev ? (
                                                <span className="text-eve-muted">Startwert</span>
                                            ) : (
                                                <span>
                                                    {entry.change > 0 ? '+' : ''}
                                                    {formatIsk(entry.change)}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    );
}
