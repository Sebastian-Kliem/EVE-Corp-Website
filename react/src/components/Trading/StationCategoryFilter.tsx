import { useEffect, useMemo, useState } from 'react';
import { fetchJson, loadStored, saveStored, Spinner } from './tradingShared';

export type StationFilterMode = 'all' | 'only' | 'except';

export interface StationFilterData {
    mode: StationFilterMode;
    marketGroupIds: number[];
    hiddenItems: { typeId: number; name: string }[];
}

export interface StationFilterChange {
    mode: StationFilterMode;
    marketGroupIds: number[];
    hiddenTypeIds: number[];
}

interface MarketGroup {
    id: number;
    parentId: number | null;
    name: string;
}

interface GroupIndex {
    byId: Map<number, MarketGroup>;
    childrenByParent: Map<number | null, MarketGroup[]>;
}

const MODES: { key: StationFilterMode; label: string }[] = [
    { key: 'all', label: 'Alle Kategorien' },
    { key: 'only', label: 'Nur ausgewählte' },
    { key: 'except', label: 'Alle außer ausgewählte' },
];
const OPEN_STORAGE_KEY = 'wh-trading-category-filter-open';
const SEARCH_RESULT_LIMIT = 50;

interface StationCategoryFilterProps {
    marketGroupsUrl: string;
    filter: StationFilterData;
    saving: boolean;
    onChange: (change: StationFilterChange) => void;
}

export default function StationCategoryFilter({ marketGroupsUrl, filter, saving, onChange }: StationCategoryFilterProps) {
    const [groups, setGroups] = useState<MarketGroup[] | null>(null);
    const [groupsError, setGroupsError] = useState<string | null>(null);
    const [isOpen, setIsOpen] = useState<boolean>(() => loadStored<boolean>(OPEN_STORAGE_KEY, false) === true);
    const [search, setSearch] = useState('');

    useEffect(() => {
        fetchJson<MarketGroup[]>(marketGroupsUrl)
            .then(setGroups)
            .catch((e: Error) => setGroupsError(e.message));
    }, [marketGroupsUrl]);

    const groupIndex = useMemo(() => _buildIndex(groups ?? []), [groups]);
    const selectedIds = useMemo(() => new Set(filter.marketGroupIds), [filter.marketGroupIds]);
    const hiddenTypeIds = filter.hiddenItems.map(item => item.typeId);

    const toggleOpen = () => {
        setIsOpen(!isOpen);
        saveStored(OPEN_STORAGE_KEY, !isOpen);
    };

    const change = (partial: Partial<StationFilterChange>) => {
        onChange({ mode: filter.mode, marketGroupIds: filter.marketGroupIds, hiddenTypeIds, ...partial });
    };

    const toggleGroup = (groupId: number) => {
        if (selectedIds.has(groupId)) {
            change({ marketGroupIds: filter.marketGroupIds.filter(id => id !== groupId) });
            return;
        }
        // Subgroups are covered by the new parent and leave the selection
        const remaining = filter.marketGroupIds.filter(id => !_isDescendant(id, groupId, groupIndex));
        // Choosing a group without a mode means the user wants to filter by it
        change({ marketGroupIds: [...remaining, groupId], mode: filter.mode === 'all' ? 'only' : filter.mode });
    };

    return (
        <div className="bg-eve-card border border-eve-border shadow-eve rounded-lg">
            <button type="button" onClick={toggleOpen} className="w-full text-left px-4 py-2.5 text-xs cursor-pointer flex items-center justify-between gap-4">
                <span className="text-eve-primary">Kategorien &amp; ausgeblendete Items</span>
                <span className="flex items-center gap-2 text-eve-muted">
                    {saving && <Spinner />}
                    <span className="truncate max-w-[50vw]">{_summary(filter, groupIndex)}</span>
                    <span className={`text-eve-primary transition-transform duration-200 ${isOpen ? '' : '-rotate-90'}`}>▼</span>
                </span>
            </button>
            {isOpen && (
                <div className="px-4 pb-4 flex flex-col gap-4 text-xs">
                    <div className="flex flex-wrap gap-2">
                        {MODES.map(mode => (
                            <button
                                key={mode.key}
                                type="button"
                                onClick={() => change({ mode: mode.key })}
                                className={`px-3 py-1.5 rounded border cursor-pointer ${filter.mode === mode.key ? 'border-eve-primary text-white bg-eve-primary/10' : 'border-white/10 text-eve-muted hover:text-white'}`}
                            >
                                {mode.label}
                            </button>
                        ))}
                    </div>

                    <SelectedGroups filter={filter} groupIndex={groupIndex} onRemove={toggleGroup} />

                    <div className="flex flex-col gap-2">
                        <input
                            type="text"
                            value={search}
                            placeholder="Kategorie suchen, z. B. Hybrid Turrets"
                            onChange={event => setSearch(event.target.value)}
                            className="bg-black/30 border border-white/10 rounded px-2 py-1.5 text-xs text-white focus:border-eve-primary outline-none max-w-md"
                        />
                        <div className="max-h-80 overflow-y-auto border border-white/10 rounded p-2 bg-black/20">
                            {groupsError && <div className="text-red-400">{groupsError}</div>}
                            {!groups && !groupsError && <div className="flex items-center gap-2 text-eve-muted"><Spinner /> Lade Kategorien...</div>}
                            {groups && search.trim() !== '' && (
                                <SearchResults search={search} groupIndex={groupIndex} selectedIds={selectedIds} onToggle={toggleGroup} />
                            )}
                            {groups && search.trim() === '' && (
                                <GroupTree parentId={null} groupIndex={groupIndex} selectedIds={selectedIds} isCovered={false} onToggle={toggleGroup} />
                            )}
                        </div>
                    </div>

                    <HiddenItems filter={filter} onShow={typeId => change({ hiddenTypeIds: hiddenTypeIds.filter(id => id !== typeId) })} />
                </div>
            )}
        </div>
    );
}

function SelectedGroups({ filter, groupIndex, onRemove }: { filter: StationFilterData; groupIndex: GroupIndex; onRemove: (groupId: number) => void }) {
    if (filter.marketGroupIds.length === 0) {
        return <div className="text-eve-muted">Keine Kategorie gewählt, es werden alle Items angezeigt.</div>;
    }

    return (
        <div className="flex flex-col gap-1.5">
            <span className="text-eve-muted">
                {filter.mode === 'except' ? 'Ausgeschlossen' : filter.mode === 'only' ? 'Nur diese' : 'Gewählt (wirkt erst mit einem Modus)'}
            </span>
            <div className="flex flex-wrap gap-1.5">
                {filter.marketGroupIds.map(groupId => (
                    <Chip key={groupId} label={_groupPath(groupId, groupIndex)} tone={filter.mode === 'except' ? 'exclude' : 'include'} onRemove={() => onRemove(groupId)} />
                ))}
            </div>
        </div>
    );
}

function HiddenItems({ filter, onShow }: { filter: StationFilterData; onShow: (typeId: number) => void }) {
    return (
        <div className="flex flex-col gap-1.5">
            <span className="text-eve-muted">Ausgeblendete Items ({filter.hiddenItems.length})</span>
            {filter.hiddenItems.length === 0 ? (
                <span className="text-eve-muted">Keine. Items lassen sich in der Tabelle über den Button „Ausblenden“ ganz rechts ausblenden.</span>
            ) : (
                <div className="flex flex-wrap gap-1.5">
                    {filter.hiddenItems.map(item => (
                        <Chip key={item.typeId} label={item.name} tone="exclude" onRemove={() => onShow(item.typeId)} removeTitle="Wieder einblenden" />
                    ))}
                </div>
            )}
        </div>
    );
}

function GroupTree({ parentId, groupIndex, selectedIds, isCovered, onToggle }: { parentId: number | null; groupIndex: GroupIndex; selectedIds: Set<number>; isCovered: boolean; onToggle: (groupId: number) => void }) {
    const children = groupIndex.childrenByParent.get(parentId) ?? [];

    return (
        <ul className={parentId === null ? '' : 'pl-5'}>
            {children.map(group => (
                <GroupNode key={group.id} group={group} groupIndex={groupIndex} selectedIds={selectedIds} isCovered={isCovered} onToggle={onToggle} />
            ))}
        </ul>
    );
}

function GroupNode({ group, groupIndex, selectedIds, isCovered, onToggle }: { group: MarketGroup; groupIndex: GroupIndex; selectedIds: Set<number>; isCovered: boolean; onToggle: (groupId: number) => void }) {
    const [isExpanded, setIsExpanded] = useState(false);
    const hasChildren = (groupIndex.childrenByParent.get(group.id) ?? []).length > 0;
    const isSelected = selectedIds.has(group.id);

    return (
        <li>
            <div className="flex items-center gap-1.5 py-0.5">
                <button
                    type="button"
                    onClick={() => setIsExpanded(!isExpanded)}
                    className={`w-4 text-eve-primary cursor-pointer ${hasChildren ? '' : 'invisible'}`}
                >
                    {isExpanded ? '▾' : '▸'}
                </button>
                <label className={`flex items-center gap-1.5 ${isCovered ? 'text-eve-muted' : 'text-slate-200 cursor-pointer'}`}>
                    <input type="checkbox" checked={isSelected || isCovered} disabled={isCovered} onChange={() => onToggle(group.id)} className="accent-eve-primary" />
                    {group.name}
                </label>
            </div>
            {isExpanded && hasChildren && (
                <GroupTree parentId={group.id} groupIndex={groupIndex} selectedIds={selectedIds} isCovered={isCovered || isSelected} onToggle={onToggle} />
            )}
        </li>
    );
}

function SearchResults({ search, groupIndex, selectedIds, onToggle }: { search: string; groupIndex: GroupIndex; selectedIds: Set<number>; onToggle: (groupId: number) => void }) {
    const needle = search.trim().toLowerCase();
    const matches: MarketGroup[] = [];
    for (const group of groupIndex.byId.values()) {
        if (group.name.toLowerCase().includes(needle)) {
            matches.push(group);
        }
        if (matches.length >= SEARCH_RESULT_LIMIT) {
            break;
        }
    }

    if (matches.length === 0) {
        return <div className="text-eve-muted">Keine Kategorie gefunden.</div>;
    }

    return (
        <ul>
            {matches.map(group => {
                const isCovered = _hasSelectedAncestor(group.id, selectedIds, groupIndex);
                return (
                    <li key={group.id} className="py-0.5">
                        <label className={`flex items-center gap-1.5 ${isCovered ? 'text-eve-muted' : 'text-slate-200 cursor-pointer'}`}>
                            <input type="checkbox" checked={selectedIds.has(group.id) || isCovered} disabled={isCovered} onChange={() => onToggle(group.id)} className="accent-eve-primary" />
                            {_groupPath(group.id, groupIndex)}
                        </label>
                    </li>
                );
            })}
        </ul>
    );
}

function Chip({ label, tone, onRemove, removeTitle = 'Entfernen' }: { label: string; tone: 'include' | 'exclude'; onRemove: () => void; removeTitle?: string }) {
    const toneClass = tone === 'include' ? 'border-emerald-500/30 text-emerald-300 bg-emerald-500/10' : 'border-rose-500/30 text-rose-300 bg-rose-500/10';
    return (
        <span className={`inline-flex items-center gap-1.5 border rounded px-2 py-0.5 ${toneClass}`}>
            {label}
            <button type="button" onClick={onRemove} title={removeTitle} className="cursor-pointer hover:text-white">×</button>
        </span>
    );
}

function _buildIndex(groups: MarketGroup[]): GroupIndex {
    const byId = new Map<number, MarketGroup>();
    const childrenByParent = new Map<number | null, MarketGroup[]>();
    for (const group of groups) {
        byId.set(group.id, group);
    }
    for (const group of groups) {
        // Groups whose parent is unknown are shown at the top level
        const parentId = group.parentId !== null && byId.has(group.parentId) ? group.parentId : null;
        const siblings = childrenByParent.get(parentId) ?? [];
        siblings.push(group);
        childrenByParent.set(parentId, siblings);
    }
    for (const siblings of childrenByParent.values()) {
        siblings.sort((first, second) => first.name.localeCompare(second.name, 'de'));
    }
    return { byId, childrenByParent };
}

function _groupPath(groupId: number, groupIndex: GroupIndex): string {
    const names: string[] = [];
    let current = groupIndex.byId.get(groupId);
    while (current) {
        names.unshift(current.name);
        current = current.parentId !== null ? groupIndex.byId.get(current.parentId) : undefined;
    }
    return names.length > 0 ? names.join(' › ') : `Gruppe ${groupId}`;
}

function _isDescendant(groupId: number, ancestorId: number, groupIndex: GroupIndex): boolean {
    let current = groupIndex.byId.get(groupId);
    while (current && current.parentId !== null) {
        if (current.parentId === ancestorId) {
            return true;
        }
        current = groupIndex.byId.get(current.parentId);
    }
    return false;
}

function _hasSelectedAncestor(groupId: number, selectedIds: Set<number>, groupIndex: GroupIndex): boolean {
    for (const selectedId of selectedIds) {
        if (_isDescendant(groupId, selectedId, groupIndex)) {
            return true;
        }
    }
    return false;
}

function _summary(filter: StationFilterData, groupIndex: GroupIndex): string {
    const parts: string[] = [];
    if (filter.mode === 'all' || filter.marketGroupIds.length === 0) {
        parts.push('alle Kategorien');
    } else {
        const names: string[] = [];
        for (const groupId of filter.marketGroupIds) {
            names.push(groupIndex.byId.get(groupId)?.name ?? `Gruppe ${groupId}`);
        }
        parts.push(`${filter.mode === 'only' ? 'nur' : 'ohne'} ${names.join(', ')}`);
    }
    if (filter.hiddenItems.length > 0) {
        parts.push(`${filter.hiddenItems.length} Items ausgeblendet`);
    }
    return parts.join(' · ');
}
