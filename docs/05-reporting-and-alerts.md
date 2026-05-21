---
title: Reporting And Alerts
---

# Reporting And Alerts

Signals owns generic analytics and alerting for the Commerce packages.

## Alert lifecycle

1. `SignalEvent` records event name, category, owner, tracked property, revenue, and allowlisted properties.
2. `SignalAlertRule` defines metric, operator, threshold, timeframe, cooldown, event filters, channels, and destination keys.
3. `SignalAlertEvaluator` evaluates the rule against matching events.
4. `SignalAlertDispatcher` writes `SignalAlertLog` records and dispatches configured channels.
5. `signals:process-alerts` runs scheduled evaluation.

## Generic filters

Alert rules can filter by:

- event names,
- event categories,
- tracked property,
- event property conditions.

This is intentionally package-agnostic: cart, checkout, orders, vouchers, affiliates, and future packages can all use the same rule engine.

## Channels

Supported dispatch channels:

- database,
- email,
- webhook,
- Slack-compatible webhook.

Named destinations from config are preferred. Inline destinations are ignored unless explicitly enabled.

## Idempotency

`SignalEvent` supports an idempotency/source-event key unique per tracked property. Use it for listener retries and backfills.

## Owner scoping

Report, alert, and command paths are owner-scoped. Global rows require explicit global context for mutation.
