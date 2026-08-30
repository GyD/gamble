-- Betting modes, market probabilities and per-stake odds.
--
-- Bets created before this migration predate the betting mode concept. They are
-- given the fixed_odds default here only because the column requires one, and
-- 202608300002 immediately converts them to pari_mutuel, which is the mode
-- matching their historical settlement.

ALTER TABLE bets
    ADD COLUMN betting_mode VARCHAR(20) NOT NULL DEFAULT 'fixed_odds' AFTER status,
    ADD COLUMN odds_evolution_mode VARCHAR(20) NOT NULL DEFAULT 'dynamic_normal' AFTER betting_mode,
    ADD COLUMN mutuel_commission_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 1000 AFTER bookmaker_rate_bps,
    ADD COLUMN final_bookmaker_result BIGINT NULL AFTER final_bookmaker_share,
    ADD CONSTRAINT bets_betting_mode_check CHECK (betting_mode IN ('fixed_odds', 'pari_mutuel')),
    ADD CONSTRAINT bets_odds_evolution_mode_check
        CHECK (odds_evolution_mode IN ('fixed', 'dynamic_low', 'dynamic_normal', 'dynamic_high')),
    ADD CONSTRAINT bets_mutuel_commission_rate_check CHECK (mutuel_commission_rate_bps <= 2500);

-- Placeholder mode for existing rows, superseded by 202608300002.
UPDATE bets
SET betting_mode = 'fixed_odds',
    odds_evolution_mode = 'dynamic_normal';

-- The signed bookmaker result is always reconstructed from pot - redistributed,
-- never from the historical bookmaker share.
UPDATE bets
SET final_bookmaker_result = CAST(final_pot AS SIGNED) - CAST(final_redistributed AS SIGNED)
WHERE status = 'settled'
  AND final_pot IS NOT NULL
  AND final_redistributed IS NOT NULL;

-- Probabilities of legacy options cannot be reconstructed: they stay NULL and
-- the market services fall back to equiprobable options.
ALTER TABLE bet_options
    ADD COLUMN initial_probability DECIMAL(9, 8) NULL AFTER position,
    ADD COLUMN current_probability DECIMAL(9, 8) NULL AFTER initial_probability,
    ADD CONSTRAINT bet_options_initial_probability_check
        CHECK (initial_probability IS NULL OR (initial_probability > 0 AND initial_probability <= 1)),
    ADD CONSTRAINT bet_options_current_probability_check
        CHECK (current_probability IS NULL OR (current_probability > 0 AND current_probability <= 1));

-- Odds of legacy stakes cannot be reconstructed either: they stay NULL and
-- settlement falls back to refunding the stake.
ALTER TABLE stakes
    ADD COLUMN quoted_odds DECIMAL(10, 4) NULL AFTER amount,
    ADD COLUMN odds_at_bet DECIMAL(10, 4) NULL AFTER quoted_odds,
    ADD CONSTRAINT stakes_quoted_odds_check CHECK (quoted_odds IS NULL OR quoted_odds >= 1),
    ADD CONSTRAINT stakes_odds_at_bet_check CHECK (odds_at_bet IS NULL OR odds_at_bet >= 1);
