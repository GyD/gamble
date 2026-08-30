<?php

declare(strict_types=1);

namespace App\Domain\Bet;

final readonly class BetOption
{
    public function __construct(
        public int $id,
        public string $label,
        public int $position,
        public ?float $odds = null,
        public ?float $initialProbability = null,
        public ?float $currentProbability = null,
    ) {
    }

    public function withOdds(?float $odds): self
    {
        return new self($this->id, $this->label, $this->position, $odds, $this->initialProbability, $this->currentProbability);
    }
}