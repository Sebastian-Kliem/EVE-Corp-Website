import React, { useState, useEffect, useRef } from 'react';
import ItemPasteInput from '../Form/ItemPasteInput';
import { cleanItemSearch } from '../../utils/itemSearch';

interface TrackingListItem {
    id: number;
    typeId: number;
    typeName: string;
}

interface TrackingList {
    id: number;
    name: string;
    description: string | null;
    isGlobal: boolean;
    isTemplate: boolean;
    items: TrackingListItem[];
}

interface SdeItem {
    id: number;
    name: string;
    variation: string;
}

interface TrackingListManagerProps {
    jwtToken: string;
}

export default function TrackingListManager({ jwtToken }: TrackingListManagerProps) {
    const [lists, setLists] = useState<TrackingList[]>([]);
    const [selectedListId, setSelectedListId] = useState<number | null>(null);
    const [loadingLists, setLoadingLists] = useState<boolean>(true);
    
    // Create new list form state
    const [newListName, setNewListName] = useState('');
    const [newListDesc, setNewListDesc] = useState('');
    const [showCreateForm, setShowCreateForm] = useState(false);

    // Item Autocomplete state
    const [searchQuery, setSearchQuery] = useState('');
    const [suggestions, setSuggestions] = useState<SdeItem[]>([]);
    const [searchingItems, setSearchingItems] = useState(false);
    const [showSuggestions, setShowSuggestions] = useState(false);
    const containerRef = useRef<HTMLDivElement | null>(null);

    const [listError, setListError] = useState<string | null>(null);

    // Fetch lists on mount
    useEffect(() => {
        fetchLists();
    }, []);

    // Handle click outside autocomplete
    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
                setShowSuggestions(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Debounce SDE Item Search
    useEffect(() => {
        const cleanQuery = cleanItemSearch(searchQuery).trim();
        if (cleanQuery.length < 2) {
            setSuggestions([]);
            return;
        }

        setSearchingItems(true);
        const timer = setTimeout(() => {
            fetch(`/api/sde/items?q=${encodeURIComponent(cleanQuery)}`, {
                headers: {
                    'Authorization': `Bearer ${jwtToken}`,
                    'Accept': 'application/json'
                }
            })
                .then(res => {
                    if (!res.ok) throw new Error('Search failed');
                    return res.json();
                })
                .then((data: SdeItem[]) => {
                    setSuggestions(data);
                    setShowSuggestions(data.length > 0);
                    setSearchingItems(false);
                })
                .catch(err => {
                    console.error(err);
                    setSuggestions([]);
                    setSearchingItems(false);
                });
        }, 300);

        return () => clearTimeout(timer);
    }, [searchQuery, jwtToken]);

    const fetchLists = (selectIdAfterLoad?: number) => {
        setLoadingLists(true);
        setListError(null);
        fetch('/corp/tracking/api/lists')
            .then(res => {
                if (!res.ok) throw new Error('Fehler beim Laden der Listen.');
                return res.json();
            })
            .then((data: TrackingList[]) => {
                setLists(data);
                
                if (selectIdAfterLoad) {
                    setSelectedListId(selectIdAfterLoad);
                } else if (data.length > 0 && selectedListId === null) {
                    setSelectedListId(data[0].id);
                }
                setLoadingLists(false);
            })
            .catch(err => {
                setListError(err.message);
                setLoadingLists(false);
            });
    };

    const handleCreateList = (e: React.FormEvent) => {
        e.preventDefault();
        if (!newListName.trim()) return;

        fetch('/corp/tracking/api/lists', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name: newListName,
                description: newListDesc
            })
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    setNewListName('');
                    setNewListDesc('');
                    setShowCreateForm(false);
                    fetchLists(data.id);
                } else {
                    alert(data.error || 'Fehler beim Erstellen der Liste.');
                }
            })
            .catch(err => console.error(err));
    };

    const handleCopyList = (listId: number) => {
        fetch(`/corp/tracking/api/lists/${listId}/copy`, {
            method: 'POST'
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    fetchLists(data.id);
                } else {
                    alert(data.error || 'Fehler beim Kopieren der Vorlage.');
                }
            })
            .catch(err => console.error(err));
    };

    const handleDeleteList = (listId: number) => {
        if (!confirm('Möchtest du diese Tracking-Liste wirklich löschen? Alle Verknüpfungen gehen verloren.')) {
            return;
        }

        fetch(`/corp/tracking/api/lists/${listId}`, {
            method: 'DELETE'
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    setSelectedListId(null);
                    fetchLists();
                } else {
                    alert(data.error || 'Fehler beim Löschen.');
                }
            })
            .catch(err => console.error(err));
    };

    const handleAddItem = (typeId: number) => {
        if (selectedListId === null) return;

        fetch(`/corp/tracking/api/lists/${selectedListId}/items`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ typeId })
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    setSearchQuery('');
                    setSuggestions([]);
                    setShowSuggestions(false);
                    fetchLists(selectedListId); // Reload items
                } else {
                    alert(data.error || 'Fehler beim Hinzufügen des Items.');
                }
            })
            .catch(err => console.error(err));
    };

    const handleItemsParsed = (parsedItems: { typeId: number }[]) => {
        if (selectedListId === null) return;
        const typeIds = parsedItems.map(item => item.typeId);

        fetch(`/corp/tracking/api/lists/${selectedListId}/items/bulk`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${jwtToken}`
            },
            body: JSON.stringify({ typeIds })
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    fetchLists(selectedListId); // Reload items
                } else {
                    alert(data.error || 'Fehler beim Hinzufügen der Items.');
                }
            })
            .catch(err => console.error(err));
    };

    const handleRemoveItem = (itemId: number) => {
        fetch(`/corp/tracking/api/lists/items/${itemId}`, {
            method: 'DELETE'
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    fetchLists(selectedListId || undefined);
                } else {
                    alert(data.error || 'Fehler beim Entfernen.');
                }
            })
            .catch(err => console.error(err));
    };

    const activeList = lists.find(l => l.id === selectedListId) || null;
    const templates = lists.filter(l => l.isTemplate);
    const privateLists = lists.filter(l => !l.isTemplate);

    return (
        <div className="flex flex-wrap gap-6">
            {/* LEFT: Lists selection */}
            <div className="w-full md:w-1/3 flex-none">
                <div className="bg-[#00000026] border border-eve-border rounded-lg p-4 h-full">
                    <div className="flex justify-between items-center mb-4">
                        <h3 className="text-sm font-semibold text-white">Listen verwalten</h3>
                        <button 
                            className="inline-flex items-center justify-center border border-transparent rounded bg-eve-primary hover:brightness-115 text-[#060911] hover:text-[#060911] font-bold text-xs px-2.5 py-1.5 shadow-eve transition-all duration-300 cursor-pointer"
                            onClick={() => setShowCreateForm(!showCreateForm)}
                        >
                            {showCreateForm ? 'Abbrechen' : 'Neu'}
                        </button>
                    </div>

                    {showCreateForm && (
                        <form onSubmit={handleCreateList} className="mb-4 p-3 bg-black/20 rounded-lg border border-white/5">
                            <div className="mb-3">
                                <label className="block text-[10px] font-semibold text-eve-muted mb-1">Name</label>
                                <input 
                                    type="text" 
                                    className="rounded px-2.5 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full" 
                                    placeholder="z. B. Abyss Loot"
                                    value={newListName}
                                    onChange={(e) => setNewListName(e.target.value)}
                                    required
                                />
                            </div>
                            <div className="mb-3">
                                <label className="block text-[10px] font-semibold text-eve-muted mb-1">Beschreibung</label>
                                <input 
                                    type="text" 
                                    className="rounded px-2.5 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full" 
                                    placeholder="Optionale Beschreibung"
                                    value={newListDesc}
                                    onChange={(e) => setNewListDesc(e.target.value)}
                                />
                            </div>
                            <button type="submit" className="inline-flex items-center justify-center border border-transparent rounded bg-emerald-500 hover:brightness-115 text-white font-semibold text-xs px-2.5 py-1.5 shadow-eve transition-all duration-300 cursor-pointer w-full mt-2">
                                Erstellen
                            </button>
                        </form>
                    )}

                    {loadingLists ? (
                        <p className="text-eve-muted text-xs">Lade Listen...</p>
                    ) : listError ? (
                        <p className="text-rose-400 text-xs">{listError}</p>
                    ) : (
                        <div>
                            <div className="text-[10px] uppercase tracking-wider text-eve-muted mt-4 mb-2 border-b border-white/5 pb-0.5">Meine Listen</div>
                            {privateLists.length === 0 ? (
                                <p className="text-eve-muted text-xs my-2">Keine eigenen Listen.</p>
                            ) : (
                                privateLists.map(list => (
                                    <div 
                                        key={list.id} 
                                        className={`p-2.5 rounded-lg border cursor-pointer flex justify-between items-center transition-all duration-200 mb-1.5 hover:border-eve-primary ${
                                            selectedListId === list.id 
                                                ? 'bg-eve-primary/5 border-eve-primary' 
                                                : 'border-transparent bg-black/20'
                                        }`}
                                        onClick={() => setSelectedListId(list.id)}
                                    >
                                        <div className="min-w-0">
                                            <div className="font-bold text-xs truncate" title={list.name}>
                                                {list.name}
                                            </div>
                                        </div>
                                        <button 
                                            className="text-rose-400 hover:text-rose-300 transition-colors p-1 text-xs cursor-pointer ml-2"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                handleDeleteList(list.id);
                                            }}
                                            title="Liste löschen"
                                        >
                                            ❌
                                        </button>
                                    </div>
                                ))
                            )}

                            <div className="text-[10px] uppercase tracking-wider text-eve-muted mt-4 mb-2 border-b border-white/5 pb-0.5">System-Vorlagen</div>
                            {templates.map(list => (
                                <div 
                                    key={list.id} 
                                    className={`p-2.5 rounded-lg border cursor-pointer flex justify-between items-center transition-all duration-200 mb-1.5 hover:border-eve-primary ${
                                        selectedListId === list.id 
                                            ? 'bg-eve-primary/5 border-eve-primary' 
                                            : 'border-transparent bg-black/20'
                                    }`}
                                    onClick={() => setSelectedListId(list.id)}
                                >
                                    <div className="font-bold text-xs truncate" title={list.name}>
                                        {list.name}
                                    </div>
                                    <span className="bg-eve-primary/10 text-eve-primary text-[10px] px-1 py-0.5 rounded ml-2 flex-shrink-0">Kopierbar</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* RIGHT: Selected list items editor */}
            <div className="w-full md:w-2/3 flex-1">
                {activeList ? (
                    <div className="bg-black/10 border border-eve-border rounded-lg p-5">
                        <div className="flex justify-between items-center mb-4 border-b border-white/5 pb-3">
                            <div>
                                <h4 className="text-base font-semibold text-white">
                                    {activeList.name}
                                </h4>
                                <p className="text-xs text-eve-muted mt-1">{activeList.description || 'Keine Beschreibung.'}</p>
                            </div>
                            
                            {activeList.isTemplate ? (
                                <button 
                                    className="inline-flex items-center justify-center border border-transparent rounded bg-sky-600 hover:brightness-115 text-white font-semibold text-xs px-2.5 py-1.5 shadow-eve transition-all duration-300 cursor-pointer"
                                    onClick={() => activeList && handleCopyList(activeList.id)}
                                >
                                    📋 Als eigene Liste kopieren
                                </button>
                            ) : (
                                <span className="bg-emerald-500/10 text-emerald-400 text-[10px] px-1 py-0.5 rounded font-mono">Bearbeitbar</span>
                            )}
                        </div>

                        <div className="flex flex-wrap gap-6">
                            {/* Left (Add Items) */}
                            <div className="w-full md:w-1/2">
                                <h5 className="text-sm font-semibold text-white mb-2">Items verwalten</h5>
                                
                                {activeList.isTemplate ? (
                                    <div className="p-3 rounded text-xs bg-sky-500/10 border border-sky-500/30 text-sky-400">
                                        Vorlagen können nicht direkt bearbeitet werden. Kopiere sie zuerst!
                                    </div>
                                ) : (
                                    <>
                                        <div ref={containerRef} className="relative mb-4">
                                            <input 
                                                type="text" 
                                                className="rounded px-2.5 py-1.5 text-xs border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary transition-all duration-300 w-full"
                                                placeholder="Item suchen und hinzufügen..."
                                                value={searchQuery}
                                                onChange={(e) => setSearchQuery(cleanItemSearch(e.target.value))}
                                                onFocus={() => {
                                                    if (suggestions.length > 0) setShowSuggestions(true);
                                                }}
                                                autoComplete="off"
                                            />
                                            {searchingItems && (
                                                <span className="absolute right-2.5 top-2 text-xs text-eve-muted">...</span>
                                            )}

                                            {showSuggestions && suggestions.length > 0 && (
                                                <div className="absolute w-full max-h-[200px] overflow-y-auto z-50 bg-[#0d121ff2] border border-eve-border rounded-lg shadow-eve backdrop-blur-md mt-1">
                                                    {suggestions.map(item => (
                                                        <div 
                                                            key={item.id} 
                                                            className="p-2.5 cursor-pointer border-b border-white/5 flex items-center gap-2 transition-all duration-150 text-xs hover:bg-eve-primary/15 hover:text-eve-primary"
                                                            onClick={() => handleAddItem(item.id)}
                                                        >
                                                            <img 
                                                                src={`https://images.evetech.net/types/${item.id}/${item.variation || 'icon'}?size=32`} 
                                                                alt="" 
                                                                className="w-[18px] height-[18px] rounded"
                                                            />
                                                            <span>{item.name}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex items-center gap-2 my-4 text-eve-muted text-xs">
                                            <div className="flex-1 h-[1px] bg-white/5"></div>
                                            <span>ODER</span>
                                            <div className="flex-1 h-[1px] bg-white/5"></div>
                                        </div>

                                        <ItemPasteInput 
                                            jwtToken={jwtToken} 
                                            onItemsParsed={handleItemsParsed} 
                                        />
                                    </>
                                )}
                            </div>

                            {/* Right (Tracked Items list) */}
                            <div className="w-full md:w-1/2">
                                <h5 className="text-sm font-semibold text-eve-muted mb-2">Gegenstände in der Liste ({activeList.items.length})</h5>
                                
                                {activeList.items.length === 0 ? (
                                    <p className="text-eve-muted text-xs">Keine Items vorhanden.</p>
                                ) : (
                                    <div className="max-h-[250px] overflow-y-auto flex flex-col gap-1 pr-1.5">
                                        {activeList.items.map(item => (
                                            <div 
                                                key={item.id} 
                                                className="flex justify-between items-center p-1.5 px-2 bg-white/2 rounded border border-white/5 hover:border-white/10 transition-colors"
                                            >
                                                <div className="flex items-center gap-2 min-w-0 text-xs">
                                                    <img 
                                                        src={`https://images.evetech.net/types/${item.typeId}/icon?size=32`} 
                                                        alt="" 
                                                        className="w-[18px] height-[18px] rounded"
                                                    />
                                                    <span className="truncate text-white" title={item.typeName}>
                                                        {item.typeName}
                                                    </span>
                                                </div>
                                                {!activeList.isTemplate && (
                                                    <button 
                                                        className="text-rose-400 hover:text-rose-300 transition-colors p-1 text-xs cursor-pointer" 
                                                        onClick={() => handleRemoveItem(item.id)}
                                                        title="Entfernen"
                                                    >
                                                        ❌
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="text-center p-6 bg-black/10 rounded-lg border border-dashed border-eve-border">
                        <p className="text-eve-muted text-xs">Bitte wähle links eine Tracking-Liste aus.</p>
                    </div>
                )}
            </div>
        </div>
    );
}
