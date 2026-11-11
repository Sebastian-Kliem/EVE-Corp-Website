# Probe scanner samples

Test input for the signature calculator on the wormhole sites page. Each file mimics a copy from the probe scanner (Ctrl+A, Ctrl+C) of a German client: tab separated columns ID, type, group, name, signal strength, distance.

Open the file in an editor and copy from there; copying from a terminal or chat usually turns tabs into spaces.

| File | Content |
|------|---------|
| `c3-realistic.txt` | Typical C3 scan with combat, relic, data, gas and wormholes. `Wilder Drohnenfertiger`, `Zerstörtes Blood Raider-Denkmal` and the wormholes have no German name in the seed and show up as unknown. |
| `all-categories.txt` | Several sites per category (combat, data, relic, gas, ore) across C1 to C6. |
| `edge-cases.txt` | Unscanned signatures, group without name, unknown name, duplicate ID, English site name, lower-case ID and junk lines. |
| `spaces-instead-of-tabs.txt` | Lines whose tabs were replaced by spaces, including an unscanned one. |

The group names `Datengebiet` and `Erzgebiet` are guesses; the calculator only matches the site name.
