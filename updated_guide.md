
# PagerDuty to Nagios Two Way Integration installation guide


Originally this script worked with the standard PagerDuty Nagios integration, however that integration is pretty much dead, the pdagent doesnt work on any modern Linux distributions and PagerDuty is not supporting it (publicly [deprecating](https://github.com/PagerDuty/pdagent?tab=readme-ov-file#notice) the product and refering us to [go-pdagent](https://github.com/PagerDuty/go-pdagent) which was never finished and has been silently abandoned by the company (no commit for 3 years)  

on pdagent and the perl script before it use a weird custom event format specifically made for the nagios integration and that creates problems with newer tech stack from PD like event orchestration

Martin Stone made an excellent pdagent alternaive in python and packaged it as a docker image and it JUST WORKS so I use that

I have create some sample Nagios configuration, a wrapper script (send_PD_alert.sh) and I have been able to acieve what I intended and improved way to send events to PagerDuty

I will attempt to document that here

DRAFT DOCUMENT, USE AT YOUR OWN RISK mostly just XI is written at this point in time and I have not tested it recently  and have not tested it since I re-wrote it


## Prerequisites

* A currently working nagios installation can be any of the three options
    * Nagios XI (Commercial)  
    * Nagios Core (opensource) 
    * Nagios CSP (Freemium) installation
* Ability to install docker on the linux host
    * Install docker per the documentation at https://docs.docker.com/engine/install/ 

## Configure the Integration to send events to PagerDuty

### Option 1: Setup a service in PagerDuty (Dont do this, do option2)
This option will require an individual contact for each service in PagerDuty it is easy to implement but gets messy with age

1. Create a PagerDuty Service or identify an existing service to use
2. Add “Events API V2” integration
    * Copy the integration key

### Option 2: Setup an event orchestration 
This option will allow you to have a single contact in Nagios used to send events to PagerDuty. This is the better option for easy implementation and growth over time as you can just have a 1:1 relationship like option 1 at the start then get more creative.

At the time of writing, routing to different services via event orchestration does not require any special licenses.

1. create an event orchestration via the AIOps menu
2. Copy the integration key from the integration screen

3. install PDAltAgent (assuming you have Docker installed)
    
    3.1. Install [PDAltAgent](https://github.com/martindstone/PDaltagent) with Docker

    ```bash
    wget  http://raw.githubusercontent.com/Nozlaf/PDaltagent/refs/heads/fix-docker-compose.yml/docker-compose.yml
    docker compose up -d
    usermod -aG docker nagios
    ```
4. Install Nagios to PagerDuty integration [ READ ALL THIS BEFORE MAKING ANY CHANGES]

    4.1. Grab the latest copy of my send_PD_alert script, copy it to the default folder for nagios install and set it as executable

    ```bash
    wget https://raw.githubusercontent.com/Nozlaf/PD2Nagiosv3/main/send_PD_alert.sh
    sudo mv send_PD_alert.sh /usr/local/nagios/libexec/send_PD_alert.sh
    sudo chmod 550 /usr/local/nagios/libexec/send_PD_alert.sh
    sudo chown nagios:nagios /usr/local/nagios/libexec/send_PD_alert.sh
    ```

    4.2. Updatr the nagios configuration so it can send service alerts and host alerts
   this will also create a user called PagerDuty which uses those commands, however the user will not be in any groups and will not be "oncall" for anything, so you will need to add that user to your groups

   ```bash
   wget https://raw.githubusercontent.com/Nozlaf/PD2Nagiosv3/refs/heads/main/PagerDuty.cfg
   mv PagerDuty.cfg /usr/local/nagios/etc
   echo Dont forget to edit the nagios.cfg file to call the new PagerDuty.cfg file
   ```

   now edit the /usr/local/nagios/etc/nagios.cfg file to call the new configuration file, add these lines in the file
   ```bash
   # Definitions for integration with PagerDuty
   cfg_file=/usr/local/nagios/etc/objects/PagerDuty.cfg
   ```

   lastly you need to make that PagerDuty user oncall for some issues, I just add the user to the admins contactgroup as my installation is simple

   ```bash
   define contactgroup {

       contactgroup_name       admins
       alias                   Nagios Administrators
       members                 nagiosadmin,pagerduty
   
   }
```
   

   now validate the configuration is working

   

    ***For Nagios XI & CSP you can follow the standard guide just substitute in the updated commands which I am providing***

    5. Follow steps 2-20 in [On Your Nagios XI Server](https://www.pagerduty.com/docs/guides/nagios-xi-integration-guide/). Do not install the Agent or the two-way integration files from there.
        1. Use the integration key that was copied when setting up the PagerDuty service
        2. Host command to use in the instructions above \
<code>$USER1$/send_PD_alert.sh  -k $CONTACTPAGER$ -o "$HOSTNAME$" -t "$HOSTSTATE$" -f HOSTNAME="$HOSTNAME$" -f HOSTSTATE="$HOSTSTATE$" -f HOSTDISPLAYNAME="$HOSTDISPLAYNAME$" -f HOSTPROBLEMID="$HOSTPROBLEMID$" -f HOSTEVENTID="$HOSTEVENTID" -i "$HOSTNAME$"</code>

        3. Service command to use in the instructions above \
<code>$USER1$/send_PD_alert.sh  -k $CONTACTPAGER$ -o "$HOSTNAME$" -s "$SERVICEDESC$" -t "$SERVICESTATE$" -f SERVICEDESC='"$SERVICEDESC$"' -f SERVICESTATE="$SERVICESTATE$" -f SERVICEOUTPUT='"$SERVICEOUTPUT$"' -f HOSTNAME="$HOSTNAME$" -f HOSTSTATE="$HOSTSTATE$" -f HOSTDISPLAYNAME="$HOSTDISPLAYNAME$" -f SERVICEEVENTID="$SERVICEEVENTID$" -f SERVICEOUTPUT='"$SERVICEOUTPUT$"' -i '"$HOSTNAME$_$SERVICEDESC$"'</code>

    ***For Nagios Core you can do this.... ***


TBD, most likely download pre-configured file from my github and copy it to your nagios core configuration folder

5. Install PagerDuty to Nagios integration
    1. <code>wget https://raw.githubusercontent.com/Nozlaf/PD2Nagiosv3/main/PD2Nagiosv3_config.php</code>
    2. <code>mv PD2Nagiosv3_config.php /var/www/html/PD2Nagiosv3_config.php</code>
    3. <code>wget https://raw.githubusercontent.com/Nozlaf/PD2Nagiosv3/main/PD2Nagiosv3_pagerduty.php</code>
    4. <code>mv PD2Nagiosv3_pagerduty.php /var/www/html/PD2Nagiosv3_pagerduty.php</code>
    5. Modify PD2Nagiosv3_config.php
        1. Add the read-only PagerDuty API key
        2. Add the PagerDuty webhook secret(s)
        3. Change the NRDP URL
        4. Change the NRDP secret
    6. Make sure nagiosbridge_debug.log file can be written to by the web server if debugging is turned on if you have restricted creaction of files you will need to create the log file manually like this
        1. <code>touch nagiosbridge_debug.log</code>
        2. <code>chmod 666 nagiosbridge_debug.log</code> < Really bad, do better
6. The integration should now be working!


## Test the Integration



1. Test a host and service.
2. Nagios
    1. Alerts should go to PagerDuty and create an Incident when an alert occurs in Nagios.
    2. The PD Incident should be resolved when the service or host goes back into an Ok state.
