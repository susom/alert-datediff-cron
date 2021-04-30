<?php
namespace Stanford\AlertDateDiffCron;

require_once "emLoggerTrait.php";

use Alerts;
use BenMorel\GsmCharsetConverter\Packer;
use REDCap;

class AlertDateDiffCron extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $batchInfo;


    public $pid;              // Process ID of the current EM instance
    public $pid_alert_count;  // Number of alerts processed in the current thread
    public $ts_start;        // Time the EM was initialized
    public $Alerts;             // A cache for the Alerts object

    // public $queue;
    // public $retries;
    // public $Alerts;
    // public $lastBatch;
    // public $alertHistory;  // an array with one entry for each alert_id with the most recent completion results

    // private $batch_info;

    // current-batch                The current batch timestamp
    // current-alert
    // current-batch-process-id     The pid of the original batch


    public function __construct() {
		parent::__construct();

		// Other code to run when object is instantiated
        $this->pid = getmypid();
        $this->ts_start = microtime(true);
        $this->pid_alert_count = 1;   // The number of alerts processed in the current pid
    }


    /**
     * Called periodically to see if the last batch has crashed and needs to be resumed
     * @param $cron
     * @return false
     */
    public function cronCheckBatchProcess( $cron ) {
        // $this->emDebug("Cron Fired", $cron);

        # SEE IF PROCESS IS OR WAS RUNNING
        $currentAlert = $this->getSystemSetting("current-alert");
        $batchInfo = $this->getBatchInfo();
        $queue = $batchInfo['queue'];
        $batchPid = $batchInfo['pid'] ?? 0;

        if (empty($currentAlert)) {
            // Empty currentAlert suggests batch cron process is complete

            // Verify queue is empty as it should if there is nothing in the currentAlert
            if (!empty($queue)) {
                // QUEUE is not empty but there is no current alert.  This shouldn't happen
                $this->emError("currentAlert is empty but queue is not.  This shouldn't happen", $batchInfo, "Current PID: " . posix_getpid());
                return false;
            }

            // How long has it been since the last batch to see if we need to start a new batch process
            if (!$this->isTimeForNextBatch()) {
                $this->emDebug("Waiting until next check");
                return true;
            }

            // It is time to start a new batch
            $this->emDebug("Starting new batch");
            $this->startNewBatch();

        } else {
            // currentAlert is not empty indicating a potential crash or still running

            // Check if still running
            if (posix_getpgid($batchPid)) {
                $runTimeInMin = round($this->getRunTime($batchInfo['start_ts']) / 60,0);
                $this->emDebug("Batch pid $batchPid is still running - has been running for $runTimeInMin minutes - exiting");
                return true;
            }

            // Check for empty queue - This really shouldn't happen as the currentAlert should be clear
            if (empty($queue)) {
                $this->emError("The queue is empty even though the currentAlert is not.  This shouldn't happen!  Clearing the currentAlert", $currentAlert);
                $this->setSystemSetting('current-alert','');
                return true;
            }

            // Assume PID is NOT running and we have a crash - we can either restart the previous batch process or start a new one
            $this->emDebug("It appears batch (pid $batchPid) from " . $batchInfo['start_ts'] . " has died on alert " .
                $currentAlert['alert_id'] . " with " . count($queue) . " alerts left to complete.");

            // See if it is time for a 'new' batch or to restart this batch
            if ($this->isTimeForNextBatch()) {
                // Time to start over
                $this->emError("Aborting previous batch and starting over, Skipping the following alerts:", $batchInfo);
                $this->emailAlert("Last batch did not finish.\n\nSkipped remaining Alert Queue:\n<pre>".print_r($batchInfo,true)."</pre>");
                return $this->startNewBatch();
            } else {
                // Time to continue the previous batch
                return $this->restartBatch();
            }
        }
    }


    /**
     * Returns time delta in ms
     * If argument is not present, it uses the instantiation time of the EM in seconds
     * @param $start float Start time in seconds
     * @return float|int
     */
	public function getRunTime($start_ts = null) {
	    if (empty($start_ts)) $start_ts = $this->ts_start ?? 0;
        return (microtime(true) - $start_ts);
    }


    /**
     * Load the batch info from EM settings
     */
    public function getBatchInfo() {
        if (empty($this->batchInfo)) {
            $this->batchInfo = $this->getSystemSetting("batch-info");
        }
        return $this->batchInfo;
    }

    /**
     * Save the batch info
     * @param $batchInfo
     */
    public function setBatchInfo($batchInfo) {
        $this->batchInfo = $batchInfo;
        $this->setSystemSetting("batch-info", $batchInfo);
    }


    /**
     * Determine if it is time for the next batch to be started
     * @return bool
     */
    public function isTimeForNextBatch() {
        // How long has it been since the last batch
        $batchInfo = $this->getBatchInfo();
        $lastBatchStartTs = $batchInfo['start_ts'] ?? 0;

        $hoursBetweenBatches = $this->getSystemSetting("hours-between-batches");
        if(empty($hoursBetweenBatches)) {
            $this->emError("Missing required hours-between-batches");
            REDCap::logEvent($this->getModuleName() . " Error","Missing required hours-between-batches field");
            return false;
        }

        $hoursSinceLastBatch = round( ( $this->ts_start - $lastBatchStartTs ) / 3600, 2);

        $isTime = $hoursSinceLastBatch > $hoursBetweenBatches;
        $this->emDebug("It has been $hoursSinceLastBatch hours since the last batch.",
            "Your configured batch gap is $hoursBetweenBatches hours.",
            "It " . ($isTime ? "IS" : "NOT") . " time for a new batch process" );
        return $isTime;
    }

    /**
     * Start a new batch process
     * @param false $reverseOrder
     */
    public function startNewBatch() {
        # START A NEW BATCH
        $queue = $this->getAlertProjectArray();
        $batchInfo = [
            "start_ts" => $this->ts_start,
            "end_ts" => null,
            "pid" => $this->pid,
            "failures" => [],
            "queue" => $queue,
            "queue_size" => count($queue),
        ];
        $this->setBatchInfo($batchInfo);
        $this->emDebug("Created new batch: {$batchInfo['start_ts']} on process {$batchInfo['pid']} with queue of " . count($queue) . " alerts");

        # START TO PROCESS BATCH QUEUE
        return $this->processQueue();
    }


    /**
     * Restart the batch after a failure
     */
    public function restartBatch() {
        $batchInfo = $this->getBatchInfo();
        $queue = $batchInfo['queue'];
        $currentAlert = $this->getProjectSetting('current-alert');

        $batch_runtime = $this->getRunTime($batchInfo['start_ts']);

        // Lets archive the current batchInfo
        $failure = [
            'pid' => $batchInfo['pid'],
            'restarted_ts' => $this->ts_start,    // When this failure was created
            'batch_runtime' => $batch_runtime,
            'reverse_order' => $batchInfo['reverse_order'],
            'queue_size_remaining' => count($queue),
            'current_alert_failed' => $currentAlert
        ];

        // Let's reverse the queue to try and get others before the one that failed
        if(count($queue) >= 2) $queue = array_reverse($queue,true);

        // Save the updated batch
        $batchInfo['pid'] = $this->pid;
        $batchInfo['failures'][] = $failure;
        $batchInfo['queue'] = $queue;
        $this->setBatchInfo($batchInfo);

        // START TO PROCESS BATCH QUEUE
        $this->emDebug("Restarted batch: {$batchInfo['start_ts']} on process {$batchInfo['pid']} after " . count($batchInfo['failures']) . " failure(s) with queue size of " . count($queue) . " alerts");
        return $this->processQueue();

    }


    public function emailAlert($msg) {
        global $project_contact_email;
        $alert_email = $this->getSystemSetting('alert-email');
        if (empty($alert_email)) return false;
        REDCap::email($alert_email,$project_contact_email,"Alert from " . $this->getModuleName(), $msg);
    }


    // /**
    //  * Start processing of new batch
    //  * batch is named based on timestamp of now
    //  * @param $cron
    //  * @return false
    //  */
	// public function cronInitiateBatch( $cron ) {
    //     $batchInfo = $this->getBatchInfo();
    //
    //     # CHECK IF TIME FOR NEW BATCH
    //
    //     // Get the minimum mins between batches
    //     $hoursBetweenBatches = $this->getSystemSetting("hours-between-batches");
    //     $minBetweenBatches = $hoursBetweenBatches * 60;
    //
    //     // How long has it been since the last batch
    //     $lastBatchStartTs = $batchInfo['start_ts'] ?? 0;
    //     $minSinceLastBatch = ( $this->ts_start - $lastBatchStartTs ) / 60;
    //
    //     // See if we should proceed or abort
    //     if ($minBetweenBatches > $minSinceLastBatch) {
    //         $this->emDebug("$minSinceLastBatch minutes since last batch is less than defined gap of $minBetweenBatches mins so we will skip.");
    //         return false;
    //     }
    //     $this->emDebug("Starting new batch - $minSinceLastBatch minutes since last batch is greater than defined gap of $minBetweenBatches mins");
    //
    //
    //     # CHECK TO SEE IF LAST BATCH COMPLETED SUCCESSFULLY
    //     $queue = $batchInfo['queue'] ?? [];
    //     $order = $batchInfo['order'] ?? "asc";
    //     if (!empty($queue)) {
    //         // Last batch did not complete
    //         $this->emDebug("The last batch did not complete -- the following jobs remain on the queue:", $queue);
    //
    //         // This is not a fatal error - we just email the contact person to let them know.
    //         global $project_contact_email;
    //         REDCap::email($this->getSystemSetting('alert-email'),$project_contact_email,"Error in " . $this->getModuleName(), "Last batch did not finish.\n\nRemaining Alert Queue:\n<pre>".print_r($batchInfo,true)."</pre>");
    //
    //         // reverse the sort order to try and make sure we cover other alerts on this next pass
    //         $order = $order === "asc" ? "desc" : "asc";
    //     }
    //
    //
    //     # START A NEW BATCH
    //     $queue = $this->getAlertProjectArray($order);
    //     $batchInfo = [
    //         "start_ts" => $this->ts_start,
    //         "end_ts" => null,
    //         "pid" => $this->pid,
    //         "failures" => [],
    //         "queue" => $queue,
    //         "queue_size" => count($queue),
    //         "order" => $order
    //     ];
    //
    //     $this->setBatchInfo($batchInfo);
    //     $this->emDebug("Created new batch: {$batchInfo['start_ts']} on process {$batchInfo['pid']} with queue if " . count($queue) . " alerts");
    //
    //
    //     # PROCESS QUEUE
    //     if (empty($queue)) {
    //         $this->emDebug("Nothing in the queue to process");
    //     } else {
    //         // $this->alertHistory = $this->getSystemSetting("alert-history");
    //         // $this->queue = $queue;
    //         $this->processQueue();
    //     }
    // }


    /**
     * Looks at the batch info and processes the queue inside of it
     * Calls itself until the queue is empty
     * @return bool
     */
    public function processQueue() {
	    // Get Queue of alerts to be processed (from cache)
        $batchInfo = $this->getBatchInfo();
        $queue = $batchInfo['queue'];
        $this->emDebug("Starting processQueue with " . count($queue) . " alerts to process");

        if (empty($queue)) {
            $batchInfo['end_ts'] = microtime(true);
            $this->emDebug("Batch Complete", $batchInfo);
            $batchLog = [];
            foreach ($batchInfo as $k => $v) {
                if (is_array($v)) {
                    $batchLog[$k] = json_encode($v);
                } else {
                    $batchLog[$k] = $v;
                }
            }
            $this->log("Batch Complete", $batchLog);
            $this->setBatchInfo($batchInfo);
            return null;
        }

        // Shift off first alert to process from the queue
        reset($queue);
        $alert_id = key($queue);
        $project_id = $queue[$alert_id];
        unset($queue[$alert_id]);

        $currentAlert = [
            "alert_id" => $alert_id,
            "project_id" => $project_id,
            "pid" => $batchInfo['pid'],
            "start_ts" => microtime(true)
        ];

        // Save alert to database in case of timeout/crash
        $this->setSystemSetting("current-alert", $currentAlert);

        // Run the process... this could take a while...
        $this->emDebug("Starting alert $alert_id from project $project_id - " . count($queue) . " remaining");
        $results = $this->checkProjectAlert($project_id, $alert_id);

        // Clear the current alert
        $this->setSystemSetting("current-alert", "");

        // Save the updated batch queue
        $batchInfo['queue'] = $queue;
        $batchInfo['duration'] = $this->getRunTime($batchInfo['start_ts']);
        $this->setBatchInfo($batchInfo);

        // Log Results to EM Logs
        $results = array_merge($results, [
            "pid" => $batchInfo['pid'],
            "pid_duration" => $this->getRunTime(),
            "pid_alert_count" => $this->pid_alert_count++,
            "batch_start_ts" => $batchInfo['start_ts'],
            "batch_duration" => $batchInfo['duration'],
            "failure_count" => count($batchInfo['failures'])
        ]);
        $this->log("Alert Processed", $results);
        // $this->emDebug("Alert Processed", $results);

        // Continue
        return $this->processQueue();
    }


    /**
     * Actually process the alert for notifications one alert at a time
     * @param $project_id
     * @param $alert_id
     * @return array|false
     */
	public function checkProjectAlert($project_id, $alert_id) {
        if (empty($alert_id) || empty($project_id)) {
            $this->emError("Project and Alert ID REQUIRED");
            return false;
        }

        $start_ts = microtime(true);
        $Alerts = empty($this->Alerts) ? new Alerts : $this->Alerts;

        // checkAlertsBulk($project_id=null, $datediffsOnly=false, $alert_ids=array())
        list($num_scheduled_total, $num_removed_total, $count_records_affected) = $Alerts->checkAlertsBulk($project_id, true, $alert_id);

        $duration = $this->getRunTime($start_ts);

        $results = [
            'project_id' => $project_id,
            'alert_id' => $alert_id,
            'num_scheduled' => $num_scheduled_total,
            'num_removed' => $num_removed_total,
            'count_affected' => $count_records_affected,
            'duration' => $duration
        ];

        return $results;
    }


    /**
     * Get a list of all project/alerts with datediff to make the queue
     * @return array
     */
	public function getAlertReport($array_values = false) {
        $datediffsOnly = true;
        $sql1 = $datediffsOnly ? "AND a.form_name is null AND (a.alert_condition like '%datediff%(%today%,%)%' or a.alert_condition like '%datediff%(%now%,%)%')" : "";

        // Get a list of all projects that are using active, time-based conditional logic for automated notifications
        $sql = "SELECT a.* FROM redcap_alerts a, redcap_projects p
				WHERE a.email_deleted = 0 AND p.status <= 1 AND p.date_deleted is null AND p.completed_time is null AND p.project_id = a.project_id
				$sql1
				order by p.project_id desc, a.alert_id";
        $q = db_query($sql);





        // Load the alert history to merge
        $this->alertHistory = $this->getSystemSetting("alert-history");
        $alerts = [];
        while ($row = db_fetch_assoc($q) ) {
            $alertHx = empty($this->alertHistory[$row['alert_id']]) ? null : $this->alertHistory[$row['alert_id']];

            $alert = [
                "project_id" => $row['project_id'],
                "alert_id" => $row['alert_id'],
                "title" => $row['alert_title'],
                "queue_time" => $alertHx['queue_time'] ?? '',
                "batch" => $alertHx['batch'] ?? '',
                "date_time" => $alertHx['date_time'] ?? '',
                'num_scheduled' => $alertHx['num_scheduled'] ?? '',
                'duration' => $alertHx['duration'] ?? ''
            ];
            if (!empty($alertHx)) $this->emDebug("AlertHx", $alertHx);

            $alerts[] = $array_values ? array_values($alert) : $alert;
        }

        return $alerts;
    }


    /**
     * Returns an array with key of alert_id and value of project_id
     * @param $fancy bool Return a json format for html display
     * @return array
     */
    public function getAlertProjectArray($fancy = false) {
        // Copied / Modified from Alerts->checkAlertsBulk
        // Get a list of all projects that are using active, time-based conditional logic for automated notifications
        $sql = "SELECT a.* FROM redcap_alerts a, redcap_projects p
				WHERE a.email_deleted = 0 AND p.status <= 1 AND p.date_deleted is null
				  AND p.completed_time is null AND p.project_id = a.project_id
				  AND a.form_name is null AND (a.alert_condition like '%datediff%(%today%,%)%' or a.alert_condition like '%datediff%(%now%,%)%')
				ORDER BY a.alert_id asc";
        $q = db_query($sql);

        $alerts = [];
        if ($fancy) {
            // Generate output for webpage view
            while ($row = db_fetch_assoc($q) ) {
                $alerts[] = [
                    "project_id" => $row['project_id'],
                    "alert_id" => $row['alert_id'],
                    "title" => $row['alert_title']
                ];
            }
        } else {
            // Generate output for cron
            while ($row = db_fetch_assoc($q) ) {
                $alerts[$row['alert_id']] = $row['project_id'];
            }
        }
        return $alerts;
    }

}
