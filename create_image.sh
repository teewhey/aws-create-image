#!/bin/bash

# Created by David Chin

# This script makes an image of the instances specified below with the NO REBOOT option

if [ -z "$1" -o -z "$2" ]
 then
  echo "Please specify a name and a awscli profile"
  exit 1
fi

export PATH=$PATH:/usr/local/bin/:/usr/bin

instance_id=$(curl -s http://169.254.169.254/latest/meta-data/instance-id/)
region=$(curl -s http://169.254.169.254/latest/meta-data/placement/availability-zone | rev | cut -c2- | rev)
retention_days=7
instance_name=$1
AWSCLI_PROFILE=$2

set -ue
set -o pipefail

retention_date_in_seconds=$(date +%s --date "$retention_days days ago")

logfile="/var/log/ami-imager.log"
logfile_max_lines="5000"

log_setup() {
    # Check if logfile exists and is writable.
    ( [ -e "$logfile" ] || touch "$logfile" ) && [ ! -w "$logfile" ] && echo "ERROR: Cannot write to $logfile. Check permissions or sudo access." && exit 1

    tmplog=$(tail -n $logfile_max_lines $logfile 2>/dev/null) && echo "${tmplog}" > $logfile
    exec > >(tee -a $logfile)
    exec 2>&1
}

log() {
    echo "[$(date +"%Y-%m-%d"+"%T")]: $*"
}

prerequisite_check() {
	for prerequisite in aws; do
		hash $prerequisite &> /dev/null
		if [[ $? == 1 ]]; then
			echo "In order to use this script, the executable \"$prerequisite\" must be installed." 1>&2; exit 70
		fi
	done
}

create_image() {
	image_name="$instance_name.$(date +%Y%m%d-%H%M%S --date "8 hours")"
	ami_id=$(aws --profile ${AWSCLI_PROFILE} ec2 create-image --no-reboot --instance-id $instance_id --name $image_name --description "Image created for instance $instance_name with instance ID $instance_id")
	log "New AMI ID $instance_name is $ami_id"
	aws --profile ${AWSCLI_PROFILE} ec2 create-tags --region $region --resource $ami_id --tags Key=CreatedBy,Value=AutomatedImager Key=InstanceName,Value=$instance_name
}

cleanup_images() {
	for ami_id in $ami_list; do
		ami_date=$(aws --profile ${AWSCLI_PROFILE} ec2 describe-images --region $region --output=text --image-ids $ami_id --query Images[].CreationDate | awk -F "T" '{printf "%s\n", $1}')
		ami_date_in_seconds=$(date "--date=$ami_date" +%s)
		ami_description=$(aws --profile ${AWSCLI_PROFILE} ec2 describe-images --region $region --image-ids $ami_id --query Images[].Description)

		if (( $ami_date_in_seconds <= $retention_date_in_seconds )); then
			log "DELETING AMI $ami_id. Description: $ami_description ..."
			snapshot_list=$(aws --profile ${AWSCLI_PROFILE} ec2 describe-images --image-id $ami_id --output=text --query Images[].BlockDeviceMappings[*].Ebs.SnapshotId)
			aws --profile ${AWSCLI_PROFILE} ec2 deregister-image --region $region --image-id $ami_id
			sleep 10

			log "DELETING snapshots related to AMI $ami_id"
			for snapshot_id in $snapshot_list; do
				log "DELETING snapshot $snapshot_id"
				aws --profile ${AWSCLI_PROFILE} ec2 delete-snapshot --region $region --snapshot-id $snapshot_id
			done
		else
			log "Not deleting AMI $ami_id. Description: $ami_description ..."
		fi
	done
}


#RUN
log_setup
prerequisite_check

ami_list=$(aws --profile ${AWSCLI_PROFILE} ec2 describe-images --filters Name=tag-key,Values=CreatedBy Name=tag-value,Values=AutomatedImager --filters Name=tag-key,Values=InstanceName Name=tag-value,Values=$instance_name --query 'Images[*].{ID:ImageId}' --output text)

create_image
cleanup_images
