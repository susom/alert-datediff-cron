# AlertDateDiffCron
A utility to manage alert datediff jobs

## Goals

- Allow you to log / test execution time for alerts in projects one at a time
- Prevent script overruns
- Prevent skipping jobs when crons fail

Every 6 hours a new 'batch' of crons are initiated via a cron job:


A "Batch" is a run intended to complete all project-alerts.
```
{
    "pid": <<process id>>,
    "start_ts": <<start timestamp>>,
    ""
}

// Object properties
$batch -> the timestamp of the currently initialted


// EM Properties
$hours-between-batches
$alert-email

$queue -> array of tasks to be processed...
$current-batch-process-id
$current-batch
$batch-retries
$batch-count


$batch-info = json string of:
{
    "start_ts": <<start timestamp>>,
    "end_ts": << end timestamp >>,
    "pid": <<>>,
    "failures": [ {
        "pid": <<pid>>,
        "start_ts": <<>>
        "queue_remaining": <<>>
        "failed_alert": <<>>
        "start_queue_length": <<>>,

    }
    "start_pid": <<starting pid>>,
    "additional_pids": [ <<additional pids>> ],

}
