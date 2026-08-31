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
        /** Amount owed to the paid stakes of the option if it wins. */
        public int $paidPayout,
        /** Amount that would also be owed if every unpaid stake gets paid. */
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
