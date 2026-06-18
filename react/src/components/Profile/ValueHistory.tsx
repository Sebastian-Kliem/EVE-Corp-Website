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
    const [activeTab, setActiveTab] = useState<'daily' | 'monthly'>('daily');
    const [hoveredPoint, setHoveredPoint] = useState<{ x: number; y: number; date: string; total: number } | null>(null);

    // Calculate dynamic Omega goal based on manually set Omega accounts
    const omegaGoal = useMemo(() => {
        const count = omegaAccountCount > 0 ? omegaAccountCount : 1;
        return count * OMEGA_COST_ISK;
    }, [omegaAccountCount]);

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

        // Average daily profit (based on changes)
        const validChanges = historyList.filter(h => h.hasPrev).map(h => h.change);
        const totalProfit = validChanges.reduce((a, b) => a + b, 0);
        const avgDailyProfit = validChanges.length > 0 ? totalProfit / validChanges.length : 0;

        // 3. Process monthly aggregation
        const monthlyTotals: Record<string, { monthKey: string; name: string; change: number }> = {};
        historyList.forEach(entry => {
            if (!entry.hasPrev) return;
            const [year, month] = entry.date.split('-');
            const monthKey = `${year}-${month}`;
            if (!monthlyTotals[monthKey]) {
                const monthNames = [
                    'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'
                ];
                const monthNum = parseInt(month, 10);
                const name = `${monthNames[monthNum - 1]} ${year}`;
                monthlyTotals[monthKey] = { monthKey, name, change: 0 };
            }
            monthlyTotals[monthKey].change += entry.change;
        });

        const monthlyList = Object.values(monthlyTotals).sort((a, b) => b.monthKey.localeCompare(a.monthKey));

        return {
            historyList,
            currentWallet,
            currentAssets,
            currentTotal,
            todayEarned,
            avgDailyProfit,
            monthlyList
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
        <div className="section p-0">
            <div className="level">
                <div className="level-left">
                    <h1 className="title is-3" style={{ color: 'var(--theme-primary)', textShadow: '0 0 10px rgba(0, 240, 255, 0.3)' }}>
                        📈 Vermögensverlauf
                    </h1>
                </div>
                <div className="level-right">
                    <div className="field is-horizontal align-items-center" style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                        <span className="has-text-grey-light is-size-7">Charakter filtern:</span>
                        <div className="select is-small">
                            <select
                                value={selectedCharacterId}
                                onChange={(e) => {
                                    const val = e.target.value;
                                    setSelectedCharacterId(val === 'all' ? 'all' : parseInt(val, 10));
                                }}
                                style={{
                                    backgroundColor: 'var(--theme-card-bg)',
                                    color: 'var(--theme-text)',
                                    borderColor: 'var(--theme-card-border)'
                                }}
                            >
                                <option value="all">Alle Charaktere (Kombiniert)</option>
                                {characters.map(char => (
                                    <option key={char.id} value={char.id}>{char.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {/* Statistics Cards */}
            <div className="columns mb-4">
                <div className="column">
                    <div className="box" style={{ position: 'relative', overflow: 'hidden' }}>
                        <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: '3px', background: 'var(--theme-primary)' }}></div>
                        <p className="subtitle is-7 mb-2 uppercase" style={{ tracking: '1px', fontSize: '0.75rem' }}>Gesamtvermögen</p>
                        <p className="title is-4 mb-1" style={{ color: '#fff' }}>
                            {formatIskShort(processedData.currentTotal)}
                        </p>
                        <div className="is-size-7 has-text-grey-light" style={{ display: 'flex', justifyContent: 'space-between' }}>
                            <span>Liquid: {formatIskShort(processedData.currentWallet)}</span>
                            <span>Assets: {formatIskShort(processedData.currentAssets)}</span>
                        </div>
                    </div>
                </div>
                
                <div className="column">
                    <div className="box" style={{ position: 'relative', overflow: 'hidden' }}>
                        <div style={{ 
                            position: 'absolute', top: 0, left: 0, right: 0, height: '3px', 
                            background: processedData.todayEarned > 0 ? '#00ffaa' : processedData.todayEarned < 0 ? '#f14668' : 'var(--theme-text-muted)' 
                        }}></div>
                        <p className="subtitle is-7 mb-2 uppercase" style={{ tracking: '1px', fontSize: '0.75rem' }}>Gewinn heute (Bisher)</p>
                        <p className={`title is-4 mb-1 ${processedData.todayEarned > 0 ? 'has-text-success' : processedData.todayEarned < 0 ? 'has-text-danger' : ''}`}
                           style={{ color: processedData.todayEarned > 0 ? '#00ffaa' : processedData.todayEarned < 0 ? '#f14668' : undefined }}>
                            {processedData.todayEarned > 0 ? '+' : ''}{formatIsk(processedData.todayEarned)}
                        </p>
                        <p className="is-size-7 has-text-grey-light">Vergleich mit dem Stand von gestern</p>
                    </div>
                </div>

                <div className="column">
                    <div className="box" style={{ position: 'relative', overflow: 'hidden' }}>
                        <div style={{ 
                            position: 'absolute', top: 0, left: 0, right: 0, height: '3px', 
                            background: processedData.avgDailyProfit > 0 ? '#00ffaa' : processedData.avgDailyProfit < 0 ? '#f14668' : 'var(--theme-text-muted)' 
                        }}></div>
                        <p className="subtitle is-7 mb-2 uppercase" style={{ tracking: '1px', fontSize: '0.75rem' }}>Durchschnittsverdienst / Tag</p>
                        <p className={`title is-4 mb-1 ${processedData.avgDailyProfit > 0 ? 'has-text-success' : processedData.avgDailyProfit < 0 ? 'has-text-danger' : ''}`}
                           style={{ color: processedData.avgDailyProfit > 0 ? '#00ffaa' : processedData.avgDailyProfit < 0 ? '#f14668' : undefined }}>
                            {processedData.avgDailyProfit > 0 ? '+' : ''}{formatIsk(processedData.avgDailyProfit)}
                        </p>
                        <p className="is-size-7 has-text-grey-light">
                            Berechnet aus {processedData.historyList.filter(h => h.hasPrev).length} Tagen
                        </p>
                    </div>
                </div>
            </div>

            {/* Navigation Tabs */}
            <div className="tabs mb-4" style={{ borderBottom: '1px solid var(--theme-card-border)', display: 'flex', gap: '20px' }}>
                <button 
                    onClick={() => setActiveTab('daily')} 
                    className="p-2"
                    style={{
                        background: 'none', border: 'none', 
                        color: activeTab === 'daily' ? 'var(--theme-primary)' : 'var(--theme-text-muted)',
                        borderBottom: activeTab === 'daily' ? '2px solid var(--theme-primary)' : 'none',
                        cursor: 'pointer', fontWeight: 600, fontSize: '1rem',
                        transition: 'all 0.2s ease'
                    }}
                >
                    📅 Täglicher Verlauf
                </button>
                <button 
                    onClick={() => setActiveTab('monthly')} 
                    className="p-2"
                    style={{
                        background: 'none', border: 'none', 
                        color: activeTab === 'monthly' ? 'var(--theme-primary)' : 'var(--theme-text-muted)',
                        borderBottom: activeTab === 'monthly' ? '2px solid var(--theme-primary)' : 'none',
                        cursor: 'pointer', fontWeight: 600, fontSize: '1rem',
                        transition: 'all 0.2s ease'
                    }}
                >
                    📊 Monatsübersicht & Omega-Ziel
                </button>
            </div>

            {/* TAB: DAILY HISTORY */}
            {activeTab === 'daily' && (
                <div>
                    {/* SVG Area Chart */}
                    <div className="box mb-4" style={{ background: 'rgba(10, 15, 28, 0.8)', padding: '1.5rem', overflowX: 'auto' }}>
                        <h3 className="title is-6 has-text-grey-light mb-3">Nettovermögen Entwicklung</h3>
                        {chartSvg ? (
                            <div style={{ position: 'relative', width: '100%', minWidth: '850px' }}>
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
                            <p className="has-text-grey-light is-size-7 has-text-centered py-5">
                                Mindestens 2 Tage mit Daten sind erforderlich, um das Diagramm zu zeichnen.
                            </p>
                        )}
                    </div>

                    {/* Daily History Table */}
                    <div className="box">
                        <h3 className="title is-6 has-text-grey-light mb-3">Tägliche Aufzeichnungen</h3>
                        <div style={{ overflowX: 'auto' }}>
                            <table className="table is-fullwidth" style={{ backgroundColor: 'transparent', color: 'var(--theme-text)' }}>
                                <thead>
                                    <tr style={{ borderBottom: '2px solid var(--theme-card-border)' }}>
                                        <th style={{ color: 'var(--theme-text-muted)' }}>Datum</th>
                                        <th style={{ color: 'var(--theme-text-muted)', textAlign: 'right' }}>Liquid (Wallet)</th>
                                        <th style={{ color: 'var(--theme-text-muted)', textAlign: 'right' }}>Assets (Gegenstände)</th>
                                        <th style={{ color: 'var(--theme-text-muted)', textAlign: 'right' }}>Gesamtwert</th>
                                        <th style={{ color: 'var(--theme-text-muted)', textAlign: 'right' }}>Veränderung</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {processedData.historyList.slice().reverse().map((entry, index) => (
                                        <tr key={index} style={{ borderBottom: '1px solid rgba(0, 240, 255, 0.05)' }}>
                                            <td style={{ fontWeight: 500 }}>
                                                {formatDateGerman(entry.date)} {entry.date === todayStr ? ' (Heute)' : ''}
                                            </td>
                                            <td style={{ textAlign: 'right' }}>{formatIsk(entry.wallet)}</td>
                                            <td style={{ textAlign: 'right' }}>{formatIsk(entry.assets)}</td>
                                            <td style={{ textAlign: 'right', fontWeight: 'bold' }}>{formatIsk(entry.total)}</td>
                                            <td style={{ 
                                                textAlign: 'right', 
                                                fontWeight: 'bold', 
                                                color: !entry.hasPrev ? 'var(--theme-text-muted)' : entry.change > 0 ? '#00ffaa' : entry.change < 0 ? '#f14668' : 'var(--theme-text)'
                                            }}>
                                                {!entry.hasPrev ? (
                                                    <span className="has-text-grey-light">Startwert</span>
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
            )}

            {/* TAB: MONTHLY HISTORY & OMEGA TARGET */}
            {activeTab === 'monthly' && (
                <div>
                    <div className="columns">
                        {/* Month Progress & Target */}
                        <div className="column is-one-third">
                            <div className="box h-100" style={{ height: '100%' }}>
                                <h3 className="title is-6 mb-3" style={{ color: 'var(--theme-primary)' }}>🤖 Omega-Ziel Tracker</h3>
                                <p className="is-size-7 has-text-grey-light mb-4">
                                    Eine Omega-Monatslizenz kostet auf dem Markt ca. <strong>2,5 Mrd. ISK</strong> (PLEX). 
                                    {omegaAccountCount > 0 ? (
                                        <span> Du hast aktuell <strong>{omegaAccountCount} Omega-Accounts</strong> hinterlegt. Dein monatliches Gesamtziel beträgt daher <strong>{formatIskShort(omegaGoal)}</strong>.</span>
                                    ) : (
                                        <span> Hier siehst du, ob dein Verdienst des aktuellen Monats bereits für einen Account ausreicht.</span>
                                    )}
                                </p>
                                
                                {processedData.monthlyList.length > 0 ? (
                                    (() => {
                                        const currentMonth = processedData.monthlyList[0];
                                        const percent = Math.min(100, Math.max(0, (currentMonth.change / omegaGoal) * 100));
                                        const isNegative = currentMonth.change < 0;

                                        return (
                                            <div>
                                                <div className="level mb-2">
                                                    <div className="level-left">
                                                        <span className="is-size-6 font-weight-bold">{currentMonth.name}</span>
                                                    </div>
                                                    <div className="level-right">
                                                        <span className={`font-weight-bold ${isNegative ? 'has-text-danger' : percent >= 100 ? 'has-text-success' : 'has-text-info'}`}>
                                                            {isNegative ? '0%' : `${percent.toFixed(1)}%`}
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                                {/* Progress Bar Container */}
                                                <div style={{
                                                    width: '100%', 
                                                    height: '14px', 
                                                    backgroundColor: 'rgba(0, 0, 0, 0.4)', 
                                                    borderRadius: '7px', 
                                                    overflow: 'hidden',
                                                    border: '1px solid var(--theme-card-border)',
                                                    marginBottom: '15px'
                                                }}>
                                                    <div style={{
                                                        width: `${isNegative ? 0 : percent}%`,
                                                        height: '100%',
                                                        background: percent >= 100 
                                                            ? 'linear-gradient(90deg, #00b37a 0%, #00ffaa 100%)' 
                                                            : 'linear-gradient(90deg, #0284c7 0%, var(--theme-primary) 100%)',
                                                        borderRadius: '7px',
                                                        transition: 'width 0.5s ease-out',
                                                        boxShadow: '0 0 8px var(--theme-primary)'
                                                    }}></div>
                                                </div>

                                                <div className="is-size-7 has-text-grey-light mb-2">
                                                    Verdienst diesen Monat: <span style={{ color: isNegative ? '#f14668' : '#fff', fontWeight: 'bold' }}>
                                                        {currentMonth.change > 0 ? '+' : ''}{formatIsk(currentMonth.change)}
                                                    </span>
                                                </div>

                                                {percent >= 100 ? (
                                                    <div className="p-3 mt-3 has-text-centered" style={{ border: '1px solid rgba(0, 255, 170, 0.2)', backgroundColor: 'rgba(0, 255, 170, 0.05)', borderRadius: '4px' }}>
                                                        <span style={{ fontSize: '1.2rem' }}>🎉</span> <strong style={{ color: '#00ffaa' }}>Omega gesichert!</strong>
                                                        <p className="is-size-7 has-text-grey-light mt-1">Du hast diesen Monat genug verdient, um dein Omega-Abonnement mit ISK zu finanzieren!</p>
                                                    </div>
                                                ) : isNegative ? (
                                                    <div className="p-3 mt-3 has-text-centered" style={{ border: '1px solid rgba(241, 70, 104, 0.2)', backgroundColor: 'rgba(241, 70, 104, 0.05)', borderRadius: '4px' }}>
                                                        <span style={{ fontSize: '1.2rem' }}>⚠️</span> <strong style={{ color: '#f14668' }}>Verlustmonat</strong>
                                                        <p className="is-size-7 has-text-grey-light mt-1">Diesen Monat bist du im Minus. Konzentriere dich auf Einkommen, um den Verlust auszugleichen.</p>
                                                    </div>
                                                ) : (
                                                    <div className="p-3 mt-3 has-text-centered" style={{ border: '1px solid rgba(0, 240, 255, 0.1)', backgroundColor: 'rgba(0, 240, 255, 0.02)', borderRadius: '4px' }}>
                                                        <span>⏳</span> <strong>Noch {(omegaGoal - currentMonth.change).toLocaleString('de-DE', { maximumFractionDigits: 0 })} ISK benötigt</strong>
                                                        <p className="is-size-7 has-text-grey-light mt-1">Weiter so! Du bist auf dem besten Weg zu deiner ISK-finanzierten Monatslizenz.</p>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })()
                                ) : (
                                    <p className="has-text-grey-light is-size-7">Noch keine monatlichen Daten erfasst.</p>
                                )}
                            </div>
                        </div>

                        {/* Monthly Profits List */}
                        <div className="column is-two-thirds">
                            <div className="box h-100" style={{ height: '100%' }}>
                                <h3 className="title is-6 has-text-grey-light mb-3">Historische Monatsübersicht</h3>
                                {processedData.monthlyList.length > 0 ? (
                                    <div style={{ overflowX: 'auto' }}>
                                        <table className="table is-fullwidth" style={{ backgroundColor: 'transparent', color: 'var(--theme-text)' }}>
                                            <thead>
                                                <tr style={{ borderBottom: '2px solid var(--theme-card-border)' }}>
                                                    <th style={{ color: 'var(--theme-text-muted)' }}>Monat</th>
                                                    <th style={{ color: 'var(--theme-text-muted)', textAlign: 'right' }}>Gewinn / Verlust</th>
                                                    <th style={{ color: 'var(--theme-text-muted)', textAlign: 'right' }}>Omega-Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {processedData.monthlyList.map((month, index) => {
                                                    const omegaPercent = (month.change / omegaGoal) * 100;
                                                    return (
                                                        <tr key={index} style={{ borderBottom: '1px solid rgba(0, 240, 255, 0.05)' }}>
                                                            <td style={{ fontWeight: 500 }}>{month.name}</td>
                                                            <td style={{ 
                                                                textAlign: 'right', 
                                                                fontWeight: 'bold', 
                                                                color: month.change > 0 ? '#00ffaa' : month.change < 0 ? '#f14668' : 'var(--theme-text)' 
                                                            }}>
                                                                {month.change > 0 ? '+' : ''}{formatIsk(month.change)}
                                                            </td>
                                                            <td style={{ textAlign: 'right', fontWeight: 'bold' }}>
                                                                {month.change >= omegaGoal ? (
                                                                    <span className="tag is-success" style={{ backgroundColor: 'rgba(0, 255, 170, 0.15)', color: '#00ffaa', border: '1px solid rgba(0, 255, 170, 0.3)', padding: '2px 8px', borderRadius: '4px', fontSize: '0.75rem' }}>
                                                                        ✔️ Omega gedeckt
                                                                    </span>
                                                                ) : month.change <= 0 ? (
                                                                    <span className="tag is-danger" style={{ backgroundColor: 'rgba(241, 70, 104, 0.15)', color: '#f14668', border: '1px solid rgba(241, 70, 104, 0.3)', padding: '2px 8px', borderRadius: '4px', fontSize: '0.75rem' }}>
                                                                        ❌ Verlust
                                                                    </span>
                                                                ) : (
                                                                    <span className="tag is-info" style={{ backgroundColor: 'rgba(0, 240, 255, 0.15)', color: 'var(--theme-primary)', border: '1px solid var(--theme-card-border)', padding: '2px 8px', borderRadius: '4px', fontSize: '0.75rem' }}>
                                                                        ⏳ {omegaPercent.toFixed(0)}% gedeckt
                                                                    </span>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <p className="has-text-grey-light is-size-7 has-text-centered py-5">
                                        Noch keine monatlichen Daten in der Aufzeichnung vorhanden.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
