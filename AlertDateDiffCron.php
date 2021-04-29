<?php
namespace Stanford\AlertDateDiffCron;

require_once "emLoggerTrait.php";

use Alerts;
use REDCap;

class AlertDateDiffCron extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $batch;
    public $processId;
    public $queue;
    public $retries;
    public $Alerts;
    public $ts_start;
    public $lastBatch;
    public $alertHistory;  // an array with one entry for each alert_id with the most recent completion results

    private $batch_info;

    // current-batch                The current batch timestamp
    // current-alert
    // current-batch-process-id     The pid of the original batch


    public function __construct() {
		parent::__construct();

		// Other code to run when object is instantiated
        $this->processId = getmypid();
        $this->ts_start = microtime(true);
    }


    /**
     * Get EM runtime based on ts_start
     * @return float|int
     */
	public function getRunTime() {
        return (microtime(true) - $this->ts_start) * 1000;
    }


    /**
     * Load the batch info
     */
    public function getBatchInfo() {
	    $this->batch_info = $this->getSystemSetting("batch-info");

    }


    /**
     * Called periodically to see if the cron has crashed
     * @param $cron
     * @return false
     */
	public function cronCheckQueue( $cron ) {
        // $this->emDebug("Cron Fired", $cron);

        // Get PID for last running batch
        $lastBatch = $this->getSystemSetting("current-batch");
        $lastProcessId = $this->getSystemSetting("current-batch-process-id");

        // Get the last running alert
        $currentAlert = $this->getSystemSetting("current-alert");
        if (empty($currentAlert)) {
            $this->emDebug("cronCheck - Looks like there are no alerts running - stopping");
            return false;
        }

        // Get Queue for last running batch
        $this->queue = $this->getSystemSetting("queue");
        if (empty($this->queue)) {
            // There is nothing to do here - the queue is empty
            $this->emDebug("cronCheck - empty queue - stopping");
            return false;
        }

        // Check if the last batch is still running
        if (posix_getpgid($lastProcessId)) {
            $this->emDebug("Process $lastProcessId from batch $lastBatch is still running...");
            return false;
        }

        // Appears that the previous job died and maybe left stuff running.
        $this->emDebug("It appears batch $lastBatch (process $lastProcessId) died while running.  " .
            "Check error logs.  Let's skip the last job and try to resume", $currentAlert, $this->queue);

        // See if there is time to resume
        $hoursBetweenBatches = $this->getSystemSetting('hours-between-batches');
        $nextBatch = $lastBatch + $hoursBetweenBatches * 3600;  // ts of when next batch should cron-start
        $now = strtotime("now");
        if ( ($now + $cron['cron_max_run_time']) > $nextBatch) {
            // We do not have enough time to run
            $this->emDebug("Given that it is now $now and this cron could run for " . $cron['cron_max_run_time'] .
                " and the next cron is scheduled at " . $nextBatch . " we do not have enough time to retry");
            return false;
        }

        // Check number of retries
        $this->retries = $this->getSystemSetting("batch-retries");
        // $maxRetries = $this->getSystemSetting("max-retries");
        // $maxRetries = empty($maxRetries) ? 2 : $maxRetries;
        // if ($retries >= $maxRetries) {
        //     $this->emDebug("Exceeded maximum number of retries: $retries - aborting...");
        //     return false;
        // }
        $this->retries++;
        $this->setSystemSetting("retries", $this->retries);

        // Let's shift the first member to the end of the array in case this was a failing one to let the others continue...
        if(count($this->queue) >= 2) {
            $first = array_shift($this->queue);
            $this->queue[] = $first;
            $this->setSystemSetting("queue",$this->queue);
            $this->emDebug("Queue rotated");
        }


        // Load the alert history
        $this->alertHistory = $this->getSystemSetting("alert-history");

        // Update the PID to the current one and set the queue
        $this->setSystemSetting("current-batch-process-id", $this->processId);

        $this->processQueue();
        $this->emDebug("Reprocessing queue as retry $this->retries");
	}


    /**
     * Start processing of new batch
     * batch is named based on timestamp of now
     * @param $cron
     * @return false
     */
	public function cronInitiateBatch( $cron ) {
        // $this->emDebug("Cron Fired", $cron);

        $this->batch = strtotime("now");

        // Get the last batch
        $lastBatch = $this->getSystemSetting("current-batch");

        // Get the minimum hours between batches
        $hoursBetweenBatches = $this->getSystemSetting("hours-between-batches");
        $minBetweenBatches = $hoursBetweenBatches * 60;

        // How long has it been since the last batch
        $minSinceLastBatch = ( $this->batch - $lastBatch ) / 60;

        if ($minBetweenBatches > $minSinceLastBatch) {
            $this->emDebug("$minSinceLastBatch minutes since last batch is less than defined gap of $minBetweenBatches mins so we will skip.");
            return false;
        } else {
            $this->emDebug("Min since last batch: $minSinceLastBatch minutes is more than $minBetweenBatches so we will check to start");
        }

        // Check if last batch completed
        $lastQueue = $this->getSystemSetting("queue");
        if (!empty($lastQueue)) {
            $this->emDebug("The last batch did not complete -- the following jobs remain on the queue", $lastQueue);
            // Email support user
            global $project_contact_email;
            REDCap::email($this->getSystemSetting('alert-email'),$project_contact_email,"Error in " . $this->getModuleName(), "Batch $lastBatch did not finish.\n\nRemaining Alert Queue:\n<pre>".print_r($lastQueue,true)."</pre>");
        }

        // Start a new batch
        $this->setSystemSetting("current-batch", $this->batch);

        // Set current batch process id
        $this->setSystemSetting("current-batch-process-id", $this->processId);

        // Set retries to 0
        $this->setSystemSetting("batch-retries", 0);

        // Set count to 0...
        $this->setSystemSetting("batch-count", 0);

        // Get a list of project-alerts that need to be done
        $jobs = $this->getProjectsWithJobs();
        $queue = [];
        foreach($jobs as $alert) {
            $queue[] = array_merge($alert, [
                "batch" => $this->batch,
                "start_process" => $this->processId
            ]);
        }

        // Save the new queue
        $this->setSystemSetting("queue", $queue);
        $this->setSystemSetting("queue-total", count($queue));

        $this->emDebug("Set batch {$this->batch} queue with " . count($queue) . " alerts");

        // Start processing the queue
        if (empty($queue)) {
            $this->emDebug("Nothing in the queue");
        } else {
            $this->alertHistory = $this->getSystemSetting("alert-history");
            $this->queue = $queue;
            $this->processQueue();
        }
    }


    public function processQueue() {
	    // Get Queue of alerts to be processed (from cache)

        if (empty($this->queue)) {
            // There is nothing to be done...
            $this->emError("Queue is empty (shouldn't happen!!) - stop processing");
            return false;
        }

        // Get the next alert to process from the queue
        $alert = array_shift($this->queue);

        // Save alert to database in case of timeout/crash
        $this->setSystemSetting("current-alert", $alert);

        // Run the process... this could take a while...
        $this->emDebug("Processing alert " . $alert['alert_id'] . " from project " . $alert['project_id'] . "...");

        $results = $this->checkProjectAlert($alert['project_id'], $alert['alert_id']);
        $results['queue_time'] = $this->getRunTime();
        // $results['batch'] = $this->batch;
        $results['date_time'] = date("Y-m-d H:i:s", $this->batch);

        // Log results to EM
        $this->log("Alert Processed", $results);
        $this->setSystemSetting("current-alert", "");
        // $this->setSystemSetting("alert-" . $alert['alert_id'], $results);

        // Save alert Hx
        $this->alertHistory[$alert['alert_id']] = $results;
        $this->setSystemSetting('alert-history', $this->alertHistory);

        // Save the shortened queue
        $this->setSystemSetting("queue", $this->queue);

        if (empty($this->queue)) {
            // All processed
            $this->emDebug("Batch $this->batch complete");
            return true;
        } else {
            // Recursively process the next item in the queue
            return $this->processQueue();
        }
    }


    /**
     * Actually process the alert for notifications one at a time
     * @param $project_id
     * @param $alert_id
     * @return array|false
     */
	public function checkProjectAlert($project_id, $alert_id) {
        if (empty($alert_id) || empty($project_id)) {
            $this->emError("Project and Alert ID REQUIRED");
            return false;
        }

        $ts = microtime(true);

        $Alerts = empty($this->Alerts) ? new Alerts : $this->Alerts;
        // checkAlertsBulk($project_id=null, $datediffsOnly=false, $alert_ids=array())
        list($num_scheduled_total, $num_removed_total, $count_records_affected) = $Alerts->checkAlertsBulk($project_id, true, $alert_id);

        $duration_ms = (microtime(true) - $ts) * 1000;

        $results = [
            'num_scheduled' => $num_scheduled_total,
            'num_removed' => $num_removed_total,
            'count_affected' => $count_records_affected,
            'project_id' => $project_id,
            'alert_id' => $alert_id,
            'duration' => $duration_ms
        ];

        return $results;
    }


    /**
     * Get a list of all project/alerts with datediff to make the queue
     * @return array
     */
	public function getProjectsWithJobs($array_values = false) {
        $datediffsOnly = true;

        // Copied / Modified from Alerts->checkAlertsBulk
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

}
