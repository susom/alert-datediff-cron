<?php
namespace Stanford\AlertDateDiffCron;
/** @var AlertDateDiffCron $module */

$user = $module->getUser();
if (!$user->isSuperUser()) {
    echo "This page is only available to super users";
    exit();
}

if (!empty($_POST)) {
    // Handle post ajax function
    switch($_POST['action']) {
        case "getTodo":
            break;

        case "getProjectAlerts":
            $alerts = $module->getAlertProjectArray(true);
            header("Content-type: application/json");
            echo json_encode($alerts);
            break;

        case "default":
            $module->emDebug($_POST['action'] . " not handled");
    }

    exit();
}

?>
    <h3><?php echo $module->getModuleName() ?></h3>
<?php


// Get status of cron job
$sql = "select * from redcap_crons where cron_name = 'AlertsNotificationsDatediffChecker'";
$q = $module->query($sql, []);
$row = db_fetch_assoc($q);
$result = $row['cron_enabled'];
if ($result == "ENABLED") {
    ?>
        <div class="alert alert-warning text-center">Currently, the default cron job for AlertsNotificationsDatediffChecker is still enabled.  If you
        using this EM you should disable this cron.  This can be done by executing the following SQL statement:<br><code>
                update redcap_crons set cron_enabled = 'DISABLED' where cron_name = 'AlertsNotificationsDatediffChecker';
            </code></div>
    <?php
} else {
    ?>
        <div class="alert alert-success text-center">The default AlertsNotificationsDatediffChecker cron job is currently disabled.
        <br>This is probably good as you are using this EM to replace its functionality.</div>
    <?php
}

?>

    <div class="border p-2">
        <div id="projectAlertsWrapper" class="pt-1">
            <table id="projectAlerts" class="hidden">
                <thead>
                    <tr>
                        <th>PID</th>
                        <th>Alert</th>
                        <th>Title</th>
<!--                        <th>Queue</th>-->
<!--                        <th>Batch</th>-->
<!--                        <th>DateTime</th>-->
<!--                        <th>Scheduled</th>-->
<!--                        <th>Duration</th>-->
                        <th></th>
                    </tr>
                </thead>
            </table>
        </div>
        <button class='btn btn-primary' data-action="getProjectAlerts">Get/Refresh DateDiff Alerts</button>
    </div>

    <div id='spinner-modal' class="modal fade bd-example-modal-lg" data-backdrop="static" data-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content" style="width: 48px">
                <span class="fa fa-spinner fa-spin fa-3x"></span>
            </div>
        </div>
    </div>
<?php






?>
<style>
    .bd-example-modal-lg .modal-dialog{
        display: table;
        position: relative;
        margin: 0 auto;
        top: calc(50% - 24px);
    }

    .bd-example-modal-lg .modal-dialog .modal-content{
        background-color: transparent;
        border: none;
    }
</style>
<script>

    function showModal() {
        $('#spinner-modal').modal('show');
    }

    function hideModal() {
        setTimeout(function() {
            $('#spinner-modal').modal('hide');
            $('body').removeClass('modal-open');
            $('.modal-backdrop').remove();
        }, 100);
    }

    var ajaxUrl = "<?php echo $module->getUrl('ajax.php') ?>";
    var table;

    $('#pagecontent').on('click', 'button', function() {
        console.log(this, $(this).data('action'));

        let action = $(this).data('action');

        if (action == "getProjectAlerts") {
            table = $('#projectAlerts').DataTable( {
                "ajax": {
                    "url": ajaxUrl,
                    "data": { "action": action },
                    "type": "POST"
                },
                "columnDefs": [ {
                    "targets": -1,
                    "data": null,
                    "defaultContent": "<button data-action='checkProjectAlert' class='btn btn-xs btn-primary'>Run</button>"
                } ]
            });

            $('#projectAlerts').show();

            // showModal();
            // $.post(ajaxUrl, {
            //     "action": action
            // }).done( function( data ) {
            //     let thead = $('<thead><tr><th>Project</th><th>Alert</th><th>Title</th><th>Action</th></tr></thead>');
            //     let tbody = $('<tbody></tbody>');
            //     data.forEach( payload => {
            //         let btn = "<button class='btn btn-xs btn-primary' data-action='checkProjectAlert' data-project_id='" +
            //             payload.project_id + "' data-alert_id='" + payload.alert_id + "'>Process " +
            //             "<span class='spinner-border spinner-border-sm hidden' role='status' aria-hidden='true'></span></button>";
            //         $('<tr><td>' + payload.project_id + '</td><td>' + payload.alert_id + '</td><td>' + payload.title + '</td><td>' + btn + '</td></tr>').appendTo(tbody);
            //         console.log(payload)
            //     });
            //     let table = $('<table id="projectAlertsTable"></table>').append(thead).append(tbody);
            //     $('#projectAlertsWrapper').empty().append(table);
            //     // console.log(data);
            //     $('#projectAlertsTable').DataTable();
            // }).always(function() {
            //     hideModal();
            // });
        }

        if (action == "checkProjectAlert") {
            //let data = $('#dataprojectAlerts').dataTable(
            let data = table.row( $(this).parents('tr') ).data();
            let project_id = data[0]; //$(this).data('project_id');
            let alert_id = data[1]; //$(this).data('alert_id');
            showModal();

            $.post(ajaxUrl, {
                "action": action,
                "project_id": project_id,
                "alert_id": alert_id
            }).done( function( data ) {
                console.log(data);
                alert(JSON.stringify(data, null, 2))
            }).always(function() {
                hideModal();
            });

        }


    });

</script>
