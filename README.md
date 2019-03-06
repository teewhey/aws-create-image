# create_image.sh

Prerequisite
  - Install python python-dev python-pip 
  ``` apt-get install python python-dev python-pip```
  - Install AWSCLI
  ``` pip install awscli```
  - Configure your AWS access 
  ```aws configure --profile <aws_profile_name>```
  - Clone this repo to anywhere to your liking
  - Run the command
  ```bash create_image.sh <some_fixed_name> <aws_profile_name>```

Note
- The fixed name should be the same for every run, otherwise this script will not delete past snapshots.
