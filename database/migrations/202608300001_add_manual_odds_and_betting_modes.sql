-- Betting modes, manually priced odds and per-stake frozen odds.
--
-- Fixed odds bets are priced by the bookmaker: the odds of each option are
-- typed by hand, no probability is ever stored. The bookmaker margin is derived
-- from the odds themselves (sum of their inverses minus one), so it is a
-- display value and no longer a stored input for fixed odds bets.

ALTER TABLE bets
    ADD COLUMN betting_mode VARCHAR(20) NOT NULL DEFAULT 'fixed_odds' AFTER status,
    ADD COLUMN odds_evolution_mode VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER betting_mode,
    -- Instant the offered odds were last priced by hand. Only the stakes placed
    -- after it feed the drift, so correcting the odds restarts the drift from
    -- the freshly published prices.
    ADD COLUMN odds_anchored_at DATETIME NULL AFTER odds_evolution_mode,
    ADD COLUMN mutuel_commission_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 1000 AFTER bookmaker_rate_bps,
    ADD COLUMN final_bookmaker_result BIGINT NULL AFTER final_bookmaker_share,
    ADD CONSTRAINT bets_betting_mode_check CHECK (betting_mode IN ('fixed_odds', 'pari_mutuel')),
    ADD CONSTRAINT bets_odds_evolution_mode_check
        CHECK (odds_evolution_mode IN ('fixed', 'dynamic_low', 'dynamic_normal', 'dynamic_high')),
    ADD CONSTRAINT bets_mutuel_commission_rate_check CHECK (mutuel_commission_rate_bps <= 2500);

-- The signed bookmaker result is always reconstructed from pot - redistributed,
-- never from the historical bookmaker share.
UPDATE bets
SET final_bookmaker_result = CAST(final_pot AS SIGNED) - CAST(final_redistributed AS SIGNED)
WHERE status = 'settled'
  AND final_pot IS NOT NULL
  AND final_redistributed IS NOT NULL;

-- Bets created before this migration predate the betting mode concept. Their
-- historical settlement levied a commission on the pot and split the net pool
-- proportionally between the winning stakes: that is pari mutuel behaviour, not
-- fixed odds. Leaving them in fixed odds would refund every winner at odds
-- 1.00, since they carry no priced odds at all. Settled bets are excluded,
-- their financial state is frozen. The margin that was actually agreed becomes
-- the mutuel commission instead of falling back to the default rate.
UPDATE bets
SET mutuel_commission_rate_bps = bookmaker_rate_bps,
    betting_mode = 'pari_mutuel'
WHERE status <> 'settled';

-- The fixed odds margin is no longer an input: it is read from the odds.
ALTER TABLE bets
    DROP CONSTRAINT bets_bookmaker_rate_check;

ALTER TABLE bets
    DROP COLUMN bookmaker_rate_bps;

-- Odds offered on an option, priced by the bookmaker. They stay NULL until the
-- option is priced, and an unpriced option accepts no stake.
ALTER TABLE bet_options
    ADD COLUMN odds DECIMAL(10, 4) NULL AFTER position,
    ADD CONSTRAINT bet_options_odds_check CHECK (odds IS NULL OR odds >= 1.01);

-- Odds frozen when the stake was created: they are the contract passed with the
-- bettor and are never recomputed. Legacy stakes have none, and the settlement
-- refunds them instead of silently multiplying them.
ALTER TABLE stakes
    ADD COLUMN odds_at_bet DECIMAL(10, 4) NULL AFTER amount,
    ADD CONSTRAINT stakes_odds_at_bet_check CHECK (odds_at_bet IS NULL OR odds_at_bet >= 1);
