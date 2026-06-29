import React, { useState, useEffect, useRef } from 'react';
import ItemPasteInput from '../Form/ItemPasteInput';

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
        if (searchQuery.trim().length < 2) {
            setSuggestions([]);
            return;
        }

        setSearchingItems(true);
        const timer = setTimeout(() => {
            fetch(`/api/sde/items?q=${encodeURIComponent(searchQuery.trim())}`, {
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
        <div className="columns">
            <style>{`
                .sidebar-card-prof {
                    background: rgba(0, 0, 0, 0.15);
                    border: 1px solid var(--theme-card-border, #333);
                    border-radius: 8px;
                    padding: 1rem;
                    height: 100%;
                }
                .list-section-title-prof {
                    font-size: 0.75rem;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    color: var(--theme-text-muted, #888);
                    margin-top: 1rem;
                    margin-bottom: 0.5rem;
                    border-bottom: 1px solid rgba(255,255,255,0.05);
                    padding-bottom: 2px;
                }
                .list-item-selector-prof {
                    padding: 0.6rem 0.75rem;
                    border-radius: 6px;
                    border: 1px solid transparent;
                    background: rgba(0, 0, 0, 0.2);
                    cursor: pointer;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    transition: all 0.2s;
                    margin-bottom: 0.4rem;
                }
                .list-item-selector-prof:hover {
                    border-color: var(--theme-primary, #00f0ff);
                }
                .list-item-selector-prof.is-active {
                    background: rgba(0, 240, 255, 0.06);
                    border-color: var(--theme-primary, #00f0ff);
                }
                .tag-template-prof {
                    background: rgba(0, 240, 255, 0.1);
                    color: #00f0ff;
                    font-size: 0.7rem;
                    padding: 1px 4px;
                    border-radius: 3px;
                }
                .tag-private-prof {
                    background: rgba(0, 255, 170, 0.1);
                    color: #00ffaa;
                    font-size: 0.7rem;
                    padding: 1px 4px;
                    border-radius: 3px;
                }
                .panel-prof {
                    background: rgba(0, 0, 0, 0.1);
                    border: 1px solid var(--theme-card-border, #333);
                    border-radius: 8px;
                    padding: 1.25rem;
                }
                .suggestions-dropdown-prof {
                    position: absolute;
                    width: 100%;
                    max-height: 200px;
                    overflow-y: auto;
                    z-index: 1000;
                    background: rgba(13, 18, 31, 0.98);
                    border: 1px solid var(--theme-card-border, #444);
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.5);
                    backdrop-filter: blur(12px);
                    margin-top: 4px;
                }
                .suggestion-entry-prof {
                    padding: 6px 10px;
                    cursor: pointer;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    transition: all 0.15s;
                    font-size: 0.85rem;
                }
                .suggestion-entry-prof:hover {
                    background: rgba(0, 240, 255, 0.15);
                    color: var(--theme-primary, #00f0ff);
                }
                .input-dark-prof {
                    background: rgba(0,0,0,0.3) !important;
                    border: 1px solid var(--theme-card-border, #444) !important;
                    color: #fff !important;
                }
            `}</style>

            {/* LEFT: Lists selection */}
            <div className="column is-one-third">
                <div className="sidebar-card-prof">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
                        <h3 className="title is-6 mb-0" style={{ color: '#fff' }}>Listen verwalten</h3>
                        <button 
                            className="button is-small is-primary"
                            onClick={() => setShowCreateForm(!showCreateForm)}
                        >
                            {showCreateForm ? 'Abbrechen' : 'Neu'}
                        </button>
                    </div>

                    {showCreateForm && (
                        <form onSubmit={handleCreateList} className="mb-4 p-3" style={{ background: 'rgba(0,0,0,0.2)', borderRadius: '6px', border: '1px solid rgba(255,255,255,0.05)' }}>
                            <div className="field">
                                <label className="label is-small">Name</label>
                                <input 
                                    type="text" 
                                    className="input is-small input-dark-prof" 
                                    placeholder="z. B. Abyss Loot"
                                    value={newListName}
                                    onChange={(e) => setNewListName(e.target.value)}
                                    required
                                />
                            </div>
                            <div className="field">
                                <label className="label is-small">Beschreibung</label>
                                <input 
                                    type="text" 
                                    className="input is-small input-dark-prof" 
                                    placeholder="Optionale Beschreibung"
                                    value={newListDesc}
                                    onChange={(e) => setNewListDesc(e.target.value)}
                                />
                            </div>
                            <button type="submit" className="button is-small is-success is-fullwidth mt-2">Erstellen</button>
                        </form>
                    )}

                    {loadingLists ? (
                        <p className="text-muted is-size-7">Lade Listen...</p>
                    ) : listError ? (
                        <p className="has-text-danger is-size-7">{listError}</p>
                    ) : (
                        <div>
                            <div className="list-section-title-prof">Meine Listen</div>
                            {privateLists.length === 0 ? (
                                <p className="text-muted is-size-7 my-2">Keine eigenen Listen.</p>
                            ) : (
                                privateLists.map(list => (
                                    <div 
                                        key={list.id} 
                                        className={`list-item-selector-prof ${selectedListId === list.id ? 'is-active' : ''}`}
                                        onClick={() => setSelectedListId(list.id)}
                                    >
                                        <div style={{ minWidth: 0 }}>
                                            <div className="has-text-weight-bold is-size-7" style={{ textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap' }}>
                                                {list.name}
                                            </div>
                                        </div>
                                        <button 
                                            className="button is-small is-danger is-text"
                                            style={{ padding: '0 4px', height: 'auto', fontSize: '0.75rem' }}
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

                            <div className="list-section-title-prof">System-Vorlagen</div>
                            {templates.map(list => (
                                <div 
                                    key={list.id} 
                                    className={`list-item-selector-prof ${selectedListId === list.id ? 'is-active' : ''}`}
                                    onClick={() => setSelectedListId(list.id)}
                                >
                                    <div className="has-text-weight-bold is-size-7" style={{ textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap' }}>
                                        {list.name}
                                    </div>
                                    <span className="tag-template-prof">Kopierbar</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* RIGHT: Selected list items editor */}
            <div className="column is-two-thirds">
                {activeList ? (
                    <div className="panel-prof">
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem', borderBottom: '1px solid #333', paddingBottom: '8px' }}>
                            <div>
                                <h4 className="title is-5 mb-0" style={{ color: '#fff' }}>
                                    {activeList.name}
                                </h4>
                                <p className="is-size-7 text-muted mt-1">{activeList.description || 'Keine Beschreibung.'}</p>
                            </div>
                            
                            {activeList.isTemplate ? (
                                <button 
                                    className="button is-small is-info"
                                    onClick={() => activeList && handleCopyList(activeList.id)}
                                >
                                    📋 Als eigene Liste kopieren
                                </button>
                            ) : (
                                <span className="tag-private-prof">Bearbeitbar</span>
                            )}
                        </div>

                        <div className="columns">
                            {/* Left (Add Items) */}
                            <div className="column is-6">
                                <h5 className="title is-6 mb-2" style={{ color: '#fff' }}>Items verwalten</h5>
                                
                                {activeList.isTemplate ? (
                                    <div className="notification is-info is-light p-3 is-size-7" style={{ background: 'rgba(0,240,255,0.04)', border: '1px solid rgba(0,240,255,0.15)', color: 'var(--theme-text-muted)' }}>
                                        Vorlagen können nicht direkt bearbeitet werden. Kopiere sie zuerst!
                                    </div>
                                ) : (
                                    <>
                                        <div ref={containerRef} style={{ position: 'relative', marginBottom: '1rem' }}>
                                            <input 
                                                type="text" 
                                                className="input is-small input-dark-prof"
                                                placeholder="Item suchen und hinzufügen..."
                                                value={searchQuery}
                                                onChange={(e) => setSearchQuery(e.target.value)}
                                                onFocus={() => {
                                                    if (suggestions.length > 0) setShowSuggestions(true);
                                                }}
                                                autoComplete="off"
                                            />
                                            {searchingItems && (
                                                <span style={{ position: 'absolute', right: '8px', top: '8px', fontSize: '0.75rem', color: '#888' }}>...</span>
                                            )}

                                            {showSuggestions && suggestions.length > 0 && (
                                                <div className="suggestions-dropdown-prof">
                                                    {suggestions.map(item => (
                                                        <div 
                                                            key={item.id} 
                                                            className="suggestion-entry-prof"
                                                            onClick={() => handleAddItem(item.id)}
                                                        >
                                                            <img 
                                                                src={`https://images.evetech.net/types/${item.id}/${item.variation || 'icon'}?size=32`} 
                                                                alt="" 
                                                                style={{ width: '18px', height: '18px', borderRadius: '3px' }}
                                                            />
                                                            <span>{item.name}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', margin: '1rem 0', color: 'var(--theme-text-muted, #888)', fontSize: '0.75rem' }}>
                                            <div style={{ flex: 1, height: '1px', background: 'rgba(255,255,255,0.05)' }}></div>
                                            <span>ODER</span>
                                            <div style={{ flex: 1, height: '1px', background: 'rgba(255,255,255,0.05)' }}></div>
                                        </div>

                                        <ItemPasteInput 
                                            jwtToken={jwtToken} 
                                            onItemsParsed={handleItemsParsed} 
                                        />
                                    </>
                                )}
                            </div>

                            {/* Right (Tracked Items list) */}
                            <div className="column is-6">
                                <h5 className="title is-6 mb-2" style={{ color: '#888' }}>Gegenstände in der Liste ({activeList.items.length})</h5>
                                
                                {activeList.items.length === 0 ? (
                                    <p className="text-muted is-size-7">Keine Items vorhanden.</p>
                                ) : (
                                    <div style={{ maxHeight: '250px', overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '4px' }}>
                                        {activeList.items.map(item => (
                                            <div 
                                                key={item.id} 
                                                style={{ 
                                                    display: 'flex', 
                                                    justifyContent: 'space-between', 
                                                    alignItems: 'center', 
                                                    padding: '5px 8px', 
                                                    background: 'rgba(255,255,255,0.02)', 
                                                    borderRadius: '4px',
                                                    border: '1px solid rgba(255,255,255,0.04)'
                                                }}
                                            >
                                                <div style={{ display: 'flex', alignItems: 'center', gap: '6px', minWidth: 0, fontSize: '0.8rem' }}>
                                                    <img 
                                                        src={`https://images.evetech.net/types/${item.typeId}/icon?size=32`} 
                                                        alt="" 
                                                        style={{ width: '18px', height: '18px', borderRadius: '3px' }}
                                                    />
                                                    <span style={{ textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap' }} title={item.typeName}>
                                                        {item.typeName}
                                                    </span>
                                                </div>
                                                {!activeList.isTemplate && (
                                                    <button 
                                                        className="button is-small is-danger is-text" 
                                                        style={{ height: 'auto', padding: '0 4px', fontSize: '0.75rem' }}
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
                    <div className="has-text-centered p-5" style={{ background: 'rgba(0,0,0,0.1)', borderRadius: '8px', border: '1px dashed #333' }}>
                        <p className="text-muted is-size-7">Bitte wähle links eine Tracking-Liste aus.</p>
                    </div>
                )}
            </div>
        </div>
    );
}
