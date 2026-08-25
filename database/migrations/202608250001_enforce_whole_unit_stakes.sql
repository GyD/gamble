UPDATE stakes
SET amount_cents = GREATEST(1, FLOOR((amount_cents + 50) / 100));

CREATE TEMPORARY TABLE settled_financials AS
SELECT bets.id AS bet_id,
       COALESCE(SUM(CASE
           WHEN stakes.is_paid = TRUE AND stakes.is_cancelled = FALSE THEN stakes.amount_cents
           ELSE 0
       END), 0) AS pot,
       COALESCE(SUM(CASE
           WHEN stakes.is_paid = TRUE
               AND stakes.is_cancelled = FALSE
               AND stakes.bet_option_id = bets.winning_option_id THEN stakes.amount_cents
           ELSE 0
       END), 0) AS winning_stake,
       bets.bookmaker_rate_bps
FROM bets
LEFT JOIN stakes ON stakes.bet_id = bets.id
WHERE bets.status = 'settled'
GROUP BY bets.id, bets.winning_option_id, bets.bookmaker_rate_bps;

ALTER TABLE settled_financials
    ADD PRIMARY KEY (bet_id),
    ADD COLUMN bookmaker_share BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN redistributed BIGINT UNSIGNED NOT NULL DEFAULT 0;

UPDATE settled_financials
SET bookmaker_share = CASE
        WHEN winning_stake = 0 THEN pot
        ELSE LEAST(
            FLOOR(((pot * bookmaker_rate_bps) + 5000) / 10000),
            pot - winning_stake
        )
    END;

UPDATE settled_financials
SET redistributed = pot - bookmaker_share;

UPDATE bets
INNER JOIN settled_financials ON settled_financials.bet_id = bets.id
SET bets.final_pot_cents = settled_financials.pot,
    bets.final_bookmaker_share_cents = settled_financials.bookmaker_share,
    bets.final_redistributed_cents = settled_financials.redistributed;

UPDATE bet_options
INNER JOIN settled_financials ON settled_financials.bet_id = bet_options.bet_id
LEFT JOIN (
    SELECT stakes.bet_id, stakes.bet_option_id, SUM(stakes.amount_cents) AS option_stake
    FROM stakes
    WHERE stakes.is_paid = TRUE AND stakes.is_cancelled = FALSE
    GROUP BY stakes.bet_id, stakes.bet_option_id
) option_stakes ON option_stakes.bet_id = bet_options.bet_id
    AND option_stakes.bet_option_id = bet_options.id
SET bet_options.final_odds = CASE
    WHEN COALESCE(option_stakes.option_stake, 0) = 0 THEN NULL
    ELSE (
        settled_financials.pot - LEAST(
            FLOOR(((settled_financials.pot * settled_financials.bookmaker_rate_bps) + 5000) / 10000),
            settled_financials.pot - option_stakes.option_stake
        )
    ) / option_stakes.option_stake
END;

UPDATE stakes
INNER JOIN settled_financials ON settled_financials.bet_id = stakes.bet_id
SET stakes.final_payout_cents = NULL;

CREATE TEMPORARY TABLE settled_winner_payouts AS
SELECT ranked.id,
       ranked.base_payout + IF(
           ranked.remainder_rank <= ranked.redistributed - ranked.allocated,
           1,
           0
       ) AS payout
FROM (
    SELECT payouts.id,
           payouts.bet_id,
           payouts.redistributed,
           payouts.base_payout,
           SUM(payouts.base_payout) OVER (PARTITION BY payouts.bet_id) AS allocated,
           ROW_NUMBER() OVER (
               PARTITION BY payouts.bet_id
               ORDER BY payouts.payout_remainder DESC, payouts.id ASC
           ) AS remainder_rank
    FROM (
        SELECT stakes.id,
               stakes.bet_id,
               settled_financials.redistributed,
               FLOOR(
                   (settled_financials.redistributed * stakes.amount_cents)
                   / settled_financials.winning_stake
               ) AS base_payout,
               MOD(
                   settled_financials.redistributed * stakes.amount_cents,
                   settled_financials.winning_stake
               ) AS payout_remainder
        FROM stakes
        INNER JOIN bets ON bets.id = stakes.bet_id
        INNER JOIN settled_financials ON settled_financials.bet_id = stakes.bet_id
        WHERE stakes.is_paid = TRUE
          AND stakes.is_cancelled = FALSE
          AND stakes.bet_option_id = bets.winning_option_id
          AND settled_financials.winning_stake > 0
    ) payouts
) ranked;

ALTER TABLE settled_winner_payouts ADD PRIMARY KEY (id);

UPDATE stakes
INNER JOIN settled_winner_payouts ON settled_winner_payouts.id = stakes.id
SET stakes.final_payout_cents = settled_winner_payouts.payout;

DROP TEMPORARY TABLE settled_winner_payouts;
DROP TEMPORARY TABLE settled_financials;

ALTER TABLE stakes
    DROP CONSTRAINT stakes_amount_check,
    CHANGE COLUMN amount_cents amount BIGINT UNSIGNED NOT NULL,
    CHANGE COLUMN final_payout_cents final_payout BIGINT UNSIGNED NULL,
    ADD CONSTRAINT stakes_amount_check CHECK (amount BETWEEN 1 AND 999999);

ALTER TABLE bets
    CHANGE COLUMN final_pot_cents final_pot BIGINT UNSIGNED NULL,
    CHANGE COLUMN final_bookmaker_share_cents final_bookmaker_share BIGINT UNSIGNED NULL,
    CHANGE COLUMN final_redistributed_cents final_redistributed BIGINT UNSIGNED NULL;
