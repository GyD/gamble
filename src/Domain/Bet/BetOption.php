<?php

declare(strict_types=1);

namespace App\Domain\Bet;

final readonly class BetOption
{
    /**
     * @param float|null $odds odds priced by the bookmaker, null while unpriced
     * @param float|null $offeredOdds odds actually offered to the next stake,
     *        the priced odds once drifted; null while unpriced
     * @param float|null $finalOdds odds recorded at settlement
     */
    public function __construct(
        public int $id,
        public string $label,
        public int $position,
        public ?float $odds = null,
        public ?float $offeredOdds = null,
        public ?float $finalOdds = null,
    ) {
    }

    public function isPriced(): bool
    {
        return $this->odds !== null;
    }

    public function withOfferedOdds(?float $offeredOdds): self
    {
        return new self($this->id, $this->label, $this->position, $this->odds, $offeredOdds, $this->finalOdds);
    }
}
