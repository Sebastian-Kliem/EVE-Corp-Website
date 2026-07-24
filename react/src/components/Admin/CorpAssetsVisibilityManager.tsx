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
        <div ref={containerRef} className="relative mt-2 w-full max-w-[300px]">
            <input
                type="text"
                className="rounded-lg w-full px-2.5 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300"
                placeholder="Mitglied suchen..."
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setIsOpen(true);
                }}
                onFocus={() => setIsOpen(true)}
            />
            {isOpen && suggestions.length > 0 && (
                <div className="absolute w-full max-h-[150px] overflow-y-auto z-[1000] bg-eve-card/98 border border-eve-border shadow-eve backdrop-blur-md rounded-md mt-0.5">
                    {suggestions.map(user => (
                        <div
                            key={user}
                            onClick={() => {
                                onAddUser(user);
                                setQuery('');
                                setIsOpen(false);
                            }}
                            className="px-2.5 py-1.5 cursor-pointer text-xs border-b border-white/5 text-eve-text transition-colors duration-150 hover:bg-eve-primary/15 hover:text-eve-primary"
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
                    <div key={locId} className="bg-eve-card border border-eve-border shadow-eve p-6 rounded-lg mb-6">
                        <h3 className="text-lg font-semibold mb-4 text-white flex items-center flex-wrap">
                            {loc.name}
                            {loc.systemName && (
                                <span className="inline-flex items-center justify-center px-2 py-0.5 text-xs font-semibold rounded bg-slate-800 border border-white/5 text-eve-muted ml-2">
                                    {loc.systemName}
                                </span>
                            )}
                        </h3>

                        <div className="flex flex-col gap-4">
                            {Object.entries(flagsToMap).map(([flag, label]) => {
                                const hangarName = divisions[label] ?? `Hangar ${label}`;
                                const state = settings[locId]?.[flag] || { visible: false, restricted: false, users: [] };

                                return (
                                    <div 
                                        key={flag} 
                                        className={`p-4 rounded-lg border transition-colors duration-200 ${state.visible ? 'border-eve-primary/20 bg-eve-primary/2' : 'border-white/5 bg-transparent'}`}
                                    >
                                        {/* Hidden inputs to sync with Symfony's request processing */}
                                        {state.visible && (
                                            <input type="hidden" name={`visibility[${locId}][${flag}][visible]`} value="1" />
                                        )}
                                        {state.visible && state.restricted && state.users.map(u => (
                                            <input key={u} type="hidden" name={`visibility[${locId}][${flag}][users][]`} value={u} />
                                        ))}

                                        <div className="flex justify-between items-start flex-wrap gap-4">
                                            <div className="flex items-center gap-3">
                                                <input
                                                    type="checkbox"
                                                    id={`chk-${locId}-${flag}`}
                                                    checked={state.visible}
                                                    onChange={() => toggleVisible(locId, flag)}
                                                    className="cursor-pointer w-4.5 h-4.5 accent-eve-primary"
                                                />
                                                <label 
                                                    htmlFor={`chk-${locId}-${flag}`} 
                                                    className={`cursor-pointer font-medium ${state.visible ? 'text-eve-text' : 'text-eve-muted'}`}
                                                >
                                                    {hangarName}
                                                </label>
                                            </div>

                                            {state.visible && (
                                                <div className="flex flex-col gap-2 w-full max-w-[450px] mt-1">
                                                    <div className="flex items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            id={`restrict-${locId}-${flag}`}
                                                            checked={state.restricted}
                                                            onChange={() => toggleRestricted(locId, flag)}
                                                            className="cursor-pointer w-4 h-4 accent-eve-primary"
                                                        />
                                                        <label htmlFor={`restrict-${locId}-${flag}`} className="cursor-pointer text-xs text-eve-muted">
                                                            Sichtbarkeit auf bestimmte Mitglieder einschränken
                                                        </label>
                                                    </div>

                                                    {state.restricted && (
                                                        <div className="flex flex-col gap-2 pl-6 mt-1">
                                                            {state.users.length > 0 ? (
                                                                <div className="flex flex-wrap gap-1.5">
                                                                    {state.users.map(user => (
                                                                        <span 
                                                                            key={user} 
                                                                            className="text-xs px-2 py-0.5 bg-eve-primary/10 border border-eve-primary/20 rounded-full text-eve-primary inline-flex items-center gap-1.5"
                                                                        >
                                                                            {user}
                                                                            <button 
                                                                                type="button" 
                                                                                onClick={() => removeUser(locId, flag, user)}
                                                                                className="bg-transparent border-none text-eve-primary cursor-pointer font-bold text-sm leading-none p-0"
                                                                            >
                                                                                &times;
                                                                            </button>
                                                                        </span>
                                                                    ))}
                                                                </div>
                                                            ) : (
                                                                <span className="text-xs text-amber-500 italic">
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
                <div className="mt-6">
                    <button 
                        type="submit" 
                        className="w-full inline-flex items-center justify-center border border-transparent rounded-lg bg-eve-primary hover:brightness-115 text-[#060911] hover:text-[#060911] font-semibold text-base py-3 shadow-eve transition-all duration-300 hover:-translate-y-0.5 cursor-pointer"
                    >
                        💾 Sichtbarkeits-Freigaben speichern
                    </button>
                </div>
            ) : (
                <div className="p-5 bg-amber-500/10 border border-amber-500/20 rounded-lg text-amber-400">
                    Bisher wurden keine Corporation-Assets in der Datenbank gefunden.
                    Bitte stelle sicher, dass der Synchronisierungs-Cronjob gelaufen ist.
                </div>
            )}
        </div>
    );
}
