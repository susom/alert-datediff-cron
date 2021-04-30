# AlertDateDiffCron
A utility to manage alert datediff jobs one alert at a time to get
 around the current system cron failing

## Goals

- Allow you to log / test execution time for alerts in projects one at a time
- Prevent script overruns
- Prevent skipping jobs when crons fail


A "Process Batch" is a run intended to complete all project-alerts.

* All alerts with datediff logic are added as an associative array
* This batchInfo is stored as an EM setting and processing begins one
  alert at a time.  After each alert, the remaining 'queue' of alerts
  is saved to the batchInfo until the queue reaches 0 and the batch is
  complete
* Every 15 minutes, a cron job checks on the processing.  If it detects
  a failure, it will reverse the remaining queue alerts and restart.
  This reversal ensures that all alerts but the 'troublesome' one are
  completed.  It will then fall back on the last alert before failure
  and attempt to re-process it.  If it fails again it will retry.
* If the gap between batches (which is an EM config property) has elapsed,
  then it will 'give up' on processing the remaining alerts and send an
  email message to the admin to notify them.


## Behind the scenes
* The current alert being run is stored in the `current-alert` setting.
```
$currentAlert = [
  "alert_id"   => $alert_id,
  "project_id" => $project_id,
  "pid"        => $batchInfo['pid'],
  "start_ts"   => microtime(true)
];
```

* The most recent batch is stored in the 'batch-info' setting.  It is updated
on every alert.  Failures are also tracked inside this object.
```
todo: add example batch-info object
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

    } ],
    "queue": [],
}
```

A Cron is run every 15 minutes - its logic is similar to:
```
Is there a 'current-alert' task that is running?
Yes:
  Is the PID of the task still alive?
  Yes:
    Do nothing
  No:
    Crash detected.  Is there time to restart?
    Yes:
      Restart
    No:
      Skip failed alerts and send email notification
No:
  Is it time for the next batch to begin?
  Yes:
    Start it
  No:
    Sleep
```

$hours-between-batches
$alert-email


/// RESULTS
Message: "Alert Processed"
        $results = [
            'alert_id' => $alert_id,
            'project_id' => $project_id,
            'num_scheduled' => $num_scheduled_total,
            'num_removed' => $num_removed_total,
            'count_affected' => $count_records_affected,
            'duration' => $duration,    // time for this one alert
            'pid'                     // process id
            'pid_duration' =>         // age of pid
            'pid_alert_count' =>      // alert number in this pid
            'batch_start_ts'    // start time for batch
            'batch_duration'    // duration of current batch
            'failure_count'     // number of failures in batch
        ];


Message: "Batch Complete"
  $batchInfo fields


