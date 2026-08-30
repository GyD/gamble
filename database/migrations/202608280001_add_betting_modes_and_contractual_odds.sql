ALTER TABLE bets
    ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT 'fixed_odds' AFTER winning_option_id,
    ADD COLUMN odds_evolution_mode VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER mode,
    ADD COLUMN mutuel_commission_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 1000 AFTER bookmaker_rate_bps,
    ADD CONSTRAINT bets_mode_check CHECK (mode IN ('fixed_odds', 'pari_mutuel')),
    ADD CONSTRAINT bets_odds_evolution_mode_check CHECK (odds_evolution_mode IN ('fixed', 'dynamic_low', 'dynamic_normal', 'dynamic_high')),
    ADD CONSTRAINT bets_mutuel_commission_rate_check CHECK (mutuel_commission_rate_bps <= 2500);

ALTER TABLE bet_options
    ADD COLUMN initial_probability DECIMAL(9, 8) NULL AFTER position,
    ADD COLUMN current_probability DECIMAL(9, 8) NULL AFTER initial_probability;

UPDATE bet_options
INNER JOIN (
    SELECT bet_id, COUNT(*) AS option_count
    FROM bet_options
    GROUP BY bet_id
) option_counts ON option_counts.bet_id = bet_options.bet_id
SET bet_options.initial_probability = 1.0 / option_counts.option_count,
    bet_options.current_probability = 1.0 / option_counts.option_count;

ALTER TABLE bet_options
    MODIFY initial_probability DECIMAL(9, 8) NOT NULL,
    MODIFY current_probability DECIMAL(9, 8) NOT NULL,
    ADD CONSTRAINT bet_options_initial_probability_check CHECK (initial_probability > 0 AND initial_probability < 1),
    ADD CONSTRAINT bet_options_current_probability_check CHECK (current_probability > 0 AND current_probability < 1);

ALTER TABLE stakes
    ADD COLUMN quoted_odds DECIMAL(25, 6) NULL AFTER amount,
    ADD COLUMN odds_at_bet DECIMAL(25, 6) NULL AFTER quoted_odds;