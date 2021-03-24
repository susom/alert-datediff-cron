# AlertDateDiffCron
A utility to manage alert datediff jobs

## Goals

- Allow you to log / test execution time for alerts in projects one at a time
- Prevent script overruns
- Prevent skipping jobs when crons fail

Every 6 hours a new 'batch' of crons are initiated via a cron job:
