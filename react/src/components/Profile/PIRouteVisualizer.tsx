import React, { useEffect, useRef, useState } from 'react';
import cytoscape from 'cytoscape';

interface PIRouteVisualizerProps {
    pins: any[];
    routes: any[];
    getTypeIconUrl: (typeId: number) => string;
}

const getCleanName = (name: string): string => {
    const prefixes = ['barren', 'temperate', 'lava', 'ice', 'gas', 'oceanic', 'plasma', 'storm', 'shattered'];
    let clean = name;
    for (const prefix of prefixes) {
        if (clean.toLowerCase().startsWith(prefix)) {
            clean = clean.slice(prefix.length).trim();
            clean = clean.charAt(0).toUpperCase() + clean.slice(1);
            break;
        }
    }
    return clean;
};

export default function PIRouteVisualizer({ pins, routes, getTypeIconUrl }: PIRouteVisualizerProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const cyRef = useRef<cytoscape.Core | null>(null);

    const [tooltip, setTooltip] = useState<{
        visible: boolean;
        x: number;
        y: number;
        title: string;
        category: string;
        details: React.ReactNode;
    }>({
        visible: false,
        x: 0,
        y: 0,
        title: '',
        category: '',
        details: null
    });

    const renderTooltipDetails = (pin: any) => {
        // Find destination for extractor
        let extractorDest = 'Kein Ziel';
        if (pin.category === 'extractor' && pin.extractor_info) {
            const route = routes.find(r => 
                r.source_pin_id.toString() === pin.pin_id.toString() && 
                r.content_type_id === pin.extractor_info.product_type_id
            );
            if (route) {
                const dstPin = pins.find(p => p.pin_id.toString() === route.destination_pin_id.toString());
                if (dstPin) extractorDest = getCleanName(dstPin.name);
            }
        }

        const cycleTimeHours = pin.factory_info ? pin.factory_info.cycle_time / 3600 : 1;

        return (
            <div>
                {/* 1. Extraction info */}
                {pin.category === 'extractor' && pin.extractor_info && (
                    <div style={{ marginBottom: '8px' }}>
                        <div style={{ color: '#00d8ff', fontWeight: 600, marginBottom: '2px' }}>⛏️ Extraktion:</div>
                        <div style={{ paddingLeft: '8px' }}>
                            <div>Rate: {Math.round(pin.extractor_info.qty_per_cycle / (pin.extractor_info.cycle_time / 3600)).toLocaleString()} / Std.</div>
                            <div style={{ fontSize: '0.75rem', color: '#8892b0' }}>Ziel: {extractorDest}</div>
                            <div style={{ fontSize: '0.75rem', color: '#8892b0' }}>Zyklus: {Math.round(pin.extractor_info.cycle_time / 60)} Min.</div>
                            {pin.expiry_time && (
                                <div style={{ fontSize: '0.75rem', color: '#ff7c00', marginTop: '2px' }}>
                                    Ablaufzeit: {new Date(pin.expiry_time).toLocaleDateString()} {new Date(pin.expiry_time).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* 2. Factory inputs (Verbrauch) */}
                {pin.category === 'factory' && pin.factory_info && pin.factory_info.inputs && pin.factory_info.inputs.length > 0 && (
                    <div style={{ marginBottom: '8px' }}>
                        <div style={{ color: '#e06c75', fontWeight: 600, marginBottom: '2px' }}>📉 Verbrauch (Inputs):</div>
                        <ul style={{ margin: 0, paddingLeft: '14px', listStyleType: 'disc' }}>
                            {pin.factory_info.inputs.map((inp: any, idx: number) => {
                                const hourlyRate = Math.round(inp.quantity / cycleTimeHours);
                                
                                // Find source for this input
                                let sourceName = 'Unbekannt';
                                const route = routes.find(r => 
                                    r.destination_pin_id.toString() === pin.pin_id.toString() && 
                                    r.content_type_id === inp.type_id
                                );
                                if (route) {
                                    const srcPin = pins.find(p => p.pin_id.toString() === route.source_pin_id.toString());
                                    if (srcPin) sourceName = getCleanName(srcPin.name);
                                }

                                return (
                                    <li key={idx}>
                                        {inp.name}: {hourlyRate.toLocaleString()} / Std.
                                        <div style={{ fontSize: '0.7rem', color: '#8892b0' }}>Quelle: {sourceName}</div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                {/* 3. Factory outputs (Produktion) */}
                {pin.category === 'factory' && pin.factory_info && pin.factory_info.outputs && pin.factory_info.outputs.length > 0 && (
                    <div style={{ marginBottom: '8px' }}>
                        <div style={{ color: '#98c379', fontWeight: 600, marginBottom: '2px' }}>📈 Produktion:</div>
                        <ul style={{ margin: 0, paddingLeft: '14px', listStyleType: 'disc' }}>
                            {pin.factory_info.outputs.map((out: any, idx: number) => {
                                const hourlyRate = Math.round(out.quantity / cycleTimeHours);
                                
                                // Find destination for this output
                                let destName = 'Kein Ziel';
                                const route = routes.find(r => 
                                    r.source_pin_id.toString() === pin.pin_id.toString() && 
                                    r.content_type_id === out.type_id
                                );
                                if (route) {
                                    const dstPin = pins.find(p => p.pin_id.toString() === route.destination_pin_id.toString());
                                    if (dstPin) destName = getCleanName(dstPin.name);
                                }

                                return (
                                    <li key={idx}>
                                        {out.name}: {hourlyRate.toLocaleString()} / Std.
                                        <div style={{ fontSize: '0.7rem', color: '#8892b0' }}>Ziel: {destName}</div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                {/* 4. Contents / Storage */}
                {pin.contents && pin.contents.length > 0 ? (
                    <div>
                        <div style={{ color: '#98c379', fontWeight: 600, marginBottom: '2px' }}>📦 Inhalt:</div>
                        <ul style={{ margin: 0, paddingLeft: '14px', listStyleType: 'disc' }}>
                            {pin.contents.map((item: any, idx: number) => (
                                <li key={idx}>
                                    {item.name}: {item.quantity.toLocaleString()}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : (
                    // Only show empty text if it's not a factory/extractor
                    pin.category !== 'factory' && pin.category !== 'extractor' && (
                        <div style={{ color: '#8892b0', fontStyle: 'italic' }}>Kein Inhalt gelagert</div>
                    )
                )}
            </div>
        );
    };

    useEffect(() => {
        if (!containerRef.current) return;

        // Filter out Command Centers as they are far away and unrelated to production flow
        const filteredPins = pins.filter(pin => pin.category !== 'command_center');

        if (filteredPins.length === 0) return;

        // Find min/max coordinate bounds
        let minLat = Infinity, maxLat = -Infinity;
        let minLng = Infinity, maxLng = -Infinity;

        filteredPins.forEach(pin => {
            const lat = pin.latitude ?? 0;
            const lng = pin.longitude ?? 0;
            if (lat < minLat) minLat = lat;
            if (lat > maxLat) maxLat = lat;
            if (lng < minLng) minLng = lng;
            if (lng > maxLng) maxLng = lng;
        });

        const latSpan = maxLat - minLat;
        const lngSpan = maxLng - minLng;
        const centerLat = minLat + latSpan / 2;
        const centerLng = minLng + lngSpan / 2;

        // Choose scale factor based on the span
        // Target a span of 300px inside the container.
        const maxSpan = Math.max(latSpan, lngSpan);
        const scale = maxSpan > 0 ? 300 / maxSpan : 1.0;

        // 1. Build nodes using scaled relative positions
        const validPinIds = new Set<string>();
        const nodes = filteredPins.map(pin => {
            const lat = pin.latitude ?? 0;
            const lng = pin.longitude ?? 0;
            
            const pinIdStr = pin.pin_id.toString();
            validPinIds.add(pinIdStr);

            // Center and scale coordinates
            const x = (lng - centerLng) * scale;
            const y = -(lat - centerLat) * scale; // Negative because latitude goes up, screen coordinates go down

            return {
                data: {
                    id: pinIdStr,
                    label: getCleanName(pin.name),
                    category: pin.category,
                    factoryType: pin.category === 'factory'
                        ? (pin.name.toLowerCase().includes('advanced') ? 'advanced' : 'basic')
                        : 'none',
                    icon: getTypeIconUrl(pin.type_id)
                },
                position: { x, y }
            };
        });

        // Initialize Cytoscape.js with nodes only (no link lines needed as tooltip maps dependencies)
        const cy = cytoscape({
            container: containerRef.current,
            elements: nodes,
            style: [
                {
                    selector: 'node',
                    style: {
                        'width': '38px',
                        'height': '38px',
                        'shape': 'ellipse',
                        'background-image': 'data(icon)',
                        'background-fit': 'contain',
                        'background-clip': 'none',
                        'background-color': '#11151c', // Dark background like in EVE Client
                        'background-opacity': 1,
                        'border-width': '2px',
                        'border-color': '#4f5b66',
                        'label': '', // Hidden by default, shown on hover
                        'color': '#abb2bf',
                        'font-size': '10px',
                        'text-valign': 'bottom',
                        'text-halign': 'center',
                        'text-margin-y': '6px',
                        'text-wrap': 'wrap',
                        'text-max-width': '90px'
                    }
                },
                {
                    selector: 'node.hovered',
                    style: {
                        'label': 'data(label)' // Show label on hover
                    }
                },
                {
                    selector: 'node[category="extractor"]',
                    style: {
                        'border-color': '#00d8ff',      // Cyan
                        'background-color': '#091c24'
                    }
                },
                {
                    selector: 'node[category="launchpad"]',
                    style: {
                        'border-color': '#0072ff',      // Blue
                        'background-color': '#07162b',
                        'width': '42px',
                        'height': '42px'
                    }
                },
                {
                    selector: 'node[category="storage"]',
                    style: {
                        'border-color': '#9da7b3',      // Grey/Silver
                        'background-color': '#181b1f',
                        'width': '40px',
                        'height': '40px'
                    }
                },
                {
                    selector: 'node[factoryType="advanced"]',
                    style: {
                        'border-color': '#8cff00',      // Yellow-Green
                        'background-color': '#142907'
                    }
                },
                {
                    selector: 'node[factoryType="basic"]',
                    style: {
                        'border-color': '#ff7c00',      // Orange
                        'background-color': '#291807'
                    }
                }
            ],
            layout: {
                name: 'preset', // Use coordinates supplied in node.position
                fit: true,
                padding: 40
            },
            userZoomingEnabled: true,
            userPanningEnabled: true,
            boxSelectionEnabled: false,
            autoungrabify: true // Disable node dragging (fixed positions)
        });

        cyRef.current = cy;

        // Setup tooltip events on mouse over
        cy.on('mouseover', 'node', (e) => {
            const node = e.target;
            const pinId = node.id();
            const pin = pins.find(p => p.pin_id.toString() === pinId);
            if (!pin) return;

            node.addClass('hovered');

            const renderedDetails = renderTooltipDetails(pin);
            const pos = node.renderedPosition();

            setTooltip({
                visible: true,
                x: pos.x,
                y: pos.y,
                title: getCleanName(pin.name),
                category: pin.category === 'extractor' ? 'Extractor' : (pin.category === 'factory' ? (pin.name.toLowerCase().includes('advanced') ? 'Adv Factory' : 'Basic Factory') : (pin.category === 'launchpad' ? 'Launchpad' : 'Storage')),
                details: renderedDetails
            });
        });

        cy.on('mousemove', 'node', (e) => {
            const node = e.target;
            const pos = node.renderedPosition();
            setTooltip(prev => ({
                ...prev,
                x: pos.x,
                y: pos.y
            }));
        });

        cy.on('mouseout', 'node', (e) => {
            e.target.removeClass('hovered');
            setTooltip(prev => ({
                ...prev,
                visible: false
            }));
        });

        // Auto-fit on window resize
        const handleResize = () => {
            if (cyRef.current) {
                cyRef.current.resize();
                cyRef.current.fit(undefined, 40);
            }
        };
        window.addEventListener('resize', handleResize);

        return () => {
            window.removeEventListener('resize', handleResize);
            if (cyRef.current) {
                cyRef.current.destroy();
                cyRef.current = null;
            }
        };
    }, [pins, routes, getTypeIconUrl]);

    return (
        <div style={{ marginTop: '1rem', marginBottom: '1.25rem', position: 'relative' }}>
            <div style={{
                fontSize: '0.8rem',
                color: 'var(--theme-text-muted)',
                marginBottom: '0.5rem',
                display: 'flex',
                alignItems: 'center',
                gap: '4px'
            }}>
                <span>📐 Planetenschema (Mausrad zum Zoomen, Ziehen zum Verschieben, Hover für Details)</span>
            </div>
            <div 
                ref={containerRef} 
                style={{ 
                    width: '100%', 
                    height: '320px', 
                    backgroundColor: 'rgba(0, 0, 0, 0.18)', 
                    borderRadius: '6px', 
                    border: '1px solid rgba(255, 255, 255, 0.08)',
                    overflow: 'hidden'
                }} 
            />
            {tooltip.visible && (
                <div style={{
                    position: 'absolute',
                    left: `${tooltip.x}px`,
                    top: `${tooltip.y}px`,
                    transform: 'translate(18px, -50%)', // Align to right of the cursor point, centered vertically
                    backgroundColor: '#1b2028',
                    border: '1px solid rgba(255, 255, 255, 0.12)',
                    borderRadius: '6px',
                    padding: '10px',
                    color: '#e2e8f0',
                    fontSize: '0.75rem',
                    pointerEvents: 'none', // Ensure cursor moves through it
                    zIndex: 1000,
                    boxShadow: '0 4px 12px rgba(0,0,0,0.5)',
                    minWidth: '220px',
                    maxWidth: '300px'
                }}>
                    <div style={{ 
                        fontWeight: 'bold', 
                        borderBottom: '1px solid rgba(255,255,255,0.08)', 
                        paddingBottom: '4px', 
                        marginBottom: '6px', 
                        display: 'flex', 
                        justifyContent: 'space-between', 
                        alignItems: 'center' 
                    }}>
                        <span>{tooltip.title}</span>
                        <span style={{ fontSize: '0.65rem', color: '#8892b0', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                            {tooltip.category}
                        </span>
                    </div>
                    {tooltip.details}
                </div>
            )}
        </div>
    );
}
