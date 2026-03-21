---
title: Reporting And Alerts
---

# Reporting And Alerts

## Core Reporting Services

The package exposes service classes for report pages and saved reports:

- `SignalsDashboardService`
- `PageViewReportService`
- `ConversionFunnelReportService`
- `AcquisitionReportService`
- `JourneyReportService`
- `RetentionReportService`
- `ContentPerformanceReportService`
- `LiveActivityReportService`
- `GoalsReportService`

These services work on top of `SignalEvent`, `SignalSession`, and `SignalDailyMetric`.

## Saved Reports

`SavedSignalReport` stores reusable report definitions (filters, dimensions, and report-specific settings).

Typical use cases:

- Save commonly used date ranges and tracked property filters
- Persist funnel or journey breakdown settings
- Share report definitions across admin users

## Goals and Segments

- `SignalGoal` defines measurable outcomes
- `SignalSegment` defines audience filters
- Segment/report helpers are implemented in services such as `SignalSegmentReportFilter`

## Alert Lifecycle

1. `SignalAlertRule` defines metric, condition, threshold, and cooldown.
2. `SignalAlertEvaluator` checks current metric values.
3. `SignalAlertDispatcher` writes `SignalAlertLog` records and dispatches notifications.
4. `signals:process-alerts` orchestrates evaluation/dispatch.

## Owner Scoping

When owner mode is enabled, report queries and command execution are scoped per owner context.
