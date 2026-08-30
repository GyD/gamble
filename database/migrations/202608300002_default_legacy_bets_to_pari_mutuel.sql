-- Legacy bets predate the betting mode concept. Their historical settlement
-- (BetFinancialCalculator, removed by this feature) levied a commission on the
-- pot and split the net pool proportionally between the winning stakes: that is
-- pari mutuel behaviour, not fixed odds.
--
-- Leaving them in fixed_odds would refund every winner at odds 1.00, because
-- their already paid stakes have no contractual odds_at_bet and the fixed odds
-- market refunds the stakes it cannot price. The bookmaker would silently keep
-- the whole margin between the pot and the refunded stakes.
--
-- Legacy bets are identified by the absence of any initial probability: a bet
-- created in fixed_odds after this feature always carries them. Settled bets
-- are excluded, their financial state is frozen.
--
-- The bookmaker margin is copied into the mutuel commission before the mode
-- changes, so a converted bet keeps the rate that was actually agreed instead
-- of silently falling back to the default one.
UPDATE bets
SET mutuel_commission_rate_bps = bookmaker_rate_bps,
    betting_mode = 'pari_mutuel'
WHERE betting_mode = 'fixed_odds'
  AND status <> 'settled'
  AND NOT EXISTS (
      SELECT 1
      FROM bet_options o
      WHERE o.bet_id = bets.id
        AND o.initial_probability IS NOT NULL
  );
