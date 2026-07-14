import React, { useState, useEffect, useRef } from 'react';

interface UserAutocompleteProps {
    inputId: string;
    inputName: string;
    defaultValue?: string;
    placeholder?: string;
    users: string[];
    form?: string;
}

export default function UserAutocomplete({
    inputId,
    inputName,
    defaultValue = '',
    placeholder = 'Mitglied suchen...',
    users = [],
    form
}: UserAutocompleteProps) {
    const [query, setQuery] = useState(defaultValue);
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    // Filter users based on query
    useEffect(() => {
        if (!isOpen) {
            setSuggestions([]);
            return;
        }

        const trimmedQuery = query.trim().toLowerCase();
        if (trimmedQuery === '') {
            // Show all users if input is focused but empty
            setSuggestions(users);
        } else {
            // Filter users containing the query
            const filtered = users.filter(user => 
                user.toLowerCase().includes(trimmedQuery)
            );
            setSuggestions(filtered);
        }
    }, [query, isOpen, users]);

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
        setQuery(e.target.value);
        setIsOpen(true);
    };

    const handleSelectSuggestion = (user: string) => {
        setQuery(user);
        setIsOpen(false);
    };

    const handleClear = () => {
        setQuery('');
        setIsOpen(true);
    };

    return (
        <div ref={containerRef} className="relative mb-4">
            <div className="flex relative">
                <input 
                    type="text" 
                    id={inputId}
                    name={inputName}
                    className="rounded-lg w-full pl-3 pr-10 py-2 text-base border border-eve-border text-eve-text bg-[#0f172a59] focus:outline-none focus:border-eve-primary focus:shadow-[0_0_10px_rgba(0,240,255,0.25)] transition-all duration-300"
                    value={query}
                    onChange={handleInputChange}
                    onFocus={() => setIsOpen(true)}
                    autoComplete="off" 
                    placeholder={placeholder}
                    form={form}
                />
                {query && (
                    <button
                        type="button"
                        onClick={handleClear}
                        className="absolute right-2 top-1/2 -translate-y-1/2 bg-transparent border-none text-eve-muted cursor-pointer text-xl flex items-center justify-center p-1"
                        title="Auswahl aufheben"
                    >
                        &times;
                    </button>
                )}
            </div>

            {isOpen && suggestions.length > 0 && (
                <div className="absolute w-full max-h-[200px] overflow-y-auto z-[1000] bg-eve-card/95 border border-eve-border shadow-eve backdrop-blur-md rounded-lg mt-1">
                    {suggestions.map((user) => (
                        <div 
                            key={user}
                            onClick={() => handleSelectSuggestion(user)}
                            className="px-3 py-2 cursor-pointer border-b border-eve-border/60 transition-colors duration-150 hover:bg-eve-primary/15 hover:text-eve-primary"
                        >
                            {user}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
