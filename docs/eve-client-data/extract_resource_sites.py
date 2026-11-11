"""Extracts all ore, ice and gas sites with amounts from the Phobos dungeons dump."""
import collections
import io
import json
import pickle
import sqlite3
import sys

dump_path, loc_dir, sde_path, out_path, client_build = sys.argv[1:6]

RESOURCE_ARCHETYPES = {27, 28, 81}  # Ore Anomalies, Ice Belts, Hidden Belts
ASTEROID_CATEGORY = 25
GAS_CLOUD_GROUP = 711


class _LockedUnpickler(pickle.Unpickler):
    def find_class(self, module, name):
        raise pickle.UnpicklingError(f'blocked global {module}.{name}')


def _load_messages(path):
    with open(path, 'rb') as handle:
        _language, messages = _LockedUnpickler(io.BytesIO(handle.read()), encoding='utf-8').load()
    return messages


def _text(messages, message_id):
    entry = messages.get(message_id)
    if entry is None:
        return None
    text = entry[0] if isinstance(entry, (tuple, list)) else entry
    return text.strip() if isinstance(text, str) else None


dungeons = json.load(open(dump_path))
messages_en = _load_messages(f'{loc_dir}/en-us.pickle')
messages_de = _load_messages(f'{loc_dir}/de.pickle')

database = sqlite3.connect(sde_path)
types = {}
for type_id, type_name, volume, group_id in database.execute('SELECT typeID, typeName, volume, groupID FROM invTypes'):
    types[type_id] = (type_name, volume, group_id)
group_categories = dict(database.execute('SELECT groupID, categoryID FROM invGroups'))
archetype_names = dict(database.execute('SELECT archetypeID, title FROM dungeonArchetypes'))
faction_names = dict(database.execute('SELECT factionID, factionName FROM chrFactions'))
compressed_type_ids = dict(database.execute('SELECT typeID, compressedTypeID FROM compressibleTypes'))

sites = []
for dungeon_id, dungeon in dungeons.items():
    asteroid_counts = collections.Counter()
    unit_totals = collections.Counter()
    resource_kinds = set()
    for room in dungeon.get('rooms', {}).values():
        for game_object in room.get('objects', {}).values():
            type_info = types.get(game_object['typeID'])
            if type_info is None or game_object.get('radius') is None:
                continue
            group_id = type_info[2]
            if group_id == GAS_CLOUD_GROUP:
                resource_kinds.add('gas')
            elif group_categories.get(group_id) == ASTEROID_CATEGORY:
                resource_kinds.add('ore')
            else:
                continue
            asteroid_counts[game_object['typeID']] += 1
            unit_totals[game_object['typeID']] += game_object['radius']

    archetype_id = dungeon.get('archetypeID')
    # Asteroids inside combat sites are decoration, keep only resource sites and any site with gas clouds
    if not resource_kinds or (archetype_id not in RESOURCE_ARCHETYPES and 'gas' not in resource_kinds):
        continue

    resources = []
    for type_id in sorted(asteroid_counts):
        type_name, volume, _group_id = types[type_id]
        units = int(round(unit_totals[type_id]))
        resources.append({
            'typeId': type_id,
            'name': type_name,
            'count': asteroid_counts[type_id],
            'units': units,
            'volumeM3': int(round(units * (volume or 0))),
            'compressedTypeId': compressed_type_ids.get(type_id),
        })

    name_id = dungeon.get('dungeonNameID')
    sites.append({
        'dungeonId': int(dungeon_id),
        'name': _text(messages_en, name_id),
        'nameDe': _text(messages_de, name_id),
        'archetypeId': archetype_id,
        'archetype': archetype_names.get(archetype_id),
        'factionId': dungeon.get('factionID'),
        'faction': faction_names.get(dungeon.get('factionID')),
        'kind': '+'.join(sorted(resource_kinds)),
        'resources': resources,
    })

sites.sort(key=lambda site: (site['kind'], site['archetypeId'] or 0, site['name'] or '', site['dungeonId']))
result = {
    'source': 'EVE client res:/staticdata/dungeons.fsdbinary via Phobos; object radius = units per asteroid/cloud',
    'clientBuild': int(client_build),
    'sites': sites,
}
with open(out_path, 'w', encoding='utf-8') as handle:
    handle.write(json.dumps(result, indent=2, ensure_ascii=False))

kind_counts = collections.Counter((site['kind'], site['archetype']) for site in sites)
for key, count in sorted(kind_counts.items(), key=str):
    print(key, count)
print('unnamed:', sum(1 for site in sites if not site['name']))
