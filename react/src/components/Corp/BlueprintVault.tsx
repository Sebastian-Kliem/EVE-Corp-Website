import React, { useState, useMemo } from 'react';

interface ActiveJob {
    activityId: number; // 3 = TE, 4 = ME, 5 = Copying
    endDate: string; // ISO string
    runs: number;
    status: string;
}

interface BlueprintData {
    itemId: string;
    typeId: number;
    name: string;
    category: string;
    ownerCharacterName: string;
    ownerUserName: string;
    locationName: string;
    systemName: string;
    isBpo: boolean;
    me: number;
    te: number;
    runs: number;
    quantity: number;
    productId: number;
    activeJob: ActiveJob | null;
}

interface BlueprintVaultProps {
    blueprints: BlueprintData[];
    imagePaths: {
        types: string;
    };
}

export default function BlueprintVault({ blueprints, imagePaths }: BlueprintVaultProps) {
    const [searchQuery, setSearchQuery] = useState('');
    const [filterType, setFilterType] = useState<'all' | 'bpo' | 'bpc' | 'job'>('all');
    const [selectedCategory, setSelectedCategory] = useState<string>('all');
    const [hoveredItemId, setHoveredItemId] = useState<string | null>(null);

    // Collect all unique categories
    const categories = useMemo(() => {
        const cats = new Set<string>();
        blueprints.forEach(bp => {
            if (bp.category) {
                cats.add(bp.category);
            }
        });
        return Array.from(cats).sort();
    }, [blueprints]);

    const filteredBlueprints = useMemo(() => {
        return blueprints.filter((bp) => {
            // Search filter
            const query = searchQuery.toLowerCase().trim();
            const matchesSearch =
                query === '' ||
                bp.name.toLowerCase().includes(query) ||
                bp.ownerCharacterName.toLowerCase().includes(query) ||
                bp.locationName.toLowerCase().includes(query) ||
                bp.systemName.toLowerCase().includes(query);

            if (!matchesSearch) return false;

            // Tab filter
            if (filterType === 'bpo' && !bp.isBpo) return false;
            if (filterType === 'bpc' && bp.isBpo) return false;
            if (filterType === 'job' && bp.activeJob === null) return false;

            // Category filter
            if (selectedCategory !== 'all' && bp.category !== selectedCategory) return false;

            return true;
        });
    }, [blueprints, searchQuery, filterType, selectedCategory]);

    const getBlueprintIconUrl = (bp: BlueprintData) => {
        const action = bp.isBpo ? 'bp' : 'bpc';
        // Replace '12345' with the typeId and 'icon' with the blueprint action 'bp' or 'bpc'
        return imagePaths.types
            .replace('12345', bp.typeId.toString())
            .replace('/icon', '/' + action);
    };

    const getProductIconUrl = (bp: BlueprintData) => {
        return imagePaths.types.replace('12345', bp.productId.toString());
    };

    const formatJobDetails = (bp: BlueprintData, job: ActiveJob) => {
        const endDateStr = new Date(job.endDate).toLocaleString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });

        if (job.activityId === 4) {
            const nextMe = Math.min(10, bp.me + job.runs);
            return (
                <div className="tag is-warning is-light" style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', height: 'auto', padding: '6px 10px' }}>
                    <span style={{ fontWeight: 600 }}>🔬 Materialforschung</span>
                    <span style={{ fontSize: '0.75rem' }}>Fertig: {endDateStr}</span>
                    <span style={{ fontSize: '0.75rem', fontWeight: 600 }}>Ergebnis: ME {nextMe}%</span>
                </div>
            );
        }

        if (job.activityId === 3) {
            const nextTe = Math.min(20, bp.te + job.runs * 2);
            return (
                <div className="tag is-info is-light" style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', height: 'auto', padding: '6px 10px' }}>
                    <span style={{ fontWeight: 600 }}>⏳ Zeiteffizienzforschung</span>
                    <span style={{ fontSize: '0.75rem' }}>Fertig: {endDateStr}</span>
                    <span style={{ fontSize: '0.75rem', fontWeight: 600 }}>Ergebnis: TE {nextTe}%</span>
                </div>
            );
        }

        if (job.activityId === 5) {
            return (
                <div className="tag is-success is-light" style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', height: 'auto', padding: '6px 10px' }}>
                    <span style={{ fontWeight: 600 }}>🖨️ Kopieren</span>
                    <span style={{ fontSize: '0.75rem' }}>Fertig: {endDateStr}</span>
                    <span style={{ fontSize: '0.75rem', fontWeight: 600 }}>Ergebnis: {job.runs} Kopien (BPC)</span>
                </div>
            );
        }

        return <span className="tag is-light">In Arbeit</span>;
    };

    return (
        <div>
            {/* Search and Filters */}
            <div className="columns is-vcentered mb-4">
                <div className="column">
                    <p className="is-size-7 has-text-grey-light">Durchsuche alle von Corp-Mitgliedern geteilten Blueprints.</p>
                </div>
                <div className="column is-narrow">
                    <div className="field mb-0">
                        <div className="control select is-small">
                            <select
                                value={filterType}
                                onChange={(e) => setFilterType(e.target.value as any)}
                                style={{ background: '#101525', color: '#ccc', borderColor: '#444' }}
                            >
                                <option value="all">Alle Typen ({blueprints.length})</option>
                                <option value="bpo">Originale (BPOs) ({blueprints.filter(b => b.isBpo).length})</option>
                                <option value="bpc">Kopien (BPCs) ({blueprints.filter(b => !b.isBpo).length})</option>
                                <option value="job">In Forschung/Kopie ({blueprints.filter(b => b.activeJob !== null).length})</option>
                            </select>
                        </div>
                    </div>
                </div>
                {categories.length > 0 && (
                    <div className="column is-narrow">
                        <div className="field mb-0">
                            <div className="control select is-small">
                                <select
                                    value={selectedCategory}
                                    onChange={(e) => setSelectedCategory(e.target.value)}
                                    style={{ background: '#101525', color: '#ccc', borderColor: '#444' }}
                                >
                                    <option value="all">Alle Kategorien</option>
                                    {categories.map(cat => (
                                        <option key={cat} value={cat}>{cat}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>
                )}
                <div className="column is-narrow">
                    <div className="field mb-0">
                        <div className="control has-icons-left">
                            <input
                                className="input is-small assets-search-input"
                                type="text"
                                placeholder="Blueprints suchen..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                style={{ width: '250px' }}
                            />
                            <span className="icon is-small is-left">🔍</span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Blueprint Vault List */}
            {filteredBlueprints.length === 0 ? (
                <div className="notification is-dark has-text-centered py-6" style={{ background: '#13192b', border: '1px solid rgba(255,255,255,0.05)' }}>
                    <p className="has-text-grey-light">Keine passenden Blueprints im Tresor gefunden.</p>
                </div>
            ) : (
                <div style={{ overflowX: 'auto' }}>
                    <table className="table is-fullwidth is-striped is-hoverable assets-table" style={{ background: '#101525', color: '#eee', minWidth: '800px' }}>
                        <thead>
                            <tr style={{ background: 'rgba(255,255,255,0.02)' }}>
                                <th style={{ color: '#aaa', width: '50px' }}>Icon</th>
                                <th style={{ color: '#aaa' }}>Name</th>
                                <th style={{ color: '#aaa', width: '120px' }}>Typ</th>
                                <th style={{ color: '#aaa', width: '150px' }}>ME / TE</th>
                                <th style={{ color: '#aaa', width: '200px' }}>Eigentümer</th>
                                <th style={{ color: '#aaa' }}>Standort</th>
                                <th style={{ color: '#aaa', width: '250px' }}>Forschung / Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filteredBlueprints.map((bp) => {
                                const isHovered = hoveredItemId === bp.itemId;
                                return (
                                    <tr key={bp.itemId} style={{ borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                                        <td style={{ verticalAlign: 'middle' }}>
                                            <div 
                                                style={{ position: 'relative', width: '32px', height: '32px', cursor: 'help' }}
                                                onMouseEnter={() => setHoveredItemId(bp.itemId)}
                                                onMouseLeave={() => setHoveredItemId(null)}
                                                title="Fahre mit der Maus darüber, um das fertige Produkt zu sehen"
                                            >
                                                <img
                                                    src={getBlueprintIconUrl(bp)}
                                                    alt={bp.name}
                                                    style={{
                                                        position: 'absolute',
                                                        top: 0,
                                                        left: 0,
                                                        width: '32px',
                                                        height: '32px',
                                                        borderRadius: '4px',
                                                        transition: 'opacity 0.2s ease-in-out',
                                                        opacity: isHovered ? 0 : 1,
                                                        zIndex: isHovered ? 1 : 2,
                                                    }}
                                                    loading="lazy"
                                                />
                                                <img
                                                    src={getProductIconUrl(bp)}
                                                    alt={bp.name}
                                                    style={{
                                                        position: 'absolute',
                                                        top: 0,
                                                        left: 0,
                                                        width: '32px',
                                                        height: '32px',
                                                        borderRadius: '4px',
                                                        transition: 'opacity 0.2s ease-in-out',
                                                        opacity: isHovered ? 1 : 0,
                                                        zIndex: isHovered ? 2 : 1,
                                                    }}
                                                    loading="lazy"
                                                />
                                            </div>
                                        </td>
                                    <td style={{ verticalAlign: 'middle', fontWeight: 600 }}>
                                        {bp.name}
                                        {bp.quantity > 1 && (
                                            <span style={{ fontWeight: 'normal', color: 'var(--theme-text-muted)', marginLeft: '6px' }}>
                                                (x{bp.quantity})
                                            </span>
                                        )}
                                    </td>
                                    <td style={{ verticalAlign: 'middle' }}>
                                        {bp.isBpo ? (
                                            <span className="tag is-success is-light" style={{ fontWeight: 600 }}>Original (BPO)</span>
                                        ) : (
                                            <span className="tag is-info is-light" style={{ display: 'inline-flex', flexDirection: 'column', height: 'auto', padding: '4px 8px' }}>
                                                <span>Kopie (BPC)</span>
                                                <span style={{ fontSize: '0.7rem', fontWeight: 600 }}>{bp.runs} Runs übrig</span>
                                            </span>
                                        )}
                                    </td>
                                    <td style={{ verticalAlign: 'middle' }}>
                                        <div style={{ display: 'flex', gap: '6px' }}>
                                            <span className="tag is-dark" style={{ border: '1px solid rgba(255,255,255,0.1)', color: '#3273dc', fontWeight: 'bold' }}>
                                                ME: {bp.me}%
                                            </span>
                                            <span className="tag is-dark" style={{ border: '1px solid rgba(255,255,255,0.1)', color: '#00d1b2', fontWeight: 'bold' }}>
                                                TE: {bp.te}%
                                            </span>
                                        </div>
                                    </td>
                                    <td style={{ verticalAlign: 'middle' }}>
                                        <div>
                                            <span style={{ fontWeight: 600 }}>{bp.ownerCharacterName}</span>
                                            <div style={{ fontSize: '0.75rem', color: 'var(--theme-text-muted)' }}>User: {bp.ownerUserName}</div>
                                        </div>
                                    </td>
                                    <td style={{ verticalAlign: 'middle' }}>
                                        <div>
                                            <span style={{ fontWeight: 600, color: 'var(--theme-primary)' }}>{bp.systemName}</span>
                                            <div style={{ fontSize: '0.75rem', color: 'var(--theme-text-muted)' }}>{bp.locationName}</div>
                                        </div>
                                    </td>
                                    <td style={{ verticalAlign: 'middle' }}>
                                        {bp.activeJob ? formatJobDetails(bp, bp.activeJob) : (
                                            <span className="has-text-grey" style={{ fontSize: '0.85rem' }}>Bereit (Hangar)</span>
                                        )}
                                    </td>
                                </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
