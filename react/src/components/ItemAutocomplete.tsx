import React, { useState, useEffect, useRef } from 'react';

interface Item {
    id: number;
    name: string;
    variation: string;
}

interface ItemAutocompleteProps {
    jwtToken: string;
    inputId: string;
    inputName: string;
    defaultValue?: string;
    defaultId?: string | number;
    placeholder?: string;
    form?: string;
}

export default function ItemAutocomplete({
    jwtToken,
    inputId,
    inputName,
    defaultValue = '',
    defaultId = '',
    placeholder = 'Item eingeben und auswählen...',
    form
}: ItemAutocompleteProps) {
    const [query, setQuery] = useState(defaultValue);
    const [selectedId, setSelectedId] = useState<string | number>(defaultId);
    const [suggestions, setSuggestions] = useState<Item[]>([]);
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    // HTML5 Client-side validation: enforce selecting an item from the autocomplete list
    useEffect(() => {
        if (inputRef.current) {
            if (query && !selectedId) {
                inputRef.current.setCustomValidity('Bitte wähle ein Item aus der Liste aus.');
            } else {
                inputRef.current.setCustomValidity('');
            }
        }
    }, [query, selectedId]);

    // Sync defaultValue changes if any
    useEffect(() => {
        setQuery(defaultValue);
        setSelectedId(defaultId);
    }, [defaultValue, defaultId]);

    // Fetch autocomplete suggestions with debounce
    useEffect(() => {
        if (query.trim().length < 2 || selectedId) {
            setSuggestions([]);
            return;
        }

        const timer = setTimeout(() => {
            fetch(`/api/sde/items?q=${encodeURIComponent(query.trim())}`, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${jwtToken}`,
                    'Accept': 'application/json'
                }
            })
            .then(res => {
                if (!res.ok) {
                    throw new Error('Unauthorized or request failed');
                }
                return res.json();
            })
            .then((data: Item[]) => {
                setSuggestions(data);
                setIsOpen(data.length > 0);
            })
            .catch(err => {
                console.error('Failed to search SDE items:', err);
                setSuggestions([]);
            });
        }, 250);

        return () => clearTimeout(timer);
    }, [query, selectedId, jwtToken]);

    // Handle click outside to close suggestions
    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const val = e.target.value;
        setQuery(val);
        setSelectedId(''); // Clear selection when user starts typing again
    };

    const handleSelectSuggestion = (item: Item) => {
        setQuery(item.name);
        setSelectedId(item.id);
        setIsOpen(false);
    };

    return (
        <div ref={containerRef} style={{ position: 'relative', marginBottom: 'var(--spacing)' }}>
            <input 
                type="text" 
                ref={inputRef}
                className="input"
                value={query}
                onChange={handleInputChange}
                onFocus={() => {
                    if (suggestions.length > 0) setIsOpen(true);
                }}
                autoComplete="off" 
                placeholder={placeholder}
                required
            />
            
            {/* The hidden field that actually gets submitted with the form */}
            <input 
                type="hidden" 
                id={inputId} 
                name={inputName} 
                value={selectedId}
                form={form}
                required
            />

            {isOpen && suggestions.length > 0 && (
                <div style={{
                    position: 'absolute',
                    width: '100%',
                    maxHeight: '250px',
                    overflowY: 'auto',
                    zIndex: 1000,
                    background: 'rgba(13, 18, 31, 0.95)',
                    border: '1px solid var(--theme-card-border, rgba(0, 240, 255, 0.15))',
                    borderRadius: '8px',
                    boxShadow: 'var(--theme-shadow)',
                    backdropFilter: 'blur(12px)',
                    WebkitBackdropFilter: 'blur(12px)',
                    marginTop: '4px'
                }}>
                    {suggestions.map((item) => (
                        <div 
                            key={item.id}
                            onClick={() => handleSelectSuggestion(item)}
                            style={{
                                padding: '8px 12px',
                                cursor: 'pointer',
                                borderBottom: '1px solid var(--theme-card-border, rgba(0, 240, 255, 0.1))',
                                transition: 'background 0.15s, color 0.15s'
                            }}
                            onMouseEnter={(e) => {
                                e.currentTarget.style.background = 'rgba(0, 240, 255, 0.15)';
                                e.currentTarget.style.color = 'var(--theme-primary, #00f0ff)';
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.background = '';
                                e.currentTarget.style.color = '';
                            }}
                        >
                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                <img 
                                    src={`https://images.evetech.net/types/${item.id}/${item.variation || 'icon'}?size=32`} 
                                    style={{ width: '24px', height: '24px', borderRadius: '4px' }} 
                                    alt="" 
                                    loading="lazy"
                                />
                                <span>{item.name}</span>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
