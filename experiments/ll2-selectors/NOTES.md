# LL(2) selectors (proposal + supporting analysis)

**Origin:** proposal — not implemented. The scripts here measure the premise.

**Idea:** the parser tries multi-candidate branches in order and backtracks on
failure. With 2-token lookahead instead of 1, most multi-candidate rules would
become deterministic, eliminating residual backtracking.

**Run (supporting measurements):**
```
php grammar-stats.php <.../packages/mysql-on-sqlite/src>   # static rule split
php call-split.php                                         # dynamic call split
```

**Result (premise check):**
- 1290/1916 = **67.3%** of rules always resolve to one branch per token.
- 626/1916 = **32.7%** are multi-candidate for at least one token.
- Of ~9.33M `parse_recursive` calls, multi-candidate rules absorb **~51%**.

**Verdict:** the premise holds — a third of rules are multi-candidate yet they take
just over half of all parse calls, so that's where deeper lookahead could help.
Estimated 5–15% gain at high engineering cost (LL(*)/ALL(*)-style static
analysis). Not prototyped.
