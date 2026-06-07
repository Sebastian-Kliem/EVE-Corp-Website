import React, { useState, useEffect, useRef } from 'react';

interface Location {
    id: string;
    name: string;
    systemName: string;
}

interface HangarSetting {
    visible: boolean;
    restricted: boolean;
    users: string[];
}

interface CorpAssetsVisibilityManagerProps {
    locations: Record<string, Location>;
    flagsToMap: Record<string, number>;
    defaultDivisions: Record<number, string>;
    corpDivisions: Record<string, Record<number, string>>;
    initialVisibility: Record<string, Record<string, { visible: boolean; users: string[] }>>;
    allUsers: string[];
}

// Inline autocomplete component helper for each hangar
interface HangarUserSearchProps {
    allUsers: string[];
    selectedUsers: string[];
    onAddUser: (username: string) => void;
}

function HangarUserSearch({ allUsers, selectedUsers, onAddUser }: HangarUserSearchProps) {
    const [query, setQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!isOpen) {
            setSuggestions([]);
            return;
        }

        const trimmed = query.trim().toLowerCase();
        // Filter users that are not already selected
        const available = allUsers.filter(u => !selectedUsers.includes(u));

        if (trimmed === '') {
            setSuggestions(available.slice(0, 10)); // Show top 10 if empty query
        } else {
            const filtered = available.filter(u => u.toLowerCase().includes(trimmed));
            setSuggestions(filtered.slice(0, 10));
        }
    }, [query, isOpen, allUsers, selectedUsers]);

    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    return (
        <div ref={containerRef} style={{ position: 'relative', marginTop: '0.5rem', width: '100%', maxWidth: '300px' }}>
            <input
                type="text"
                className="input"
                style={{ fontSize: '0.85rem', padding: '0.4rem 0.6rem', height: 'auto' }}
                placeholder="Mitglied suchen..."
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setIsOpen(true);
                }}
                onFocus={() => setIsOpen(true)}
            />
            {isOpen && suggestions.length > 0 && (
                <div style={{
                    position: 'absolute',
                    width: '100%',
                    maxHeight: '150px',
                    overflowY: 'auto',
                    zIndex: 1000,
                    background: 'rgba(13, 18, 31, 0.98)',
                    border: '1px solid var(--theme-card-border, rgba(0, 240, 255, 0.15))',
                    borderRadius: '6px',
                    boxShadow: 'var(--theme-shadow)',
                    backdropFilter: 'blur(12px)',
                    marginTop: '2px'
                }}>
                    {suggestions.map(user => (
                        <div
                            key={user}
                            onClick={() => {
                                onAddUser(user);
                                setQuery('');
                                setIsOpen(false);
                            }}
                            style={{
                                padding: '6px 10px',
                                cursor: 'pointer',
                                fontSize: '0.85rem',
                                borderBottom: '1px solid rgba(255, 255, 255, 0.05)',
                                color: 'var(--theme-text)'
                            }}
                            onMouseEnter={(e) => {
                                e.currentTarget.style.backgroundColor = 'rgba(0, 240, 255, 0.15)';
                                e.currentTarget.style.color = 'var(--theme-primary)';
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.backgroundColor = '';
                                e.currentTarget.style.color = 'var(--theme-text)';
                            }}
                        >
                            {user}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function CorpAssetsVisibilityManager({
    locations,
    flagsToMap,
    defaultDivisions,
    corpDivisions,
    initialVisibility,
    allUsers
}: CorpAssetsVisibilityManagerProps) {
    const [settings, setSettings] = useState<Record<string, Record<string, HangarSetting>>>(() => {
        const initial: Record<string, Record<string, HangarSetting>> = {};
        Object.keys(locations).forEach(locId => {
            initial[locId] = {};
            Object.keys(flagsToMap).forEach(flag => {
                const existing = initialVisibility[locId]?.[flag];
                initial[locId][flag] = {
                    visible: !!existing?.visible,
                    restricted: !!(existing?.visible && existing.users && existing.users.length > 0),
                    users: existing?.users || []
                };
            });
        });
        return initial;
    });

    const toggleVisible = (locId: string, flag: string) => {
        setSettings(prev => {
            const current = prev[locId]?.[flag];
            if (!current) return prev;
            return {
                ...prev,
                [locId]: {
                    ...prev[locId],
                    [flag]: {
                        ...current,
                        visible: !current.visible,
                        // Reset restriction if turning off
                        restricted: !current.visible ? false : current.restricted,
                        users: !current.visible ? [] : current.users
                    }
                }
            };
        });
    };

    const toggleRestricted = (locId: string, flag: string) => {
        setSettings(prev => {
            const current = prev[locId]?.[flag];
            if (!current) return prev;
            const newRestricted = !current.restricted;
            return {
                ...prev,
                [locId]: {
                    ...prev[locId],
                    [flag]: {
                        ...current,
                        restricted: newRestricted,
                        users: newRestricted ? current.users : []
                    }
                }
            };
        });
    };

    const addUser = (locId: string, flag: string, username: string) => {
        setSettings(prev => {
            const current = prev[locId]?.[flag];
            if (!current) return prev;
            if (current.users.includes(username)) return prev;
            return {
                ...prev,
                [locId]: {
                    ...prev[locId],
                    [flag]: {
                        ...current,
                        users: [...current.users, username]
                    }
                }
            };
        });
    };

    const removeUser = (locId: string, flag: string, username: string) => {
        setSettings(prev => {
            const current = prev[locId]?.[flag];
            if (!current) return prev;
            return {
                ...prev,
                [locId]: {
                    ...prev[locId],
                    [flag]: {
                        ...current,
                        users: current.users.filter(u => u !== username)
                    }
                }
            };
        });
    };

    const locationList = Object.values(locations);

    return (
        <div>
            {locationList.map((loc) => {
                const locId = loc.id;
                // Try to resolve custom division names for this location
                // If there are corporation assets, we can look up division names.
                // We don't have corpId directly on location here, but corpDivisions is keyed by corpId.
                // Let's use defaultDivisions or the first available corpDivision map if corpDivisions is present.
                const firstCorpId = Object.keys(corpDivisions)[0];
                const divisions = firstCorpId ? corpDivisions[firstCorpId] : defaultDivisions;

                return (
                    <div key={locId} className="box mb-5">
                        <h3 className="title is-5 mb-4" style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap' }}>
                            {loc.name}
                            {loc.systemName && (
                                <span className="tag is-dark ml-2" style={{ fontSize: '0.75rem', padding: '0.2rem 0.5rem', backgroundColor: '#1e293b', borderRadius: '4px' }}>
                                    {loc.systemName}
                                </span>
                            )}
                        </h3>

                        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                            {Object.entries(flagsToMap).map(([flag, label]) => {
                                const hangarName = divisions[label] ?? `Hangar ${label}`;
                                const state = settings[locId]?.[flag] || { visible: false, restricted: false, users: [] };

                                return (
                                    <div 
                                        key={flag} 
                                        style={{ 
                                            padding: '1rem', 
                                            borderRadius: '8px', 
                                            border: '1px solid rgba(255, 255, 255, 0.05)',
                                            backgroundColor: state.visible ? 'rgba(0, 240, 255, 0.02)' : 'transparent',
                                            transition: 'background-color 0.2s'
                                        }}
                                    >
                                        {/* Hidden inputs to sync with Symfony's request processing */}
                                        {state.visible && (
                                            <input type="hidden" name={`visibility[${locId}][${flag}][visible]`} value="1" />
                                        )}
                                        {state.visible && state.restricted && state.users.map(u => (
                                            <input key={u} type="hidden" name={`visibility[${locId}][${flag}][users][]`} value={u} />
                                        ))}

                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: '1rem' }}>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                                                <input
                                                    type="checkbox"
                                                    id={`chk-${locId}-${flag}`}
                                                    checked={state.visible}
                                                    onChange={() => toggleVisible(locId, flag)}
                                                    style={{ cursor: 'pointer', width: '1.1rem', height: '1.1rem', accentColor: 'var(--theme-primary)' }}
                                                />
                                                <label 
                                                    htmlFor={`chk-${locId}-${flag}`} 
                                                    style={{ 
                                                        cursor: 'pointer', 
                                                        fontWeight: 500, 
                                                        color: state.visible ? 'var(--theme-text)' : 'var(--theme-text-muted)' 
                                                    }}
                                                >
                                                    {hangarName}
                                                </label>
                                            </div>

                                            {state.visible && (
                                                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem', width: '100%', maxWidth: '450px', marginTop: '0.25rem' }}>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                                                        <input
                                                            type="checkbox"
                                                            id={`restrict-${locId}-${flag}`}
                                                            checked={state.restricted}
                                                            onChange={() => toggleRestricted(locId, flag)}
                                                            style={{ cursor: 'pointer', width: '0.95rem', height: '0.95rem', accentColor: 'var(--theme-primary)' }}
                                                        />
                                                        <label htmlFor={`restrict-${locId}-${flag}`} style={{ cursor: 'pointer', fontSize: '0.85rem', color: 'var(--theme-text-muted)' }}>
                                                            Sichtbarkeit auf bestimmte Mitglieder einschränken
                                                        </label>
                                                    </div>

                                                    {state.restricted && (
                                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem', paddingLeft: '1.5rem', marginTop: '0.25rem' }}>
                                                            {state.users.length > 0 ? (
                                                                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
                                                                    {state.users.map(user => (
                                                                        <span 
                                                                            key={user} 
                                                                            style={{ 
                                                                                fontSize: '0.75rem',
                                                                                padding: '0.2rem 0.5rem',
                                                                                backgroundColor: 'rgba(0, 240, 255, 0.1)',
                                                                                border: '1px solid rgba(0, 240, 255, 0.2)',
                                                                                borderRadius: '12px',
                                                                                color: 'var(--theme-primary)',
                                                                                display: 'inline-flex',
                                                                                alignItems: 'center',
                                                                                gap: '0.3rem'
                                                                            }}
                                                                        >
                                                                            {user}
                                                                            <button 
                                                                                type="button" 
                                                                                onClick={() => removeUser(locId, flag, user)}
                                                                                style={{
                                                                                    background: 'none',
                                                                                    border: 'none',
                                                                                    color: 'var(--theme-primary)',
                                                                                    cursor: 'pointer',
                                                                                    fontWeight: 'bold',
                                                                                    fontSize: '0.85rem',
                                                                                    lineHeight: 1,
                                                                                    padding: 0
                                                                                }}
                                                                            >
                                                                                &times;
                                                                            </button>
                                                                        </span>
                                                                    ))}
                                                                </div>
                                                            ) : (
                                                                <span style={{ fontSize: '0.8rem', color: '#f59e0b', fontStyle: 'italic' }}>
                                                                    Keine Mitglieder ausgewählt (Hangar bleibt unsichtbar, bis Mitglieder hinzugefügt werden).
                                                                </span>
                                                            )}

                                                            <HangarUserSearch 
                                                                allUsers={allUsers}
                                                                selectedUsers={state.users}
                                                                onAddUser={(user) => addUser(locId, flag, user)}
                                                            />
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                );
            })}

            {locationList.length > 0 ? (
                <div className="field mt-5">
                    <div className="control">
                        <button type="submit" className="button is-primary is-fullwidth" style={{
                            padding: '0.75rem',
                            fontSize: '1rem',
                            fontWeight: '600',
                            borderRadius: '8px',
                            cursor: 'pointer',
                            backgroundColor: 'var(--theme-primary)',
                            color: '#0d121f',
                            border: 'none',
                            transition: 'background-color 0.2s',
                            width: '100%'
                        }}
                        onMouseEnter={(e) => e.currentTarget.style.backgroundColor = 'var(--theme-primary-hover)'}
                        onMouseLeave={(e) => e.currentTarget.style.backgroundColor = 'var(--theme-primary)'}
                        >
                            💾 Sichtbarkeits-Freigaben speichern
                        </button>
                    </div>
                </div>
            ) : (
                <div style={{
                    padding: '1.25rem',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    border: '1px solid rgba(245, 158, 11, 0.2)',
                    borderRadius: '8px',
                    color: '#fbbf24'
                }}>
                    Bisher wurden keine Corporation-Assets in der Datenbank gefunden.
                    Bitte stelle sicher, dass der Synchronisierungs-Cronjob gelaufen ist.
                </div>
            )}
        </div>
    );
}
