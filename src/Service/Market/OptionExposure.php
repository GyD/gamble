<?php

declare(strict_types=1);

namespace App\Service\Market;

/** What the bookmaker collected and what they owe if a given option wins. */
final readonly class OptionExposure
{
    public function __construct(
        public int $optionId,
        public string $label,
        public ?float $odds,
        public ?float $offeredOdds,
        /** Amount collected on the option, paid stakes only. */
        public int $paidStake,
        /** Amount pledged on the option but not collected yet. */
        public int $unpaidStake,
        /** Amount owed to the paid stakes of the option if it wins, at their frozen odds. */
        public int $paidPayout,
        /**
         * Amount the unpaid stakes of the option would be worth at the odds
         * currently offered: a projection, never a debt already contracted.
         */
        public int $unpaidPayout,
    ) {
    }

    public function totalStake(): int
    {
        return $this->paidStake + $this->unpaidStake;
    }

    public function totalPayout(): int
    {
        return $this->paidPayout + $this->unpaidPayout;
    }
}
