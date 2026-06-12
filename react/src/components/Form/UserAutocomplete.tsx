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
        <div ref={containerRef} style={{ position: 'relative', marginBottom: 'var(--spacing)' }}>
            <div style={{ display: 'flex', position: 'relative' }}>
                <input 
                    type="text" 
                    id={inputId}
                    name={inputName}
                    className="input"
                    value={query}
                    onChange={handleInputChange}
                    onFocus={() => setIsOpen(true)}
                    autoComplete="off" 
                    placeholder={placeholder}
                    form={form}
                    style={{ paddingRight: '2.5rem' }}
                />
                {query && (
                    <button
                        type="button"
                        onClick={handleClear}
                        style={{
                            position: 'absolute',
                            right: '8px',
                            top: '50%',
                            transform: 'translateY(-50%)',
                            background: 'none',
                            border: 'none',
                            color: 'var(--theme-text-muted, #888)',
                            cursor: 'pointer',
                            fontSize: '1.2rem',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            padding: '4px'
                        }}
                        title="Auswahl aufheben"
                    >
                        &times;
                    </button>
                )}
            </div>

            {isOpen && suggestions.length > 0 && (
                <div style={{
                    position: 'absolute',
                    width: '100%',
                    maxHeight: '200px',
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
                    {suggestions.map((user) => (
                        <div 
                            key={user}
                            onClick={() => handleSelectSuggestion(user)}
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
                            {user}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
