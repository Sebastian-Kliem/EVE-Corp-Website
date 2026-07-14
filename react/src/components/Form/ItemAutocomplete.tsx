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
        <div ref={containerRef} className="relative mb-4">
            <input 
                type="text" 
                ref={inputRef}
                className="rounded-lg w-full px-3 py-2 text-base border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300"
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
                <div className="absolute w-full max-h-[250px] overflow-y-auto z-[1000] bg-eve-card/95 border border-eve-border shadow-eve backdrop-blur-md rounded-lg mt-1">
                    {suggestions.map((item) => (
                        <div 
                            key={item.id}
                            onClick={() => handleSelectSuggestion(item)}
                            className="px-3 py-2 cursor-pointer border-b border-eve-border/60 transition-colors duration-150 hover:bg-eve-primary/15 hover:text-eve-primary"
                        >
                            <div className="flex items-center gap-2">
                                <img 
                                    src={`https://images.evetech.net/types/${item.id}/${item.variation || 'icon'}?size=32`} 
                                    className="w-6 h-6 rounded" 
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
