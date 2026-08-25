ALTER TABLE bets
    ADD COLUMN bookmaker_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 1000 AFTER winning_option_id,
    ADD COLUMN final_pot_cents BIGINT UNSIGNED NULL AFTER bookmaker_rate_bps,
    ADD COLUMN final_bookmaker_share_cents BIGINT UNSIGNED NULL AFTER final_pot_cents,
    ADD COLUMN final_redistributed_cents BIGINT UNSIGNED NULL AFTER final_bookmaker_share_cents,
    ADD CONSTRAINT bets_bookmaker_rate_check CHECK (bookmaker_rate_bps <= 2500);

ALTER TABLE bet_options
    ADD COLUMN final_odds DECIMAL(25, 6) NULL AFTER position;

ALTER TABLE stakes
    ADD COLUMN final_payout_cents BIGINT UNSIGNED NULL AFTER amount_cents;