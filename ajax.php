<?php
namespace Stanford\AlertDateDiffCron;
/** @var AlertDateDiffCron $module */

$user = $module->getUser();
if (!$user->isSuperUser()) {
    $result = [ "error" => "This page is only available to super users" ];
} else {
    if (!empty($_POST)) {
        // Handle post ajax function
        $action = $_POST['action'];

        switch ($action) {
            case "getTodo":
                break;

            case "checkProjectAlert":
                $project_id = filter_var($_POST['project_id'],FILTER_SANITIZE_NUMBER_INT);
                $alert_id = filter_var($_POST['alert_id'], FILTER_SANITIZE_NUMBER_INT);
                if (empty($project_id) || empty($alert_id)) {
                    $result = [ "error" => "Missing required input" ];
                } else {
                    $module->alertHistory = $module->getSystemSetting("alert-history");
                    $module->queue = [
                        [ 'project_id' => $project_id, 'alert_id' => $alert_id ]
                    ];
                    $module->batch = strtotime("now");
                    $module->processQueue();
                    // $result = $module->checkProjectAlert($project_id, $alert_id);
                }
                break;

            case "getProjectAlerts":
                $q = $module->getProjectsWithJobs(true);
                // $module->emDebug($q);
                $result = [ "data" => $q];
                break;

            case "default":
                $module->emDebug($_POST['action'] . " not handled");
                $result = "$action Not handled";
        }
    }
}


header("Content-type: application/json");
echo json_encode($result);

