<?php

namespace App\Service\Esi;

/**
 * Thrown when the character's token lacks the scope required by an ESI endpoint (ESI answers 401).
 */
class EsiMissingScopeException extends \RuntimeException
{
    // Part of every message; lets log consumers recognize this expected condition
    public const MESSAGE_MARKER = 'lacks the required ESI scope';

    public function __construct(
        private readonly string $path,
        private readonly string $characterName,
        private readonly ?string $requiredScope = null
    ) {
        parent::__construct(sprintf(
            'Character %s %s%s for %s.',
            $characterName,
            self::MESSAGE_MARKER,
            $requiredScope ? ' ' . $requiredScope : '',
            $path
        ));
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getCharacterName(): string
    {
        return $this->characterName;
    }

    public function getRequiredScope(): ?string
    {
        return $this->requiredScope;
    }
}
