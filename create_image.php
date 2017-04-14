#!/usr/bin/env php

<?php

require 'vendor/autoload.php';

use Aws\Ec2\Ec2Client;
use Colors\Color;
$c = new Color();
$ec2Client = new Ec2Client([
    'region' => 'us-west-2',
    'version' => '2016-11-15',
    'profile' => 'default'
]);
$dryrun = false;

function getInstanceDetails() {
        global $argv, $argc, $c;
	$output = NULL;

	$instances = array(
	      //array("input name","instance id","instance name","retention days","region")
                array("tower","i-xxxxxxxxxxxxxx","ops.tower",1,"us-west-2"),
                array("test","i-xxxxxxxxxxxxxx","test",1,"us-west-2"),
	);

        if ($argc < 2) {
		echo $c("Please enter the instance that you want to image")->red . PHP_EOL;
		echo $c("---- Available Options ----")->green . PHP_EOL;
		for ($item = 0; $item < count($instances); $item++) {
			echo $instances[$item][0] . PHP_EOL;
		}
		echo $c("---------------------------")->green . PHP_EOL . PHP_EOL;
                $input = readline("YOUR OPTION > ");
        } else {
                $input = $argv[1];
        }

	for ($item = 0; $item < count($instances); $item++) {
		if ( strtolower($input) == strtolower($instances[$item][0]) ) {
			$output = array(
				$instances[$item][0],
				$instances[$item][1],
				$instances[$item][2],
				$instances[$item][3],
				$instances[$item][4],
			);
		}; 
	};

	if ( is_null($output[2]) ) { 
		echo $c("The option that you specify is invalid or unavailable")->red . PHP_EOL;	
		exit(1);
	} else {
		return $output;
	}
}

function getAmiIDs($instanceName) {
	
	global $ec2Client;

	$result = $ec2Client->describeImages([
		'Filters' => array(
			array("Name" => "tag:CreatedBy", "Values" => array('AutomatedImager')),
			array("Name" => "tag:InstanceName", "Values" => array($instanceName)),
		),
	]);

	return $result->search('Images[].ImageId');
}


function createImage($instanceName,$instanceID) {

	global $ec2Client, $dryrun;

	date_default_timezone_set("Asia/Kuala_Lumpur");
	$image_name = $instanceName . "." . date("Ymd-His");
	$result = $ec2Client->createImage([
		'InstanceId' => $instanceID,
		'Name' => $image_name,
		'Description' => "Image created for instance $instanceName with instance ID $instanceID",
		'NoReboot' => true,
		'DryRun' => $dryrun,
	]);

	$ami_id = $result->search('ImageId');
	$tagging = $ec2Client->createTags([
		'Resources' => array($ami_id),
		'Tags' => [
			[
				'Key' => 'CreatedBy',
				'Value' => 'AutomatedImager',
			],
			[
                                'Key' => 'InstanceName',
                                'Value' => $instanceName,
                        ],
		],
	]);

	return $ami_id;
}


function cleanupImages($amiList,$daystokeep) {

	global $ec2Client, $c, $dryrun;

	foreach ($amiList as $amiid) {
		$result = $ec2Client->describeImages([
			'ImageIds' => array($amiid),
		]);
		$amidate = strtotime(implode(" ",$result->search('Images[].CreationDate')));
		$amidescription = implode(" ",$result->search('Images[].Description'));
		$expiry = strtotime("+$daystokeep days",$amidate);
		$now = strtotime(date(DATE_RFC2822));
		
		if ( $now > $expiry ) {
			echo $c("DELETING")->red . " AMI " . $c($amiid)->yellow . ". Description: " . $c($amidescription)->magenta . PHP_EOL;
			$deregisterImage = $ec2Client->deregisterImage([
                        	'ImageId' => $amiid,
				'DryRun' => $dryrun,
                	]);
			$snapshot_list = $result->search('Images[].BlockDeviceMappings[*].Ebs.SnapshotId');

                        for ($item = 0; $item < count($snapshot_list[0]); $item++) {
                                echo $c("DELETING ")->red . "snapshot " . $c($snapshot_list[0][$item])->cyan . PHP_EOL;
                                $deleteSnapshot = $ec2Client->deleteSnapshot([
                                        'DryRun' => $dryrun,
                                        'SnapshotId' => $snapshot_list[0][$item],
                                ]);	
			}

		} else {
			echo $c("NOT DELETING")->green . " AMI " . $c($amiid)->yellow . ". Description: " . $c($amidescription)->magenta . PHP_EOL;
		}
	}
}

$instanceinfo = getInstanceDetails();

$instance_name = $instanceinfo[2];
$instance_id = $instanceinfo[1];
$instance_retention = $instanceinfo[3];

$ami_list = getAmiIDs($instance_name);
$ami_id = createImage($instance_name,$instance_id);

echo "New AMI ID for " . $c($instance_name)->cyan . " is " .  $c($ami_id)->yellow . PHP_EOL;
$cleanup = cleanupImages($ami_list,$instance_retention);
