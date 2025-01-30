#!/usr/bin/env bash
set -o errexit
set -o pipefail
set -o nounset

nagiosName="Nagios - Set name in send_PD_alert.sh"
extinfoUrl="http://nagios.local/nagios/cgi-bin/extinfo.cgi"


# FOR USE WITH NAGIOS XI OR NAGIOS CORE
# Tested with the nagiosXI vmware image from nagios.com not tested on anything else at this stage
# tested with pdaltagent
# No warranty is provided. This script has been tested with the NagiosXI VMware image from nagios.com.

#SERVICE COMMAND DEFINITION
#USER1$/send_PD_alert.sh  -k $CONTACTPAGER$ -o "$HOSTNAME$" -s "$SERVICEDESC$" -t "$SERVICESTATE$" -f SERVICEDESC='"$SERVICEDESC$"' -f SERVICESTATE="$SERVICESTATE$" -f SERVICEOUTPUT='"$SERVICEOUTPUT$"' -f HOSTNAME="$HOSTNAME$" -f HOSTSTATE="$HOSTSTATE$" -f HOSTDISPLAYNAME="$HOSTDISPLAYNAME$" -f SERVICEEVENTID="$SERVICEEVENTID$" -f SERVICEOUTPUT='"$SERVICEOUTPUT$"' -i '"$HOSTNAME$_$SERVICEDESC$"'

#HOST COMMAND DEFINITION
#$USER1$/send_PD_alert.sh  -k $CONTACTPAGER$ -o "$HOSTNAME$" -t "$HOSTSTATE$" -f HOSTNAME="$HOSTNAME$" -f HOSTSTATE="$HOSTSTATE$" -f HOSTDISPLAYNAME="$HOSTDISPLAYNAME$" -f HOSTPROBLEMID="$HOSTPROBLEMID$" -f HOSTEVENTID="$HOSTEVENTID" -i "$HOSTNAME$"


#debug mode
#lets log all the arguments to /tmp/sendpdevent.log

severity="critical"
d_arg=""
f_arg=""
servicename=""
while [[ "$#" -gt 0 ]]; do
  case $1 in
    -k)
      k_arg="$2"
      shift
      ;;
    -d)
      d_arg="$2"
      shift
      ;;
    -c)
      c_arg="$2"
      shift
      ;;
    -f)
      if [ -z "$f_arg" ]; then
        f_arg="-f $2"
      else
        f_arg="$f_arg -f $2"
      fi
      shift
      ;;
    -t)
      case $2 in
        ACKNOWLEDGEMENT)
          t_arg="-t acknowledge"
          severity="critical"
          ;;
        DOWN|PROBLEM|CRITICAL|CUSTOM)
          t_arg="-t trigger"
          severity="critical"
          ;;
        UP|RECOVERY|OK)
          t_arg="-t resolve"
          ;;
        WARNING)
          t_arg="-t trigger"
          severity="warning"
          ;;
        *)
          t_arg="-t $2"
          ;;
      esac
      shift
      ;;
    -i)
      i_arg="-i $2"
      shift
      ;;
    -s)
      servicename="$2"
      shift
      ;;
    -o)
      hostname="$2"
      shift
      ;;

    -h|--help)
      echo "Usage: $0 [-k <arg>] [-d <arg>] [-c <arg>] [-f <arg>]... [-t <arg>] [-n <arg>] [-s <arg>] [-h|--help]" >&2
      echo "  -k                Routing Key (Contact pager)"
      echo "  -d                Description (Summary)"
      echo "  -c                Client"
      echo "  -f                Custom field"
      echo "  -t                Notification type (UP, DOWN, PROBLEM, RECOVERY, WARNING, CRITICAL, etc.)"
      echo "  -i                Nagios Incident Key"
      echo "  -s                Service name"
      echo "  -o                Host name"
      exit 1
      ;;
    *)
      echo "Usage: $0 [-k <arg>] [-d <arg>] [-c <arg>] [-f <arg>]... [-t <arg>] [-n <arg>] [-s <arg>]" >&2
      echo "Unknown parameter passed: $1" >&2
      exit 1
      ;;
  esac
  shift
done

if [ -z "$servicename" ]
then
  description="${severity} issue with ${hostname} ${d_arg} "
  url="${extinfoUrl}?type=1&host=${hostname}"
else
  description="${severity} issue with ${servicename} on ${hostname} ${d_arg}"
  url="${extinfoUrl}?type=2&host=${hostname}&service=${servicename}"

fi
#for debugging uncomment this
#echo "docker exec pdaltagent_pdagentd pd-send -c \"$nagiosName\" -u \"$url\" -k $k_arg $t_arg ${severity:+-s \"$severity\"} ${description:+-d \'\"$description\"\'} ${client:+-c \"$client\"} ${custom_field:+-f \"$custom_field\"} ${notify_host:+-n host} $f_arg $i_arg" >> /tmp/sendpdevent.log
#


bash -c "docker exec pdaltagent_pdagentd pd-send -c \"$nagiosName\" -u \"$url\" -k $k_arg $t_arg ${severity:+-s \"$severity\"} ${description:+-d \'\"$description\"\'} ${client:+-c \"$client\"} ${custom_field:+-f \"$custom_field\"} ${notify_host:+-n host} $f_arg $i_arg"
