<?php

namespace App\Service\Fitting;

/**
 * Splits an EFT text block (in-game export, Pyfa) into header and item lines without SDE lookups.
 */
class EftParser
{
    /**
     * @return array{shipName: ?string, name: ?string, lines: list<array{name: string, quantity: int, hasQuantity: bool, charge: ?string}>}
     */
    public function parse(string $eft): array
    {
        $rawLines = preg_split('/\r\n|\r|\n/', trim($eft)) ?: [];
        $header = $this->_parseHeader(array_shift($rawLines) ?? '');

        $lines = [];
        foreach ($rawLines as $rawLine) {
            $line = $this->_parseItemLine($rawLine);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return [
            'shipName' => $header['shipName'],
            'name' => $header['name'],
            'lines' => $lines,
        ];
    }

    /**
     * @return array{shipName: ?string, name: ?string}
     */
    private function _parseHeader(string $headerLine): array
    {
        if (preg_match('/^\s*\[\s*([^,\]]+?)\s*,\s*(.*?)\s*\]\s*$/', $headerLine, $matches)) {
            return ['shipName' => $matches[1], 'name' => $matches[2] !== '' ? $matches[2] : null];
        }
        if (preg_match('/^\s*\[\s*([^\]]+?)\s*\]\s*$/', $headerLine, $matches)) {
            return ['shipName' => $matches[1], 'name' => null];
        }

        return ['shipName' => null, 'name' => null];
    }

    /**
     * @return array{name: string, quantity: int, hasQuantity: bool, charge: ?string}|null
     */
    private function _parseItemLine(string $rawLine): ?array
    {
        $line = trim($rawLine);
        // Empty slot placeholders look like "[Empty High slot]"
        if ($line === '' || str_starts_with($line, '[')) {
            return null;
        }

        $line = trim((string) preg_replace('/\s*\/\s*offline\s*$/i', '', $line));

        $quantity = 1;
        $hasQuantity = false;
        if (preg_match('/^(.*?)\s+x(\d+)$/i', $line, $matches)) {
            $line = trim($matches[1]);
            $quantity = max(1, (int) $matches[2]);
            $hasQuantity = true;
        }

        $charge = null;
        $commaPosition = strrpos($line, ',');
        if ($commaPosition !== false && !$hasQuantity) {
            $charge = trim(substr($line, $commaPosition + 1));
            $line = trim(substr($line, 0, $commaPosition));
        }

        if ($line === '') {
            return null;
        }

        return [
            'name' => $line,
            'quantity' => $quantity,
            'hasQuantity' => $hasQuantity,
            'charge' => $charge !== '' ? $charge : null,
        ];
    }
}
